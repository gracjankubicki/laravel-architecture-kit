use crate::basic::{Ast, N, Records};
use serde_json::{Value, json};
use std::collections::HashMap;

#[derive(Clone, Default)]
struct Scope {
    owner: Value,
    class: Option<String>,
    types: HashMap<String, String>,
    has_this: bool,
}

pub fn extract(ast: &Ast<'_>, records: &mut Records) -> (Vec<Value>, Vec<Value>) {
    let owners: HashMap<_, _> = records
        .elements
        .iter()
        .filter(|e| {
            [
                "class",
                "interface",
                "trait",
                "enum",
                "method",
                "function",
                "closure",
            ]
            .contains(&e["kind"].as_str().unwrap())
        })
        .map(|e| (e["offset"].as_u64().unwrap() as usize, e.clone()))
        .collect();
    let relation_start = records.relations.len();
    let diagnostic_start = records.diagnostics.len();
    let file = records.identity(json!(["element", "file", ast.path, 0]));
    let mut reader = Reader {
        records,
        owners,
        visits: 0,
        limited: false,
    };
    for root in ast.roots() {
        reader.visit(
            root,
            Scope {
                owner: file.clone(),
                ..Scope::default()
            },
        );
    }
    (
        reader.records.relations.split_off(relation_start),
        reader.records.diagnostics.split_off(diagnostic_start),
    )
}

struct Reader<'a> {
    records: &'a mut Records,
    owners: HashMap<usize, Value>,
    visits: usize,
    limited: bool,
}

