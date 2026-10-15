use std::io::{self, BufRead, Write};
use mago_allocator::LocalArena;
use mago_database::file::FileId;
use mago_span::{HasSpan, Span};
use mago_syntax::{cst::{Node, Expression, ClassLikeMemberSelector}, parser::parse_file_content};
use serde_json::{Value, json};

fn fact(kind: &str, span: Span, source: &[u8], dynamic: bool, owner: Option<u32>) -> Value {
    json!({"kind":kind,"start":span.start.offset,"end":span.end.offset,
        "name":if dynamic { "@dynamic".to_string() } else { String::from_utf8_lossy(&source[span.start.offset as usize..span.end.offset as usize]).into_owned() },
        "owner":owner})
}

fn expr_fact(kind: &str, expr: &Expression, source: &[u8], owner: Option<u32>) -> Value {
    let expr = unwrapped(expr);
    fact(kind, expr.span(), source, !matches!(expr, Expression::Identifier(_) | Expression::Self_(_) | Expression::Static(_) | Expression::Parent(_)), owner)
}

fn unwrapped<'a, 'b>(mut expr: &'a Expression<'b>) -> &'a Expression<'b> {
    while let Expression::Parenthesized(n) = expr { expr = n.expression; }
    expr
}

fn selector_fact(kind: &str, selector: &ClassLikeMemberSelector, source: &[u8], owner: Option<u32>) -> Value {
    let span = if let ClassLikeMemberSelector::Expression(n) = selector { unwrapped(n.expression).span() } else { selector.span() };
    fact(kind, span, source, !matches!(selector, ClassLikeMemberSelector::Identifier(_)), owner)
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
        if kind == "anonymous" { f["name"] = json!("@anonymous"); }
        out.push(f);
    }
    let f = match node {
        Node::Method(n) => Some(fact("method", n.name.span(), source, false, owner)),
        Node::FunctionCall(n) => Some(expr_fact("function-call", n.function, source, owner)),
        Node::MethodCall(n) => Some(selector_fact("method-call", &n.method, source, owner)),
        Node::NullSafeMethodCall(n) => Some(selector_fact("nullsafe-call", &n.method, source, owner)),
        Node::StaticMethodCall(n) => Some(selector_fact("static-call", &n.method, source, owner)),
        Node::FunctionPartialApplication(n) => Some(expr_fact("function-callable", n.function, source, owner)),
        Node::MethodPartialApplication(n) => Some(selector_fact("method-callable", &n.method, source, owner)),
        Node::StaticMethodPartialApplication(n) => Some(selector_fact("static-callable", &n.method, source, owner)),
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
        let owner = if matches!(node, Node::AnonymousClass(_)) && matches!(child, Node::PartialArgumentList(_)) {
            owner
        } else {
            child_owner
        };
        walk(child, source, owner, out);
    });
}

fn main() -> Result<(), Box<dyn std::error::Error>> {
    let stdin = io::stdin();
    let mut output = io::BufWriter::new(io::stdout().lock());
    for line in stdin.lock().lines() {
        let path: String = serde_json::from_str(&line?)?;
        let source = std::fs::read(&path)?;
        let arena = LocalArena::new();
        let program = parse_file_content(&arena, FileId::new(path.as_bytes()), &source);
        let mut facts = Vec::new();
        walk(Node::Program(program), &source, None, &mut facts);
        // Keep parser-specific diagnostics separate. Source text is never emitted.
        let errors: Vec<String> = program.errors.iter().map(|e| format!("{e:?}")).collect();
        serde_json::to_writer(&mut output, &json!({"path":path,"facts":facts,"errors":errors}))?;
        output.write_all(b"\n")?;
    }
    output.flush()?;
    Ok(())
}
