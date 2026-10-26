//! Basic-only conservative assignment and dispatch facts. Deep is intentionally
//! handled by the existing syntax consumer, never entered from this extractor.
use crate::basic::{Ast, N};
use mago_database::file::FileId;
use mago_syntax::{lexer::Lexer, settings::LexerSettings};
use mago_syntax_core::input::Input;
use serde_json::{Map, Value, json};
use std::collections::HashMap;

type Variables = HashMap<String, Value>;

#[derive(Clone, Default)]
struct Scope {
    class: String,
    from: String,
    parent: Option<String>,
    properties: HashMap<String, String>,
    static_context: bool,
}

pub struct Impact {
    pub classes: Value,
    pub calls: Vec<Value>,
    pub notices: Vec<Value>,
    pub returns: Vec<Value>,
    pub descriptors: Map<String, Value>,
    catalog: bool,
    nodes: usize,
    limited: bool,
}

fn unknown() -> Value {
    json!({"type":null,"exact":false})
}
fn typed(type_: Option<String>, exact: bool) -> Value {
    json!({"type":type_,"exact":exact})
}

fn named_callable(value: &str) -> Value {
    let normalized = value.trim_start_matches('\\').to_ascii_lowercase();
    let hash = crate::basic_digest::sha256(normalized.as_bytes());
    let parts = value.strip_prefix('\\').unwrap_or(value).split_once("::");
    let valid_identifier = |name: &str, qualified: bool| {
        !name.is_empty()
            && name.bytes().enumerate().all(|(i, b)| {
                b.is_ascii_alphabetic()
                    || b == b'_'
                    || i > 0 && (b.is_ascii_digit() || qualified && b == b'\\')
            })
    };
    if value.len() <= 1000 {
        if let Some((class, method)) = parts {
            if valid_identifier(class, true) && valid_identifier(method, false) {
                return json!([
                    hash,
                    crate::basic_digest::sha256(
                        class
                            .trim_start_matches('\\')
                            .to_ascii_lowercase()
                            .as_bytes()
                    ),
                    crate::basic_digest::sha256(method.to_ascii_lowercase().as_bytes())
                ]);
            }
        }
    }
    json!([hash, null, null])
}

pub fn php_json(value: &Value) -> String {
    let text = serde_json::to_string(value).unwrap();
    let mut result = String::new();
    for ch in text.chars() {
        if ch == '/' {
            result.push_str("\\/");
        } else if ch.is_ascii() {
            result.push(ch);
        } else {
            for unit in ch.encode_utf16(&mut [0; 2]) {
                result.push_str(&format!("\\u{unit:04x}"));
            }
        }
    }
    result
}

pub fn extract(ast: &Ast<'_>, catalog: bool) -> Impact {
    let mut reader = Impact {
        classes: json!({}),
        calls: Vec::new(),
        notices: Vec::new(),
        returns: Vec::new(),
        descriptors: Map::new(),
        catalog,
        nodes: 0,
        limited: false,
    };
    let classes: Vec<_> = ast
        .roots()
        .into_iter()
        .flat_map(|n| n.descendants())
        .filter(|n| n.class_like())
        .collect();
    for class in classes {
        if class.declared().is_none() && !catalog {
            reader.notice(class.line(), "Anonymous class dispatch is unresolved.");
            continue;
        }
        let name = class
            .declared()
            .unwrap_or_else(|| format!("(anonymous) {}:{}", ast.path, class.start()));
        let mut parents = Vec::new();
        if class.kind() == "statement.class" {
            if let Some(parent) = class.child("extends") {
                parents.push(parent.resolved());
            }
        }
        parents.extend(
            class
                .list(if class.kind() == "statement.interface" {
                    "extends"
                } else {
                    "implements"
                })
                .iter()
                .map(|n| n.resolved()),
        );
        let uses: Vec<_> = class
            .list("stmts")
            .into_iter()
            .filter(|n| n.kind() == "statement.trait-use")
            .collect();
        let traits: Vec<_> = uses
            .iter()
            .flat_map(|n| n.list("traits"))
            .map(|n| n.resolved())
            .collect();
        let mut scope = Scope {
            class: name.clone(),
            parent: parents.first().cloned(),
            ..Scope::default()
        };
        let mut property_order = Vec::new();
        for property in class
            .list("stmts")
            .into_iter()
            .filter(|n| n.kind() == "statement.property")
        {
            if let Some(type_) = reader.type_(property.child("type"), &scope) {
                for prop in property.list("props") {
                    let name = prop.named("name").to_owned();
                    if !property_order.contains(&name) {
                        property_order.push(name.clone());
                    }
                    scope.properties.insert(name, type_.clone());
                }
            }
        }
        let methods: Vec<_> = class
            .list("stmts")
            .into_iter()
            .filter(|n| n.kind() == "statement.class-method")
            .collect();
        if let Some(constructor) = methods
            .iter()
            .find(|n| n.named("name").eq_ignore_ascii_case("__construct"))
        {
            for param in constructor.list("params") {
                if param.field("flags").as_u64().unwrap_or(0) != 0 {
                    if let Some(type_) = reader.type_(param.child("type"), &scope) {
                        if let Some(var) =
                            param.child("var").filter(|n| n.field("name").is_string())
                        {
                            let name = var.name().to_owned();
                            if !property_order.contains(&name) {
                                property_order.push(name.clone());
                            }
                            scope.properties.insert(name, type_);
                        }
                    }
                }
            }
        }
        let mut declarations = Map::new();
        for method in methods {
            let mut signature = signature(method, &name, scope.parent.as_deref());
            if class.kind() == "statement.interface" {
                signature["abstract"] = json!(true);
            }
            declarations.insert(method.named("name").to_ascii_lowercase(),json!({"name":method.named("name"),"line":method.line(),"final":method.flag(32),"signature":signature}));
            scope.from = format!("{name}::{}", method.named("name"));
            scope.static_context = method.flag(8);
            let mut vars = Variables::new();
            if !scope.static_context {
                let mut value = typed(Some(name.clone()), false);
                if catalog {
                    value["bound_this"] = json!(true);
                }
                vars.insert("this".to_owned(), value);
            }
            reader.parameters(method, &scope, &mut vars);
            reader.walk(&method.list("stmts"), &scope, &mut vars);
        }
        let properties: Map<String, Value> = property_order
            .into_iter()
            .map(|name| {
                let value = json!(scope.properties[&name]);
                (name, value)
            })
            .collect();
        reader.classes[&name] = json!({"kind":match class.kind(){"statement.interface"=>"interface","statement.trait"=>"trait",_=>"class"},"line":class.line(),
            "final":class.kind()=="statement.class"&&class.flag(32),"abstract":class.kind()=="statement.class"&&class.flag(16),"parents":parents,"traits":traits,
            "adaptations":uses.iter().any(|n|!n.list("adaptations").is_empty()),"properties":properties,"methods":declarations});
    }
    reader.walk(
        &ast.roots(),
        &Scope {
            from: format!("(file) {}", ast.path),
            static_context: true,
            ..Scope::default()
        },
        &mut Variables::new(),
    );
    reader
}