impl Reader<'_> {
    fn visit(&mut self, node: N<'_>, mut scope: Scope) {
        if self.limited {
            return;
        }
        self.visits += 1;
        if self.visits > 25000 {
            self.limited = true;
            self.records.diagnostic(
                "container_limit",
                "Container source analysis reached its node or memory budget.",
                node.line(),
                scope.owner,
            );
            return;
        }
        if node.class_like() {
            if let Some(element) = self.owners.get(&node.start()) {
                scope.owner = element["id"].clone();
                scope.class = element["name"].as_str().map(str::to_owned);
            }
            scope.types.clear();
            scope.has_this = false;
        } else if node.callable() {
            if let Some(element) = self.owners.get(&node.start()) {
                scope.owner = element["id"].clone();
            }
            scope.types.clear();
            scope.has_this =
                if matches!(node.kind(), "statement.class-method" | "statement.function") {
                    node.kind() == "statement.class-method" && !node.flag(8)
                } else {
                    scope.has_this && !node.boolean("static")
                };
            for param in node.list("params") {
                if let (Some(type_), Some(var)) = (
                    param.child("type").filter(|n| n.is_name()),
                    param.child("var").filter(|n| {
                        n.kind() == "expression.variable" && n.field("name").is_string()
                    }),
                ) {
                    scope.types.insert(var.name().to_owned(), type_.resolved());
                }
            }
            invalidate(node, &mut scope.types, 0);
        }
        if matches!(
            node.kind(),
            "expression.static-call" | "expression.method-call"
        ) && node.child("name").is_some_and(|n| n.kind() == "identifier")
            && !node.first_class()
        {
            let method = node.named("name").to_ascii_lowercase();
            if [
                "bind",
                "singleton",
                "scoped",
                "instance",
                "bindif",
                "singletonif",
                "scopedif",
            ]
            .contains(&method.as_str())
            {
                if let Some(receiver) = receiver(node, &scope) {
                    self.binding(node, &scope, &receiver, &method, None, Some(Vec::new()));
                }
            } else if method == "give" && node.kind() == "expression.method-call" {
                if let Some(needs) = node.child("var").filter(|n| {
                    n.kind() == "expression.method-call"
                        && n.named("name").eq_ignore_ascii_case("needs")
                }) {
                    if let Some(when) = needs.child("var").filter(|n| {
                        matches!(
                            n.kind(),
                            "expression.method-call" | "expression.static-call"
                        ) && n.named("name").eq_ignore_ascii_case("when")
                    }) {
                        if let Some(receiver) = receiver(when, &scope) {
                            self.binding(
                                node,
                                &scope,
                                &receiver,
                                "contextual",
                                argument(needs, 0, "abstract"),
                                names(argument(when, 0, "concrete"), 0),
                            );
                        }
                    }
                }
            }
        }
        for child in node.children() {
            self.visit(child, scope.clone());
        }
    }
    fn binding(
        &mut self,
        call: N<'_>,
        scope: &Scope,
        receiver: &str,
        mode: &str,
        abstract_: Option<N<'_>>,
        contexts: Option<Vec<String>>,
    ) {
        let abstract_ = abstract_.or_else(|| {
            if mode == "contextual" {
                None
            } else {
                argument(call, 0, "abstract")
            }
        });
        let implementation = argument(
            call,
            if mode == "contextual" { 0 } else { 1 },
            if mode == "contextual" {
                "implementation"
            } else if mode == "instance" {
                "instance"
            } else {
                "concrete"
            },
        );
        let mut callback = implementation
            .filter(|n| matches!(n.kind(), "expression.closure" | "expression.arrow-function"));
        let mut abstract_ = abstract_;
        if let Some(factory) = abstract_
            .filter(|n| matches!(n.kind(), "expression.closure" | "expression.arrow-function"))
        {
            callback = Some(factory);
            abstract_ = None;
        }
        let mut abstracts = if abstract_.is_some_and(|n| n.kind() == "expression.array") {
            Vec::new()
        } else {
            names(abstract_, 0).unwrap_or_default()
        };
        let mut implementations = if mode == "instance"
            || implementation.is_some_and(|n| n.kind() == "expression.array")
        {
            Vec::new()
        } else {
            names(implementation, 0).unwrap_or_default()
        };
        let mut unknown = false;
        if let Some(callback) = callback {
            if abstract_.is_none() {
                abstracts = contracts(callback.child("returnType"));
            }
            let mut factory = Factory { visits: 0 };
            let result = if callback.kind() == "expression.arrow-function" {
                factory.expression(callback.child("expr").unwrap(), &HashMap::new())
            } else {
                factory.statements(&callback.list("stmts"), HashMap::new())
            };
            implementations = result.types;
            unknown = result.unknown;
        } else if let Some(type_) = implementation
            .filter(|n| n.kind() == "expression.new")
            .and_then(|n| n.child("class"))
            .filter(|n| n.is_name())
        {
            implementations = vec![type_.resolved()];
        } else if implementation.is_none() && mode != "instance" && mode != "contextual" {
            implementations = abstracts.clone();
        }
        if mode == "contextual"
            && (abstracts.is_empty() || abstracts.iter().any(|s| s.starts_with('$')))
        {
            implementations.clear();
        }
        let metadata = json!({"receiver":receiver,"mode":mode,"abstracts":abstracts,"implementations":implementations,"contexts":contexts.clone().unwrap_or_default(),"context_unknown":contexts.is_none(),"factory_unknown":unknown,"offset":call.start(),"callback":callback.and_then(|n|self.owners.get(&n.start())).map(|e|e["id"].clone()).unwrap_or(Value::Null)});
        let id = self.records.identity(json!(["container", metadata]));
        self.records.relation(
            scope.owner.clone(),
            id,
            "container-registration",
            call.line(),
            call.end_line(),
            "conditional",
            metadata,
        );
    }
}

