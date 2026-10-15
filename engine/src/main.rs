mod syntax;
use mago_allocator::LocalArena;
use mago_database::file::{File, FileId};
use mago_names::resolver::NameResolver;
use mago_php_version::PHPVersion;
use mago_reporting::Level;
use mago_semantics::SemanticsChecker;
use mago_span::{HasSpan, Span};
use mago_syntax::{
    cst::{ClassLikeMemberSelector, Expression, Node},
    parser::parse_file_content,
};
use serde_json::{Value, json};
use std::borrow::Cow;
use std::io::{self, BufRead, Write};

fn fact(kind: &str, span: Span, source: &[u8], dynamic: bool, owner: Option<u32>) -> Value {
    json!({"kind":kind,"start":span.start.offset,"end":span.end.offset,
        "name":if dynamic { "@dynamic".to_string() } else { String::from_utf8_lossy(&source[span.start.offset as usize..span.end.offset as usize]).into_owned() },
        "owner":owner})
}

fn expr_fact(kind: &str, expr: &Expression, source: &[u8], owner: Option<u32>) -> Value {
    let expr = unwrapped(expr);
    fact(
        kind,
        expr.span(),
        source,
        !matches!(
            expr,
            Expression::Identifier(_)
                | Expression::Self_(_)
                | Expression::Static(_)
                | Expression::Parent(_)
        ),
        owner,
    )
}

fn unwrapped<'a, 'b>(mut expr: &'a Expression<'b>) -> &'a Expression<'b> {
    while let Expression::Parenthesized(n) = expr {
        expr = n.expression;
    }
    expr
}

fn selector_fact(
    kind: &str,
    selector: &ClassLikeMemberSelector,
    source: &[u8],
    owner: Option<u32>,
) -> Value {
    let span = if let ClassLikeMemberSelector::Expression(n) = selector {
        unwrapped(n.expression).span()
    } else {
        selector.span()
    };
    fact(
        kind,
        span,
        source,
        !matches!(selector, ClassLikeMemberSelector::Identifier(_)),
        owner,
    )
}

