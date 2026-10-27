mod basic;
mod basic_calls;
mod basic_container;
mod basic_digest;
mod basic_impact;
mod basic_product;
mod basic_structure;
mod basic_tests;
mod measurement;
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
use std::io::{self, BufRead, Read, Write};

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
    let basic = request["product"] == basic_product::PRODUCT;
    if request.get("product").is_some() && !basic && request["product"] != "syntax-tree-v1" {
        return Err("Unsupported analysis product".into());
    }
    let mut measurement = measurement::Recorder::new(request);
    if basic {
        if let Some(mut result) = basic_product::limited(request)? {
            measurement.attach(&mut result);
            return Ok(result);
        }
    }
    measurement.point("parse", "begin");
    let arena = LocalArena::new();
    let program = parse_file_content(&arena, FileId::new(path.as_bytes()), source);
    measurement.point("parse", "end");
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
    let mut ir_built = false;
    if diagnostics.is_empty() {
        measurement.point("names", "begin");
        let names = NameResolver::new(&arena).resolve(program);
        measurement.point("names", "end");
        measurement.point("semantics", "begin");
        let file = File::ephemeral(
            Cow::Owned(path.as_bytes().to_vec()),
            Cow::Owned(source.to_vec()),
        );
        for issue in SemanticsChecker::new(target_version).check(&file, program, &names) {
            // Mago 1.57 includes runtime checks and rejects final trait aliases
            // even on PHP >=8.3. Neither is a syntax failure on those targets.
            let runtime_this = matches!(
                issue.message.as_ref(),
                "Cannot use `$this` in a static closure."
                    | "Cannot use `$this` in a static arrow function."
            );
            let valid_final_alias = target_version >= PHPVersion::new(8, 3, 0)
                && issue.message
                    == "The `final` modifier is not allowed in a trait `as` adaptation.";
            if issue.level == Level::Error && !runtime_this && !valid_final_alias {
                let span = issue.primary_span();
                diagnostics.push(json!({"category":"syntax_error", "message":issue.message,
                    "start":span.map(|s| s.start.offset), "end":span.map(|s| s.end.offset)}));
            }
        }
        measurement.point("semantics", "end");
        if diagnostics.is_empty() {
            measurement.point("ir", "begin");
            match syntax::document(program, &names) {
                Ok(tree) => {
                    syntax = tree;
                    ir_built = true;
                    if !basic {
                        walk(Node::Program(program), source, None, &mut facts);
                    }
                }
                Err(error) => diagnostics.push(
                    json!({"category":"unsupported_syntax", "message":error.message,
                    "start":error.span.start.offset, "end":error.span.end.offset}),
                ),
            }
            measurement.point("ir", "end");
        }
    }
    if basic {
        measurement.point("facts", "begin");
        let mut result = basic_product::response(request, syntax, diagnostics, ir_built, None)?;
        measurement.point("facts", "end");
        measurement.attach(&mut result);
        return Ok(result);
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
            "mago_version":MAGO_VERSION, "schema":SCHEMA, "capabilities":["syntax-facts", "syntax-tree-v1", "syntax-flat-v1",basic_product::PRODUCT]}),
        )?;
        output.write_all(b"\n")?;
        return Ok(());
    }
    let mut input = io::stdin().lock();
    loop {
        let mut line = String::new();
        let bytes = input
            .by_ref()
            .take(32 * 1024 * 1024 + 1)
            .read_line(&mut line)?;
        if bytes == 0 {
            break;
        }
        if bytes > 32 * 1024 * 1024 {
            return Err("Source request exceeds 32 MiB".into());
        }
        let request: Value = serde_json::from_str(&line)?;
        let mut bounded = BoundedOutput {
            output: &mut output,
            bytes: 0,
        };
        if request["product"] == "basic-file-facts-batch-v1" {
            let requests = request["requests"]
                .as_array()
                .ok_or("Missing Basic batch requests")?;
            if line.len() > 4 * 1024 * 1024
                || requests.is_empty()
                || requests.len() > 32
                || request["schema"] != SCHEMA
            {
                return Err("Basic batch exceeds its count, byte or schema bounds".into());
            }
            bounded.write_all(
                b"{\"schema\":1,\"product\":\"basic-file-facts-batch-v1\",\"results\":[",
            )?;
            for (index, request) in requests.iter().enumerate() {
                if request["product"] != basic_product::PRODUCT {
                    return Err("A Basic batch cannot contain another product".into());
                }
                if index > 0 {
                    bounded.write_all(b",")?;
                }
                let result = analyze(request)?;
                serde_json::to_writer(&mut bounded, &result)?;
            }
            bounded.write_all(b"]}\n")?;
        } else {
            serde_json::to_writer(&mut bounded, &analyze(&request)?)?;
            bounded.write_all(b"\n")?;
        }
        output.flush()?;
    }
    Ok(())
}

struct BoundedOutput<'a, W: Write> {
    output: &'a mut W,
    bytes: usize,
}
impl<W: Write> Write for BoundedOutput<'_, W> {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        if bytes.len() > 64 * 1024 * 1024 - self.bytes {
            return Err(io::Error::other(
                "Analysis response exceeds 64 MiB. Analysis is incomplete.",
            ));
        }
        let count = self.output.write(bytes)?;
        self.bytes += count;
        Ok(count)
    }
    fn flush(&mut self) -> io::Result<()> {
        self.output.flush()
    }
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
    fn response_byte_budget_accepts_the_last_byte_and_rejects_the_next_without_writing_it() {
        let mut output = Vec::new();
        let mut writer = BoundedOutput {
            output: &mut output,
            bytes: 64 * 1024 * 1024 - 1,
        };
        assert_eq!(writer.write(b"x").unwrap(), 1);
        assert!(writer.write(b"y").is_err());
        assert_eq!(output, b"x");
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