fn invalidate(node: N<'_>, types: &mut HashMap<String, String>, depth: usize) {
    if depth >= 64 {
        types.clear();
        return;
    }
    for child in node.children() {
        if child.class_like()
            || matches!(
                child.kind(),
                "statement.function" | "expression.closure" | "expression.arrow-function"
            )
        {
            continue;
        }
        if !types.is_empty()
            && (matches!(
                child.kind(),
                "expression.assign"
                    | "expression.assign-ref"
                    | "statement.unset"
                    | "statement.foreach"
            ) || child.kind().starts_with("expression.assign-op."))
        {
            types.clear();
            return;
        }
        invalidate(child, types, depth + 1);
    }
}
fn receiver(call: N<'_>, scope: &Scope) -> Option<String> {
    if call.kind() == "expression.static-call" {
        return call
            .child("class")
            .filter(|n| {
                n.is_name()
                    && n.resolved()
                        .eq_ignore_ascii_case("Illuminate\\Support\\Facades\\App")
            })
            .map(|_| "framework".to_owned());
    }
    let receiver = call.child("var")?;
    if receiver.kind() == "expression.func-call"
        && receiver
            .child("name")
            .is_some_and(|n| n.is_name() && n.resolved().eq_ignore_ascii_case("app"))
        && receiver.list("args").is_empty()
    {
        return Some("helper".to_owned());
    }
    if receiver.kind() == "expression.variable" {
        if let Some(type_) = scope.types.get(receiver.name()) {
            return Some(format!("type:{type_}"));
        }
    }
    if receiver.kind() == "expression.property-fetch"
        && receiver.named("name") == "app"
        && receiver
            .child("var")
            .is_some_and(|n| n.kind() == "expression.variable" && n.name() == "this")
        && scope.has_this
    {
        return scope.class.as_ref().map(|name| format!("provider:{name}"));
    }
    None
}
fn argument<'a>(call: N<'a>, position: usize, name: &str) -> Option<N<'a>> {
    call.list("args")
        .iter()
        .enumerate()
        .find(|(index, arg)| {
            arg.kind() == "arg"
                && !arg.boolean("unpack")
                && (arg.named("name") == name || arg.child("name").is_none() && *index == position)
        })
        .and_then(|(_, arg)| arg.child("value"))
}
fn names(node: Option<N<'_>>, depth: usize) -> Option<Vec<String>> {
    if depth >= 16 {
        return None;
    }
    let node = node?;
    if node.kind() == "expression.class-const-fetch"
        && node.named("name").eq_ignore_ascii_case("class")
    {
        return node
            .child("class")
            .filter(|n| n.is_name())
            .map(|n| vec![n.resolved()]);
    }
    if node.kind() == "scalar.string" {
        let value = node.field("value").as_str()?;
        return if !value.is_empty() && value.len() <= 200 {
            Some(vec![value.to_owned()])
        } else {
            None
        };
    }
    if node.kind() == "expression.array" && node.field("items").as_array()?.len() <= 128 {
        let mut out = Vec::new();
        for item in node.field("items").as_array()? {
            let item = node.ast.node(item)?;
            if item.boolean("unpack") {
                return None;
            }
            out.extend(names(item.child("value"), depth + 1)?);
            if out.len() > 128 {
                return None;
            }
        }
        return Some(out);
    }
    None
}
fn contracts(node: Option<N<'_>>) -> Vec<String> {
    let Some(node) = node else {
        return Vec::new();
    };
    if node.kind() == "nullable-type" {
        return contracts(node.child("type"));
    }
    if node.is_name() {
        return if ["self", "static", "parent"].contains(&node.name().to_ascii_lowercase().as_str())
        {
            Vec::new()
        } else {
            vec![node.resolved()]
        };
    }
    if node.kind() == "union-type" {
        let mut names = Vec::new();
        for member in node.list("types") {
            for name in contracts(Some(member)) {
                if !names.contains(&name) {
                    names.push(name);
                }
            }
        }
        return names;
    }
    Vec::new()
}