fn walk(node: Node, source: &[u8], owner: Option<u32>, out: &mut Vec<Value>) {
    let mut child_owner = owner;
    let declaration = match node {
        Node::Class(n) => Some(("class", n.name.span(), n.class.span())),
        Node::Interface(n) => Some(("interface", n.name.span(), n.interface.span())),
        Node::Trait(n) => Some(("trait", n.name.span(), n.r#trait.span())),
        Node::Enum(n) => Some(("enum", n.name.span(), n.r#enum.span())),
        Node::AnonymousClass(n) => Some(("anonymous", n.class.span(), n.class.span())),
        _ => None,
    };
    if let Some((kind, span, keyword)) = declaration {
        child_owner = Some(keyword.start.offset);
        let mut f = fact(kind, span, source, false, child_owner);
        if kind == "anonymous" {
            f["name"] = json!("@anonymous");
        }
        out.push(f);
    }
    let f = match node {
        Node::Method(n) => Some(fact("method", n.name.span(), source, false, owner)),
        Node::FunctionCall(n) => Some(expr_fact("function-call", n.function, source, owner)),
        Node::MethodCall(n) => Some(selector_fact("method-call", &n.method, source, owner)),
        Node::NullSafeMethodCall(n) => {
            Some(selector_fact("nullsafe-call", &n.method, source, owner))
        }
        Node::StaticMethodCall(n) => Some(selector_fact("static-call", &n.method, source, owner)),
        Node::FunctionPartialApplication(n) => {
            Some(expr_fact("function-callable", n.function, source, owner))
        }
        Node::MethodPartialApplication(n) => {
            Some(selector_fact("method-callable", &n.method, source, owner))
        }
        Node::StaticMethodPartialApplication(n) => {
            Some(selector_fact("static-callable", &n.method, source, owner))
        }
        Node::Instantiation(n) => Some(expr_fact("new", n.class, source, owner)),
        _ => None,
    };
    if let Some(mut f) = f {
        let receiver = match node {
            Node::MethodCall(n) => Some(n.object),
            Node::NullSafeMethodCall(n) => Some(n.object),
            Node::StaticMethodCall(n) => Some(n.class),
            Node::MethodPartialApplication(n) => Some(n.object),
            Node::StaticMethodPartialApplication(n) => Some(n.class),
            _ => None,
        };
        if let Some(receiver) = receiver {
            let span = unwrapped(receiver).span();
            f["receiver"] = json!([span.start.offset, span.end.offset]);
        }
        out.push(f);
    }
    node.visit_children(|child| {
        // Constructor arguments run in the enclosing scope, unlike class members.
        let owner = if matches!(node, Node::AnonymousClass(_))
            && matches!(child, Node::PartialArgumentList(_))
        {
            owner
        } else {
            child_owner
        };
        walk(child, source, owner, out);
    });
}

const VERSION: &str = env!("CARGO_PKG_VERSION");
const MAGO_VERSION: &str = "1.57.0";
const SCHEMA: u64 = 1;

fn analyze(request: &Value) -> Result<Value, Box<dyn std::error::Error>> {
    if request["schema"].as_u64() != Some(SCHEMA) {
        return Err("Unsupported protocol schema".into());
    }
    let path = request["path"].as_str().ok_or("Missing path")?;
    let source = request["source"]
        .as_str()
        .ok_or("Missing source")?
        .as_bytes();
    let source_hash = request["source_hash"]
        .as_str()
        .ok_or("Missing source hash")?;
    let php_version = request["php_version"]
        .as_str()
        .ok_or("Missing target PHP version")?;
    let arena = LocalArena::new();
    let program = parse_file_content(&arena, FileId::new(path.as_bytes()), source);
    let target_version = php_version.parse::<PHPVersion>()?;
    let mut diagnostics: Vec<Value> = program
        .errors
        .iter()
        .map(|error| {
            let span = error.span();
            json!({"category":"syntax_error", "message":error.to_string(),
            "start":span.start.offset, "end":span.end.offset})
        })
        .collect();
    let mut facts = Vec::new();
    let mut syntax = json!({"nodes":[],"roots":[]});
    if diagnostics.is_empty() {
        let names = NameResolver::new(&arena).resolve(program);
        let file = File::ephemeral(
            Cow::Owned(path.as_bytes().to_vec()),
            Cow::Owned(source.to_vec()),
        );
        for issue in SemanticsChecker::new(target_version).check(&file, program, &names) {
            // Mago 1.57 includes runtime checks and rejects final trait aliases
            // even on PHP >=8.3. Neither is a syntax failure on those targets.
            let runtime_this = matches!(issue.message.as_ref(),
                "Cannot use `$this` in a static closure." |
                "Cannot use `$this` in a static arrow function.");
            let valid_final_alias = target_version >= PHPVersion::new(8, 3, 0)
                && issue.message == "The `final` modifier is not allowed in a trait `as` adaptation.";
            if issue.level == Level::Error && !runtime_this && !valid_final_alias {
                let span = issue.primary_span();
                diagnostics.push(json!({"category":"syntax_error", "message":issue.message,
                    "start":span.map(|s| s.start.offset), "end":span.map(|s| s.end.offset)}));
            }
        }
        if diagnostics.is_empty() {
            match syntax::document(program, &names) {
                Ok(tree) => {
                    syntax = tree;
                    walk(Node::Program(program), source, None, &mut facts);
                }
                Err(error) => diagnostics.push(
                    json!({"category":"unsupported_syntax", "message":error.message,
                    "start":error.span.start.offset, "end":error.span.end.offset}),
                ),
            }
        }
    }
    Ok(
        json!({"schema":SCHEMA, "path":path, "source_hash":source_hash,
        "php_version":php_version, "facts":facts, "diagnostics":diagnostics, "syntax":syntax}),
    )
}

fn run() -> Result<(), Box<dyn std::error::Error>> {
    let mut output = io::BufWriter::new(io::stdout().lock());
    if std::env::args().nth(1).as_deref() == Some("--version") {
        serde_json::to_writer(
            &mut output,
            &json!({"engine":"mago", "version":VERSION,
            "mago_version":MAGO_VERSION, "schema":SCHEMA, "capabilities":["syntax-facts", "syntax-tree-v1", "syntax-flat-v1"]}),
        )?;
        output.write_all(b"\n")?;
        return Ok(());
    }
    for line in io::stdin().lock().lines() {
        let line = line?;
        if line.len() > 32 * 1024 * 1024 {
            return Err("Source request exceeds 32 MiB".into());
        }
        let request: Value = serde_json::from_str(&line)?;
        serde_json::to_writer(&mut output, &analyze(&request)?)?;
        output.write_all(b"\n")?;
        output.flush()?;
    }
    Ok(())
}

fn main() -> Result<(), Box<dyn std::error::Error>> {
    // One bounded stack per persistent process. Deep, valid fluent chains must
    // not exhaust the operating system's small main-thread stack.
    let worker = std::thread::Builder::new()
        .name("analysis".into())
        .stack_size(64 * 1024 * 1024)
        .spawn(|| run().map_err(|error| error.to_string()))?;
    worker
        .join()
        .map_err(|_| "Analysis worker failed without a completed response")??;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn request(source: &str) -> Value {
        json!({"schema":SCHEMA,"path":"src/Example.php", "source":source,
            "source_hash":"test-snapshot", "php_version":"8.3"})
    }

    #[test]
    fn reports_owner_and_does_not_execute_input() {
        let result = analyze(&request(
            "<?php class Example { function run() { exit(1); helper(); } }",
        ))
        .unwrap();
        assert_eq!(result["diagnostics"], json!([]));
        assert_eq!(result["facts"][0]["name"], "Example");
        assert_eq!(result["facts"][2]["name"], "helper");
        assert_eq!(result["facts"][2]["owner"], 6);
    }

    #[test]
    fn invalid_file_does_not_expose_recovery_facts() {
        let result = analyze(&request("<?php class Example { function run(")).unwrap();
        assert!(!result["diagnostics"].as_array().unwrap().is_empty());
        assert_eq!(result["facts"], json!([]));
    }

    #[test]
    fn unknown_protocol_is_an_error() {
        let mut input = request("<?php helper();");
        input["schema"] = json!(2);
        assert!(analyze(&input).is_err());
    }

    #[test]
    fn target_php_version_is_checked_without_running_php() {
        let mut input = request("<?php readonly class Example {} ");
        input["php_version"] = json!("8.1");
        let unsupported = analyze(&input).unwrap();
        assert!(!unsupported["diagnostics"].as_array().unwrap().is_empty());
        assert_eq!(unsupported["facts"], json!([]));
        input["php_version"] = json!("8.3");
        assert_eq!(analyze(&input).unwrap()["diagnostics"], json!([]));
    }
}