impl Impact {
    fn notice(&mut self, line: usize, reason: &str) {
        self.notices.push(json!({"line":line,"reason":reason}));
    }
    fn tick(&mut self, node: N<'_>) -> bool {
        if self.limited {
            return false;
        }
        self.nodes += 1;
        if self.nodes > 20000 {
            self.notice(node.line(), "Per-file impact AST limit reached.");
            self.limited = true;
            false
        } else {
            true
        }
    }
    fn parameters(&self, node: N<'_>, scope: &Scope, vars: &mut Variables) {
        for param in node.list("params") {
            if let Some(var) = param
                .child("var")
                .filter(|n| n.kind() == "expression.variable" && n.field("name").is_string())
            {
                vars.insert(
                    var.name().to_owned(),
                    typed(self.type_(param.child("type"), scope), false),
                );
            }
        }
    }
    fn type_(&self, node: Option<N<'_>>, scope: &Scope) -> Option<String> {
        let node = node?;
        if node.kind() == "nullable-type" {
            return self.type_(node.child("type"), scope);
        }
        if self.catalog && matches!(node.kind(), "union-type" | "intersection-type") {
            let mut types = Vec::new();
            for member in node.list("types") {
                if let Some(type_) = self.type_(Some(member), scope) {
                    if !types.contains(&type_) {
                        types.push(type_);
                    }
                }
            }
            return if types.is_empty() {
                None
            } else {
                Some(format!("@types:{}", php_json(&json!(types))))
            };
        }
        if !node.is_name() {
            return None;
        }
        match node.name().to_ascii_lowercase().as_str() {
            "self" | "static" => {
                if scope.class.is_empty() {
                    None
                } else {
                    Some(scope.class.clone())
                }
            }
            "parent" => scope.parent.clone().or_else(|| {
                if self.catalog && !scope.class.is_empty() {
                    Some(format!("@parent:{}", scope.class))
                } else {
                    None
                }
            }),
            _ => Some(node.resolved()),
        }
    }
    fn walk(&mut self, nodes: &[N<'_>], scope: &Scope, vars: &mut Variables) {
        for &node in nodes {
            if !self.tick(node) {
                return;
            }
            if node.kind() == "statement.function" {
                if self.catalog {
                    let mut nested = scope.clone();
                    nested.class.clear();
                    nested.from = node
                        .declared()
                        .unwrap_or_else(|| node.named("name").to_owned());
                    nested.parent = None;
                    nested.properties.clear();
                    nested.static_context = true;
                    let mut locals = Variables::new();
                    self.parameters(node, &nested, &mut locals);
                    self.walk(&node.list("stmts"), &nested, &mut locals);
                } else {
                    self.notice(
                        node.line(),
                        "Standalone function bodies are outside method dispatch analysis.",
                    );
                }
                continue;
            }
            if node.class_like() {
                continue;
            }
            if node.kind().starts_with("expression.") {
                self.expression(node, scope, vars);
                continue;
            }
            if node.kind() == "statement.unset" {
                for var in node.list("vars") {
                    if var.kind() == "expression.variable" && var.field("name").is_string() {
                        vars.insert(var.name().to_owned(), unknown());
                    }
                }
            }
            if node.kind() == "statement.foreach" {
                for key in ["keyVar", "valueVar"] {
                    if let Some(var) = node.child(key).filter(|n| {
                        n.kind() == "expression.variable" && n.field("name").is_string()
                    }) {
                        vars.insert(var.name().to_owned(), unknown());
                    }
                }
            }
            if self.catalog && node.kind() == "statement.return" && node.child("expr").is_some() {
                let value = self.expression(node.child("expr").unwrap(), scope, vars);
                self.returns.push(json!({"from":scope.from,"line":node.line(),"end_line":node.end_line(),"offset":node.start(),"value":value}));
                continue;
            }
            if node.kind() == "statement.expression" {
                if let Some(expr) = node.child("expr") {
                    self.expression(expr, scope, vars);
                }
                continue;
            }
            let branched = matches!(
                node.kind(),
                "statement.if"
                    | "statement.switch"
                    | "statement.try-catch"
                    | "statement.for"
                    | "statement.foreach"
                    | "statement.while"
                    | "statement.do"
            );
            let condition = matches!(node.kind(), "statement.if" | "statement.switch");
            if condition {
                self.expression(node.child("cond").unwrap(), scope, vars);
            }
            if matches!(
                node.kind(),
                "statement.for" | "statement.foreach" | "statement.while" | "statement.do"
            ) {
                self.invalidate_loop(node, vars);
            }
            let mut changed = Vec::new();
            for (key, children) in node.groups() {
                if condition && key == "cond" {
                    continue;
                }
                let mut branch = vars.clone();
                self.walk(&children, scope, &mut branch);
                if !branched {
                    *vars = branch;
                } else {
                    for var in vars.keys().chain(branch.keys()) {
                        if vars.get(var) != branch.get(var) && !changed.contains(var) {
                            changed.push(var.clone());
                        }
                    }
                }
            }
            for var in changed {
                vars.insert(var, unknown());
            }
        }
    }
    fn invalidate_loop(&self, node: N<'_>, vars: &mut Variables) {
        for write in node.descendants() {
            let assignment = matches!(
                write.kind(),
                "expression.assign"
                    | "expression.assign-ref"
                    | "expression.pre-inc"
                    | "expression.post-inc"
                    | "expression.pre-dec"
                    | "expression.post-dec"
            ) || write.kind().starts_with("expression.assign-op.");
            let call = matches!(
                write.kind(),
                "expression.new"
                    | "expression.func-call"
                    | "expression.method-call"
                    | "expression.nullsafe-method-call"
                    | "expression.static-call"
            );
            if !assignment
                && !call
                && !matches!(write.kind(), "statement.unset" | "statement.foreach")
            {
                continue;
            }
            if write.kind() == "expression.assign-ref" {
                Self::invalidate_locals(vars);
                continue;
            }
            let targets = if call {
                if write.first_class() {
                    Vec::new()
                } else {
                    write
                        .list("args")
                        .iter()
                        .filter_map(|n| n.child("value"))
                        .collect()
                }
            } else if write.kind() == "statement.unset" {
                write.list("vars")
            } else if write.kind() == "statement.foreach" {
                [write.child("keyVar"), write.child("valueVar")]
                    .into_iter()
                    .flatten()
                    .collect()
            } else {
                let target = write.child("var");
                if !target.is_some_and(|n| {
                    n.kind() == "expression.variable" && n.field("name").is_string()
                }) && !target.is_some_and(|n| n.kind() == "expression.property-fetch")
                {
                    Self::invalidate_locals(vars);
                }
                target.into_iter().collect()
            };
            for target in targets {
                if target.kind() == "expression.variable" && target.field("name").is_string() {
                    vars.insert(target.name().to_owned(), unknown());
                }
            }
        }
    }
    fn invalidate_locals(vars: &mut Variables) {
        for (name, value) in vars {
            if name != "this" {
                *value = unknown();
            }
        }
    }
    fn expression(&mut self, expr: N<'_>, scope: &Scope, vars: &mut Variables) -> Value {
        if self.limited {
            return unknown();
        }
        if self.catalog && expr.kind() == "scalar.string" {
            return json!({"type":null,"exact":false,"callable_name":named_callable(expr.field("value").as_str().unwrap())});
        }
        if !self.tick(expr) {
            return unknown();
        }
        if expr.kind() == "expression.variable" {
            return vars.get(expr.name()).cloned().unwrap_or_else(unknown);
        }
        if matches!(
            expr.kind(),
            "expression.closure" | "expression.arrow-function"
        ) {
            if !self.catalog {
                self.notice(
                    expr.line(),
                    "Callback body is not a proved immediate invocation.",
                );
                return unknown();
            }
            let arrow = expr.kind() == "expression.arrow-function";
            let mut locals = if arrow {
                vars.clone()
            } else {
                Variables::new()
            };
            if !arrow {
                for use_ in expr.list("uses") {
                    if let Some(var) = use_.child("var").filter(|n| n.field("name").is_string()) {
                        locals.insert(
                            var.name().to_owned(),
                            if use_.boolean("byRef") {
                                unknown()
                            } else {
                                vars.get(var.name()).cloned().unwrap_or_else(unknown)
                            },
                        );
                    }
                }
            }
            if !expr.boolean("static") {
                if let Some(this) = vars.get("this") {
                    locals.insert("this".to_owned(), this.clone());
                } else {
                    locals.remove("this");
                }
            } else {
                locals.remove("this");
            }
            let name = format!("(closure) {}:{}", expr.ast.path, expr.start());
            let nested = Scope {
                from: name.clone(),
                static_context: expr.boolean("static") || scope.static_context,
                ..scope.clone()
            };
            self.parameters(expr, &nested, &mut locals);
            if arrow {
                let value = self.expression(expr.child("expr").unwrap(), &nested, &mut locals);
                self.returns.push(json!({"from":name,"line":expr.line(),"end_line":expr.end_line(),"offset":expr.start(),"value":value}));
            } else {
                self.walk(&expr.list("stmts"), &nested, &mut locals);
            }
            return typed(Some(format!("@closure:{name}")), true);
        }
        if matches!(expr.kind(), "expression.assign" | "expression.assign-ref") {
            let value = self.expression(expr.child("expr").unwrap(), scope, vars);
            if expr.kind() == "expression.assign-ref" {
                Self::invalidate_locals(vars);
                return unknown();
            }
            let target = expr.child("var").unwrap();
            if target.kind() == "expression.variable" && target.field("name").is_string() {
                vars.insert(target.name().to_owned(), value.clone());
            } else if target.kind() == "expression.property-fetch"
                && target
                    .child("name")
                    .is_some_and(|n| n.kind() == "identifier")
            {
                let owner = self.expression(target.child("var").unwrap(), scope, vars);
                if let Some(type_) = owner["type"].as_str() {
                    vars.insert(
                        format!("@property:{type_}#{}", target.named("name")),
                        unknown(),
                    );
                }
            } else {
                Self::invalidate_locals(vars);
            }
            return value;
        }
        if matches!(
            expr.kind(),
            "expression.property-fetch" | "expression.nullsafe-property-fetch"
        ) {
            let owner = self.expression(expr.child("var").unwrap(), scope, vars);
            if let Some(type_) = owner["type"].as_str() {
                if expr.child("name").is_some_and(|n| n.kind() == "identifier") {
                    let key = format!("@property:{type_}#{}", expr.named("name"));
                    if let Some(value) = vars.get(&key) {
                        return value.clone();
                    }
                    let resolved = if type_ == scope.class {
                        scope.properties.get(expr.named("name")).cloned()
                    } else {
                        None
                    };
                    let type_ = resolved.unwrap_or_else(|| {
                        let owner = if self.catalog
                            && [
                                "@return:",
                                "@method-callable:",
                                "@after-argument:",
                                "@property:",
                            ]
                            .iter()
                            .any(|prefix| type_.starts_with(prefix))
                        {
                            let hash = crate::basic_digest::sha256(type_.as_bytes());
                            self.descriptors.insert(hash.clone(), json!(type_));
                            format!("@descriptor:{hash}")
                        } else {
                            type_.to_owned()
                        };
                        format!("@property:{owner}#{}", expr.named("name"))
                    });
                    return typed(Some(type_), false);
                }
            }
            return unknown();
        }
        if matches!(
            expr.kind(),
            "expression.method-call"
                | "expression.nullsafe-method-call"
                | "expression.static-call"
                | "expression.new"
        ) {
            let instance = matches!(
                expr.kind(),
                "expression.method-call" | "expression.nullsafe-method-call"
            );
            let new = expr.kind() == "expression.new";
            let mut receiver = if instance {
                self.expression(expr.child("var").unwrap(), scope, vars)
            } else {
                let class = expr.child("class").unwrap();
                typed(
                    self.type_(Some(class), scope),
                    class.is_name() && !class.name().eq_ignore_ascii_case("static"),
                )
            };
            if self.catalog
                && new
                && expr
                    .child("class")
                    .is_some_and(|n| n.kind() == "statement.class")
            {
                receiver = typed(
                    Some(format!(
                        "(anonymous) {}:{}",
                        expr.ast.path,
                        expr.child("class").unwrap().start()
                    )),
                    true,
                );
            }
            let method = if new {
                Some("__construct")
            } else {
                expr.child("name")
                    .filter(|n| n.kind() == "identifier")
                    .map(|n| n.name())
            };
            let mut call = json!({"from":scope.from,"receiver":receiver["type"],"method":method,"exact":receiver["exact"],"kind":if expr.first_class(){"reference"}else{"call"},"line":expr.line(),"site":site(expr,scope)});
            if self.catalog {
                if let Some(origin) = receiver.get("catalog_instance_origin") {
                    call["receiver_instance_origin"] = origin.clone();
                }
                call["offset"] = json!(expr.start());
                call["end_offset"] = json!(expr.end().saturating_sub(1));
                call["end_line"] = json!(expr.end_line());
                if method.is_some_and(|m| m.eq_ignore_ascii_case("send")) && !expr.first_class() {
                    call["saloon_pool_send"] = json!(expr.list("args").is_empty());
                }
                call["this_receiver"] = json!(instance && receiver["bound_this"] == true);
                call["late_static_receiver"] = json!(
                        expr.kind() == "expression.static-call"
                            && expr.child("class").is_some_and(
                                |n| n.is_name() && n.name().eq_ignore_ascii_case("static")
                            )
                    );
                call["lexical_static_receiver"] = expr
                    .child("class")
                    .filter(|_| expr.kind() == "expression.static-call")
                    .filter(|n| {
                        n.is_name()
                            && ["self", "parent"].contains(&n.name().to_ascii_lowercase().as_str())
                    })
                    .map(|n| json!(n.name().to_ascii_lowercase()))
                    .unwrap_or(Value::Null);
                let args = expr.list("args");
                if new
                    && [2, 3].contains(&args.len())
                    && args.iter().all(|n| n.named("name").len() <= 1000)
                {
                    call["provider_constructor_arguments"]=json!(args.iter().map(|n|json!({"name":n.child("name").map(|n|n.name()),"unpack":n.boolean("unpack"),"by_ref":n.boolean("byRef")})).collect::<Vec<_>>());
                }
            }
            self.calls.push(call);
            if !expr.first_class() {
                for arg in expr.list("args") {
                    if let Some(value) = arg.child("value") {
                        self.expression(value, scope, vars);
                        if value.kind() == "expression.variable" && value.field("name").is_string()
                        {
                            vars.insert(value.name().to_owned(), unknown());
                        }
                    }
                }
            }
            if new {
                if self.catalog {
                    receiver["catalog_instance_origin"] = json!(expr.end().saturating_sub(1));
                }
                return receiver;
            }
            return unknown();
        }
        if self.catalog && expr.kind() == "expression.func-call" {
            let name = expr.child("name").unwrap();
            let receiver = if name.is_name() {
                let resolved = name.resolved();
                let namespaced = name.ast.node(&name.data()["attributes"]["namespacedName"]);
                let mut candidates = Vec::new();
                if let Some(namespaced) = namespaced {
                    candidates.push(namespaced.name().to_owned());
                }
                if !candidates.contains(&resolved) {
                    candidates.push(resolved);
                }
                typed(
                    Some(format!("@function:{}", php_json(&json!(candidates)))),
                    true,
                )
            } else {
                self.expression(name, scope, vars)
            };
            let mut call = json!({"from":scope.from,"receiver":receiver["type"],"method":"__invoke","exact":receiver["exact"],"kind":if expr.first_class(){"reference"}else{"call"},"line":expr.line(),"site":site(expr,scope),"offset":expr.start(),"end_line":expr.end_line(),"bound_callable":receiver.get("bound_callable").cloned().unwrap_or(Value::Null),"callable_form":receiver.get("callable_form").cloned().unwrap_or(Value::Null)});
            let names = receiver["type"]
                .as_str()
                .and_then(|s| s.strip_prefix("@function:"))
                .and_then(|s| serde_json::from_str::<Vec<String>>(s).ok())
                .unwrap_or_default();
            let json_encode = names.iter().any(|s| s.eq_ignore_ascii_case("json_encode"));
            let global = json_encode && names.len() == 1;
            let position = self.calls.len();
            self.calls.push(call.clone());
            if !expr.first_class() {
                for (index, arg) in expr.list("args").iter().enumerate() {
                    if let Some(value) = arg.child("value") {
                        let evaluated = self.expression(value, scope, vars);
                        if json_encode
                            && !arg.boolean("unpack")
                            && (arg.named("name") == "value"
                                || arg.child("name").is_none() && index == 0)
                            && !evaluated["type"].is_null()
                        {
                            call["json_serialization_receiver"] = evaluated["type"].clone();
                            self.calls[position] = call.clone();
                        }
                        if !global
                            && value.kind() == "expression.variable"
                            && value.field("name").is_string()
                        {
                            vars.insert(value.name().to_owned(), unknown());
                        }
                    }
                }
            }
            return if expr.first_class() {
                receiver
            } else {
                unknown()
            };
        }
        if expr.kind() == "expression.array"
            && expr.field("items").as_array().is_some_and(|a| a.len() == 2)
        {
            if let Some(value) = self.array_callable(expr, scope, vars) {
                return value;
            }
        }
        let branching = matches!(
            expr.kind(),
            "expression.ternary"
                | "expression.match"
                | "expression.binary-op.boolean-and"
                | "expression.binary-op.boolean-or"
                | "expression.binary-op.coalesce"
        );
        for (_, children) in expr.groups() {
            let mut branch = vars.clone();
            self.walk(&children, scope, &mut branch);
            if branching {
                for (name, value) in branch {
                    if vars.get(&name) != Some(&value) {
                        vars.insert(name, unknown());
                    }
                }
            } else {
                *vars = branch;
            }
        }
        if expr.kind() == "expression.func-call" && !expr.first_class() {
            for arg in expr.list("args") {
                if let Some(value) = arg
                    .child("value")
                    .filter(|n| n.kind() == "expression.variable" && n.field("name").is_string())
                {
                    vars.insert(value.name().to_owned(), unknown());
                }
            }
        }
        if expr.kind().starts_with("expression.assign-op.")
            || matches!(
                expr.kind(),
                "expression.pre-inc"
                    | "expression.post-inc"
                    | "expression.pre-dec"
                    | "expression.post-dec"
            )
        {
            if let Some(var) = expr
                .child("var")
                .filter(|n| n.kind() == "expression.variable" && n.field("name").is_string())
            {
                vars.insert(var.name().to_owned(), unknown());
            }
        }
        unknown()
    }
    fn array_callable(
        &mut self,
        expr: N<'_>,
        scope: &Scope,
        vars: &mut Variables,
    ) -> Option<Value> {
        let mut items = vec![None, None];
        let mut next = 0i64;
        for (position, item) in expr.field("items").as_array()?.iter().enumerate() {
            let item = expr.ast.node(item)?;
            if item.boolean("unpack") || item.boolean("byRef") {
                return None;
            }
            let key = if self.catalog {
                item.child("key")
                    .map(|n| {
                        if n.kind() == "scalar.int" {
                            n.field("value").as_i64()
                        } else {
                            None
                        }
                    })
                    .unwrap_or(Some(next))?
            } else {
                position as i64
            };
            if !(0..=1).contains(&key) || items[key as usize].is_some() {
                return None;
            }
            if !self.catalog {
                if let Some(index) = item.child("key") {
                    if index.kind() != "scalar.int"
                        || index.field("value").as_i64() != Some(position as i64)
                    {
                        return None;
                    }
                }
            }
            items[key as usize] = Some(item);
            next = next.max(key + 1);
        }
        let first = items[0]?;
        let second = items[1]?
            .child("value")
            .filter(|n| n.kind() == "scalar.string")?;
        let value = first.child("value")?;
        if self.catalog && value.kind() == "scalar.string" {
            return Some(
                json!({"type":null,"exact":false,"callable_name":named_callable(&format!("{}::{}",value.field("value").as_str()?,second.field("value").as_str()?))}),
            );
        }
        let class_constant = value.kind() == "expression.class-const-fetch"
            && value.named("name").eq_ignore_ascii_case("class")
            && value.child("class").is_some_and(|n| n.is_name());
        let receiver = if class_constant {
            typed(self.type_(value.child("class"), scope), true)
        } else {
            self.expression(value, scope, vars)
        };
        if receiver["type"].is_null() {
            return None;
        }
        let method = second.field("value").as_str()?;
        let mut call = json!({"from":scope.from,"receiver":receiver["type"],"method":method,"exact":receiver["exact"],"kind":"reference","line":expr.line(),"site":{"form":"reference","class":scope.class,"static_context":scope.static_context,"arguments":[]}});
        if self.catalog {
            call["offset"] = json!(expr.start());
            call["end_line"] = json!(expr.end_line());
            call["site"]["form"] = json!(if value.kind() == "expression.class-const-fetch" {
                "class"
            } else {
                "instance"
            });
        }
        self.calls.push(call);
        if self.catalog {
            Some(
                json!({"type":format!("@method-callable:{}",php_json(&json!([receiver["type"],method,receiver["exact"]]))),"exact":false,"callable_form":if value.kind()=="expression.class-const-fetch"{"static"}else{"instance"},"bound_callable":{"method":method,"this":receiver["bound_this"]==true,"late":false,"lexical":null,"creator":scope.from,"scope_bound":false}}),
            )
        } else {
            Some(unknown())
        }
    }
}

fn signature_type(node: Option<N<'_>>, class: &str, parent: Option<&str>) -> Option<String> {
    let node = node?;
    if node.kind() == "nullable-type" {
        return Some(format!(
            "?{}",
            signature_type(node.child("type"), class, parent).unwrap_or_default()
        ));
    }
    if matches!(node.kind(), "union-type" | "intersection-type") {
        let mut parts: Vec<_> = node
            .list("types")
            .iter()
            .map(|&n| signature_type(Some(n), class, parent).unwrap_or_default())
            .collect();
        parts.sort();
        return Some(format!(
            "({})",
            parts.join(if node.kind() == "union-type" {
                "|"
            } else {
                "&"
            })
        ));
    }
    if node.is_name() {
        return Some(match node.name().to_ascii_lowercase().as_str() {
            "self" => class.to_ascii_lowercase(),
            "parent" => parent.unwrap_or("@unknown-parent").to_ascii_lowercase(),
            "static" => format!("@static:{}", class.to_ascii_lowercase()),
            _ => node.resolved().to_ascii_lowercase(),
        });
    }
    Some(node.name().to_ascii_lowercase())
}

fn signature(node: N<'_>, class: &str, parent: Option<&str>) -> Value {
    let params = node.list("params");
    let last = params
        .iter()
        .rposition(|n| n.child("default").is_none() && !n.boolean("variadic"));
    json!({"parameters":params.iter().enumerate().map(|(position,param)|json!({"name":param.child("var").map(|n|n.name()).unwrap_or(""),
        "optional":param.boolean("variadic")||param.child("default").is_some()&&last.is_none_or(|last|position>last),"default":param.child("default").map(pretty),
        "variadic":param.boolean("variadic"),"by_ref":param.boolean("byRef"),"type":signature_type(param.child("type"),class,parent),"promoted":param.field("flags").as_u64().unwrap_or(0)!=0})).collect::<Vec<_>>(),
        "visibility":node.visibility(),"static":node.flag(8),"abstract":node.flag(16),"final":node.flag(32),"return_type":signature_type(node.child("returnType"),class,parent),"return_by_ref":node.boolean("byRef")})
}

fn site(expr: N<'_>, scope: &Scope) -> Value {
    let form = if expr.kind() == "expression.new" {
        "new".to_owned()
    } else if expr.kind() == "expression.static-call" {
        expr.child("class")
            .filter(|n| n.is_name())
            .map(|n| n.name().to_ascii_lowercase())
            .filter(|n| ["self", "parent", "static"].contains(&n.as_str()))
            .unwrap_or_else(|| "class".to_owned())
    } else {
        "instance".to_owned()
    };
    json!({"form":form,"class":scope.class,"static_context":scope.static_context,"arguments":if expr.first_class(){Vec::new()}else{expr.list("args").iter().map(|arg|{let value=arg.child("value").unwrap();let reference=if matches!(value.kind(),"expression.variable"|"expression.property-fetch"|"expression.static-property-fetch"|"expression.array-dim-fetch"){"variable"}else if matches!(value.kind(),"expression.func-call"|"expression.method-call"|"expression.nullsafe-method-call"|"expression.static-call"){"unknown"}else{"value"};json!({"name":arg.child("name").map(|n|n.name()),"unpack":arg.boolean("unpack"),"reference":reference})}).collect::<Vec<_>>()}})
}

fn pretty(node: N<'_>) -> String {
    if node.is_name() {
        return format!(
            "{}{}",
            match node.kind() {
                "name.fully-qualified" => "\\",
                "name.relative" => "namespace\\",
                _ => "",
            },
            node.name()
        );
    }
    let text = || String::from_utf8_lossy(&node.ast.source[node.start()..node.end()]).into_owned();
    match node.kind() {
        "identifier" | "var-like-identifier" => node.name().to_owned(),
        "scalar.string" => {
            let raw = text();
            let value = node.field("value").as_str().unwrap();
            if raw.trim_start_matches(['b', 'B']).starts_with('"') {
                serde_json::to_string(value)
                    .unwrap()
                    .replace('\u{2028}', "\\u2028")
                    .replace('\u{2029}', "\\u2029")
            } else {
                format!(
                    "'{}'",
                    value
                        .replace('\\', "\\\\")
                        .replace('\'', "\\'")
                        .replace('\0', "' . \"\\0\" . '")
                )
            }
        }
        "scalar.int" => node.field("value").to_string(),
        "scalar.float" => php_float(node.field("value")),
        "expression.variable" => {
            if node.field("name").is_string() {
                format!("${}", node.name())
            } else {
                format!("${{{}}}", pretty(node.child("name").unwrap()))
            }
        }
        "expression.const-fetch" => pretty(node.child("name").unwrap()),
        "expression.class-const-fetch" => format!(
            "{}::{}",
            pretty(node.child("class").unwrap()),
            pretty(node.child("name").unwrap())
        ),
        "expression.unary-minus" => format!("-{}", pretty(node.child("expr").unwrap())),
        "expression.unary-plus" => format!("+{}", pretty(node.child("expr").unwrap())),
        "expression.array" => format!(
            "[{}]",
            node.field("items")
                .as_array()
                .unwrap()
                .iter()
                .map(|value| node.ast.node(value).map(pretty).unwrap_or_default())
                .collect::<Vec<_>>()
                .join(", ")
        ),
        "array-item" => format!(
            "{}{}{}{}",
            node.child("key")
                .map(|n| format!("{} => ", pretty(n)))
                .unwrap_or_default(),
            if node.boolean("byRef") { "&" } else { "" },
            if node.boolean("unpack") { "..." } else { "" },
            pretty(node.child("value").unwrap())
        ),
        "expression.new" => format!(
            "new {}({})",
            pretty(node.child("class").unwrap()),
            node.list("args")
                .iter()
                .map(|&n| pretty(n))
                .collect::<Vec<_>>()
                .join(", ")
        ),
        "arg" => format!(
            "{}{}{}",
            node.child("name")
                .map(|n| format!("{}: ", n.name()))
                .unwrap_or_default(),
            if node.boolean("unpack") { "..." } else { "" },
            pretty(node.child("value").unwrap())
        ),
        _ => {
            let raw = text();
            let mut lexer = Lexer::scripting(
                Input::new(FileId::new(b"signature"), raw.as_bytes()),
                LexerSettings::default(),
            );
            let mut tokens = Vec::new();
            while let Some(token) = lexer.advance() {
                let token = token.expect("already validated default expression");
                if !token.kind.is_trivia() {
                    tokens.push(String::from_utf8_lossy(token.value).into_owned());
                }
            }
            tokens.join(" ")
        }
    }
}

fn php_float(value: &Value) -> String {
    let raw = value.to_string();
    let negative = raw.starts_with('-');
    let raw = raw.trim_start_matches('-');
    let (mantissa, exponent) = raw
        .split_once(['e', 'E'])
        .map(|(m, e)| (m, e.parse::<i32>().unwrap()))
        .unwrap_or((raw, 0));
    let before = mantissa.find('.').unwrap_or(mantissa.len()) as i32;
    let mut digits = mantissa.replace('.', "");
    let leading = digits.bytes().take_while(|&b| b == b'0').count();
    if leading == digits.len() {
        return if negative { "-0.0" } else { "0.0" }.to_owned();
    }
    digits = digits[leading..].trim_end_matches('0').to_owned();
    let exponent = exponent + before - 1 - leading as i32;
    let sign = if negative { "-" } else { "" };
    if !(-4..17).contains(&exponent) {
        let mantissa = if digits.len() == 1 {
            format!("{digits}.0")
        } else {
            format!("{}.{}", &digits[..1], &digits[1..])
        };
        return format!(
            "{sign}{mantissa}E{}{exponent}",
            if exponent >= 0 { "+" } else { "" }
        );
    }
    if exponent < 0 {
        return format!("{sign}0.{}{digits}", "0".repeat((-exponent - 1) as usize));
    }
    let point = exponent as usize + 1;
    if point >= digits.len() {
        format!("{sign}{digits}{}.0", "0".repeat(point - digits.len()))
    } else {
        format!("{sign}{}.{}", &digits[..point], &digits[point..])
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use mago_allocator::LocalArena;
    use mago_names::resolver::NameResolver;
    use mago_syntax::parser::parse_file_content;
    fn normalize(value: &Value) -> Value {
        match value {
            Value::Object(map) => {
                if map.is_empty() {
                    return json!([]);
                }
                let mut result = Map::new();
                for (k, v) in map {
                    result.insert(k.clone(), normalize(v));
                }
                Value::Object(result)
            }
            Value::Array(values) => json!(values.iter().map(normalize).collect::<Vec<_>>()),
            _ => value.clone(),
        }
    }
    #[test]
    fn basic_impact_matches_php_oracle_including_returns_and_descriptors() {
        let cases: Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/Analysis/basic-impact.json"
        ))
        .unwrap();
        for case in cases["cases"].as_array().unwrap() {
            let source = case["source"].as_str().unwrap();
            let path = case["path"].as_str().unwrap();
            let arena = LocalArena::new();
            let program =
                parse_file_content(&arena, FileId::new(path.as_bytes()), source.as_bytes());
            assert!(program.errors.is_empty());
            let names = NameResolver::new(&arena).resolve(program);
            let syntax = crate::syntax::document(program, &names)
                .unwrap_or_else(|e| panic!("{}", e.message));
            let ast = Ast {
                syntax: &syntax,
                path,
                source: source.as_bytes(),
            };
            let result = extract(&ast, case["catalog"].as_bool().unwrap());
            let actual =
                json!({"classes":result.classes,"calls":result.calls,"notices":result.notices});
            assert_eq!(
                normalize(&actual),
                normalize(&case["expected"]),
                "{} impact",
                case["id"]
            );
            assert_eq!(
                normalize(&json!(result.returns)),
                normalize(&case["returns"]),
                "{} returns",
                case["id"]
            );
            assert_eq!(
                normalize(&json!(result.descriptors)),
                normalize(&case["descriptors"]),
                "{} descriptors",
                case["id"]
            );
        }
    }
}