#[derive(Clone)]
struct FactoryValue {
    types: Vec<String>,
    unknown: bool,
}
impl FactoryValue {
    fn unknown() -> Self {
        Self {
            types: Vec::new(),
            unknown: true,
        }
    }
}
struct Factory {
    visits: usize,
}
impl Factory {
    fn tick(&mut self) -> bool {
        self.visits += 1;
        self.visits <= 128
    }
    fn statements(
        &mut self,
        stmts: &[N<'_>],
        mut vars: HashMap<String, FactoryValue>,
    ) -> FactoryValue {
        for (index, &node) in stmts.iter().enumerate() {
            if !self.tick() {
                return FactoryValue::unknown();
            }
            if node.kind() == "statement.return" {
                return node
                    .child("expr")
                    .map(|n| self.expression(n, &vars))
                    .unwrap_or_else(FactoryValue::unknown);
            }
            if let Some(assign) = node
                .child("expr")
                .filter(|_| node.kind() == "statement.expression")
                .filter(|n| n.kind() == "expression.assign")
            {
                if let Some(var) = assign
                    .child("var")
                    .filter(|n| n.kind() == "expression.variable" && n.field("name").is_string())
                {
                    let expr = assign.child("expr").unwrap();
                    Self::condition(expr, &mut vars);
                    let result = self.expression(expr, &vars);
                    vars.insert(var.name().to_owned(), result);
                    continue;
                }
            }
            if node.kind() == "statement.if" {
                Self::condition(node.child("cond").unwrap(), &mut vars);
                let rest = &stmts[index + 1..];
                let mut branch = node.list("stmts");
                branch.extend_from_slice(rest);
                let mut results = vec![self.statements(&branch, vars.clone())];
                for elseif in node.list("elseifs") {
                    Self::condition(elseif.child("cond").unwrap(), &mut vars);
                    let mut branch = elseif.list("stmts");
                    branch.extend_from_slice(rest);
                    results.push(self.statements(&branch, vars.clone()));
                }
                let mut branch = node
                    .child("else")
                    .map(|n| n.list("stmts"))
                    .unwrap_or_default();
                branch.extend_from_slice(rest);
                results.push(self.statements(&branch, vars));
                return Self::merge(results);
            }
            return FactoryValue::unknown();
        }
        FactoryValue::unknown()
    }
    fn expression(&mut self, node: N<'_>, vars: &HashMap<String, FactoryValue>) -> FactoryValue {
        if !self.tick() {
            return FactoryValue::unknown();
        }
        if node.kind() == "expression.new" {
            if let Some(class) = node.child("class").filter(|n| n.is_name()) {
                return FactoryValue {
                    types: vec![class.resolved()],
                    unknown: false,
                };
            }
        }
        if node.kind() == "expression.variable" {
            return vars
                .get(node.name())
                .cloned()
                .unwrap_or_else(FactoryValue::unknown);
        }
        if node.kind() == "expression.ternary" {
            let mut vars = vars.clone();
            let cond = node.child("cond").unwrap();
            Self::condition(cond, &mut vars);
            let first = self.expression(node.child("if").unwrap_or(cond), &vars);
            let second = self.expression(node.child("else").unwrap(), &vars);
            return Self::merge(vec![first, second]);
        }
        if node.kind() == "expression.match" {
            let mut vars = vars.clone();
            Self::condition(node.child("cond").unwrap(), &mut vars);
            let mut results = Vec::new();
            for arm in node.list("arms") {
                for cond in arm.list("conds") {
                    Self::condition(cond, &mut vars);
                }
                results.push(self.expression(arm.child("body").unwrap(), &vars));
            }
            return Self::merge(results);
        }
        FactoryValue::unknown()
    }
    fn condition(node: N<'_>, vars: &mut HashMap<String, FactoryValue>) {
        if node.descendants().iter().any(|n| {
            matches!(
                n.kind(),
                "expression.assign"
                    | "expression.assign-ref"
                    | "expression.pre-inc"
                    | "expression.post-inc"
                    | "expression.pre-dec"
                    | "expression.post-dec"
                    | "expression.func-call"
                    | "expression.method-call"
                    | "expression.static-call"
            ) || n.kind().starts_with("expression.assign-op.")
                || n.kind() == "expression.new" && !n.list("args").is_empty()
        }) {
            for value in vars.values_mut() {
                *value = FactoryValue::unknown();
            }
        }
    }
    fn merge(values: Vec<FactoryValue>) -> FactoryValue {
        let mut types = Vec::new();
        let mut unknown = false;
        for value in values {
            unknown |= value.unknown;
            for name in value.types {
                if !types.contains(&name) {
                    types.push(name);
                }
            }
        }
        if types.len() > 4 {
            FactoryValue::unknown()
        } else {
            FactoryValue {
                unknown: unknown || types.is_empty(),
                types,
            }
        }
    }
}
