use crate::basic::{Ast, N, Records};
use serde_json::{Map, Value, json};
use std::collections::{HashMap, HashSet};

const SYMBOLIC: &str = "\0id\0";
type Variables = HashMap<String, Option<String>>;
pub fn is_test(path: &str) -> bool {
    let path = path.replace('\\', "/");
    path.starts_with("tests/") || path.contains("/Tests/")
}

pub struct Invocations {
    pub records: Vec<Value>,
    pub offsets: Vec<usize>,
}

pub fn invocations<'a>(ast: &'a Ast<'a>) -> Invocations {
    let mut reader = InvocationReader {
        output: Invocations {
            records: Vec::new(),
            offsets: Vec::new(),
        },
        helpers: HashMap::new(),
        active_helpers: HashSet::new(),
    };
    let source = String::from_utf8_lossy(ast.source);
    if source.contains("guessFactoryNamesUsing") || source.contains("useNamespace") {
        for call in ast
            .roots()
            .into_iter()
            .flat_map(|n| n.descendants())
            .filter(|n| n.kind() == "expression.static-call")
        {
            if call.child("class").is_some_and(|n| {
                n.is_name() && n.resolved() == "Illuminate\\Database\\Eloquent\\Factories\\Factory"
            }) && call.child("name").is_some_and(|n| {
                n.kind() == "identifier"
                    && ["guessFactoryNamesUsing", "useNamespace"].contains(&n.name())
            }) {
                let mut data = invocation(call, "factory-config", None);
                if call.named("name") == "useNamespace" {
                    if let Some(value) = call
                        .list("args")
                        .first()
                        .and_then(|n| n.child("value"))
                        .filter(|n| n.kind() == "scalar.string")
                    {
                        data["uri"] = value.field("value").clone();
                    }
                }
                reader.push(call, data);
            }
        }
    }
    if is_test(ast.path) {
        reader.walk(&ast.roots(), None, false, &mut Variables::new());
    }
    reader.output
}

fn invocation(node: N<'_>, kind: &str, context: Option<&str>) -> Value {
    json!({"line":node.line(),"kind":kind,"context":context,"verb":null,"uri":null,"route":null,"parameters":[],"model":null,"reason":null,"dispatch":null,"command":null})
}

struct InvocationReader<'a> {
    output: Invocations,
    helpers: HashMap<(String, String), N<'a>>,
    active_helpers: HashSet<String>,
}
impl<'a> InvocationReader<'a> {
    fn push(&mut self, node: N<'_>, value: Value) {
        self.output.offsets.push(node.start());
        self.output.records.push(value);
    }
    fn walk(&mut self, nodes: &[N<'a>], context: Option<&str>, active: bool, vars: &mut Variables) {
        for &node in nodes {
            if node.kind() == "statement.class" {
                let name = node.declared();
                for method in node
                    .list("stmts")
                    .into_iter()
                    .filter(|n| n.kind() == "statement.class-method")
                {
                    self.helpers.insert(
                        (
                            name.clone().unwrap_or_default(),
                            method.named("name").to_ascii_lowercase(),
                        ),
                        method,
                    );
                }
                self.walk(
                    &node.list("stmts"),
                    name.as_deref(),
                    false,
                    &mut Variables::new(),
                );
                continue;
            }
            if node.kind() == "statement.class-method" {
                let name = node.named("name").to_ascii_lowercase();
                let mut test = name.starts_with("test")
                    || ["setup", "setupbeforeclass"].contains(&name.as_str())
                    || doc_comment(node).contains("@test");
                for attribute in node
                    .list("attrGroups")
                    .into_iter()
                    .flat_map(|n| n.list("attrs"))
                {
                    test |= attribute
                        .child("name")
                        .is_some_and(|n| n.resolved() == "PHPUnit\\Framework\\Attributes\\Test");
                }
                if test {
                    self.walk(&node.list("stmts"), context, true, &mut Variables::new());
                }
                continue;
            }
            if matches!(
                node.kind(),
                "expression.closure" | "expression.arrow-function" | "statement.function"
            ) {
                continue;
            }
            if node.kind() == "expression.func-call"
                && node.child("name").is_some_and(|n| {
                    n.is_name()
                        && ["it", "test", "beforeeach", "beforeall", "describe"]
                            .contains(&n.resolved().to_ascii_lowercase().as_str())
                })
            {
                for callback in node
                    .list("args")
                    .iter()
                    .filter_map(|n| n.child("value"))
                    .filter(|n| {
                        matches!(n.kind(), "expression.closure" | "expression.arrow-function")
                    })
                {
                    let stmts = if callback.kind() == "expression.closure" {
                        callback.list("stmts")
                    } else {
                        callback.child("expr").into_iter().collect()
                    };
                    self.walk(&stmts, Some("@pest"), true, &mut Variables::new());
                }
                continue;
            }
            if active && node.kind() == "expression.assign" {
                if let Some(var) = node
                    .child("var")
                    .filter(|n| n.kind() == "expression.variable" && n.field("name").is_string())
                {
                    vars.insert(var.name().to_owned(), value(node.child("expr"), vars));
                }
            }
            if active && node.kind() == "expression.static-call" && !node.first_class() {
                if node.child("class").is_some_and(|n| {
                    n.is_name() && n.resolved() == "Illuminate\\Support\\Facades\\Artisan"
                }) && node.named("name").eq_ignore_ascii_case("call")
                {
                    let command = value(
                        node.list("args").first().and_then(|n| n.child("value")),
                        vars,
                    );
                    let mut data = invocation(node, "artisan", context);
                    data["reason"] = if command.is_none() {
                        json!("Dynamic Artisan command selector.")
                    } else {
                        Value::Null
                    };
                    data["dispatch"] = json!("call");
                    data["command"] = json!(command);
                    self.push(node, data);
                }
                if node.child("name").is_some_and(|n| {
                    n.kind() == "identifier" && n.name().eq_ignore_ascii_case("factory")
                }) {
                    let model = node
                        .child("class")
                        .filter(|n| n.is_name())
                        .map(|n| n.resolved());
                    let mut data = invocation(node, "factory", context);
                    data["model"] = json!(model);
                    data["reason"] = if model.is_none() {
                        json!("Dynamic factory receiver.")
                    } else {
                        Value::Null
                    };
                    self.push(node, data);
                }
            }
            if active
                && matches!(
                    node.kind(),
                    "expression.method-call" | "expression.func-call"
                )
                && !node.first_class()
            {
                let mut method = None;
                if node.kind() == "expression.method-call"
                    && node.child("var").is_some_and(test_receiver)
                {
                    if node.child("name").is_some_and(|n| n.kind() == "identifier") {
                        method = Some(node.named("name").to_ascii_lowercase());
                    } else {
                        let mut data = invocation(node, "http", context);
                        data["reason"] =
                            json!("Dynamic test method; HTTP dispatch cannot be determined.");
                        self.push(node, data);
                    }
                } else if node.kind() == "expression.func-call" {
                    if let Some(name) = node.child("name").filter(|n| n.is_name()) {
                        method = name
                            .resolved()
                            .to_ascii_lowercase()
                            .strip_prefix("pest\\laravel\\")
                            .map(str::to_owned);
                    }
                }
                if let (Some(context), Some(name)) = (context, method.as_deref()) {
                    if let Some(&helper) = self.helpers.get(&(context.to_owned(), name.to_owned()))
                    {
                        let key = format!("{context}::{name}");
                        if !self.active_helpers.contains(&key) && self.active_helpers.len() < 12 {
                            self.active_helpers.insert(key.clone());
                            let mut locals = Variables::new();
                            let args = node.list("args");
                            for (index, param) in helper.list("params").iter().enumerate() {
                                if let Some(var) =
                                    param.child("var").filter(|n| n.field("name").is_string())
                                {
                                    locals.insert(
                                        var.name().to_owned(),
                                        value(args.get(index).and_then(|n| n.child("value")), vars),
                                    );
                                }
                            }
                            self.walk(&helper.list("stmts"), Some(context), true, &mut locals);
                            self.active_helpers.remove(&key);
                        }
                        method = None;
                    }
                }
                if method.as_deref() == Some("artisan") {
                    let command = value(
                        node.list("args").first().and_then(|n| n.child("value")),
                        vars,
                    );
                    let mut data = invocation(node, "artisan", context);
                    data["reason"] = if command.is_none() {
                        json!("Dynamic Artisan command selector.")
                    } else {
                        Value::Null
                    };
                    data["dispatch"] = json!("artisan");
                    data["command"] = json!(command);
                    self.push(node, data);
                }
                if let Some(method) = method.filter(|m| {
                    [
                        "get",
                        "post",
                        "put",
                        "patch",
                        "delete",
                        "head",
                        "options",
                        "getjson",
                        "postjson",
                        "putjson",
                        "patchjson",
                        "deletejson",
                        "headjson",
                        "optionsjson",
                        "json",
                        "call",
                    ]
                    .contains(&m.as_str())
                }) {
                    let args = node.list("args");
                    let generic = ["json", "call"].contains(&method.as_str());
                    let verb = if generic {
                        value(args.first().and_then(|n| n.child("value")), vars)
                    } else {
                        Some(method.strip_suffix("json").unwrap_or(&method).to_owned())
                    };
                    let uri_node = args
                        .get(if generic { 1 } else { 0 })
                        .and_then(|n| n.child("value"));
                    let uri = value(uri_node, vars);
                    let mut route = None;
                    let mut parameters = Map::new();
                    if let Some(call) = uri_node.filter(|n| {
                        n.kind() == "expression.func-call"
                            && n.child("name")
                                .is_some_and(|n| n.is_name() && n.resolved() == "route")
                    }) {
                        let args = call.list("args");
                        route = value(args.first().and_then(|n| n.child("value")), vars);
                        if let Some(params) = args.get(1).and_then(|n| n.child("value")) {
                            if params.kind() == "expression.array" {
                                for (index, item) in
                                    params.field("items").as_array().unwrap().iter().enumerate()
                                {
                                    if let Some(item) = params.ast.node(item) {
                                        let key = value(item.child("key"), vars)
                                            .unwrap_or_else(|| index.to_string());
                                        parameters
                                            .insert(key, json!(value(item.child("value"), vars)));
                                    }
                                }
                            } else {
                                parameters.insert("0".to_owned(), json!(value(Some(params), vars)));
                            }
                        }
                    }
                    let mut data = invocation(node, "http", context);
                    data["verb"] = json!(verb.as_ref().map(|s| s.to_ascii_uppercase()));
                    data["uri"] = json!(uri);
                    data["route"] = json!(route);
                    data["parameters"] = php_array(parameters);
                    data["reason"] = if verb.is_none() || uri.is_none() && route.is_none() {
                        json!("Dynamic HTTP method or address.")
                    } else {
                        Value::Null
                    };
                    data["dispatch"] = json!(method);
                    self.push(node, data);
                }
            }
            let mut branch = vars.clone();
            let mut changed = HashSet::new();
            for (_, children) in node.groups() {
                if node.kind() == "statement.if" {
                    branch = vars.clone();
                }
                self.walk(&children, context, active, &mut branch);
                for (name, value) in &branch {
                    if vars.get(name) != Some(value) {
                        changed.insert(name.clone());
                    }
                }
            }
            if matches!(
                node.kind(),
                "statement.if" | "statement.foreach" | "statement.while" | "statement.switch"
            ) {
                for name in changed {
                    vars.insert(name, None);
                }
            } else {
                *vars = branch;
            }
        }
    }
}

fn php_array(map: Map<String, Value>) -> Value {
    if map.keys().enumerate().all(|(i, k)| k == &i.to_string()) {
        json!(map.into_iter().map(|(_, v)| v).collect::<Vec<_>>())
    } else {
        Value::Object(map)
    }
}
fn test_receiver(node: N<'_>) -> bool {
    node.kind() == "expression.variable" && node.name() == "this"
        || node.kind() == "expression.method-call"
            && node.child("name").is_some_and(|n| {
                n.kind() == "identifier"
                    && [
                        "actingas",
                        "be",
                        "withheaders",
                        "withheader",
                        "withservervariables",
                        "withsession",
                        "withoutmiddleware",
                        "withmiddleware",
                        "withoutexceptionhandling",
                        "withcookie",
                        "withcookies",
                    ]
                    .contains(&n.name().to_ascii_lowercase().as_str())
            })
            && node.child("var").is_some_and(test_receiver)
}
fn doc_comment(node: N<'_>) -> String {
    node.data()["comments"]
        .as_array()
        .unwrap()
        .iter()
        .rev()
        .find(|c| c["documentation"] == true)
        .and_then(|c| c["text"].as_str())
        .unwrap_or("")
        .to_owned()
}
fn value(node: Option<N<'_>>, vars: &Variables) -> Option<String> {
    let node = node?;
    match node.kind() {
        "scalar.string" => return node.field("value").as_str().map(str::to_owned),
        "scalar.int" => return Some(node.field("value").to_string()),
        "expression.variable" => return vars.get(node.name()).cloned().flatten(),
        "expression.property-fetch" if node.named("name") == "id" => {
            return Some(SYMBOLIC.to_owned());
        }
        "expression.cast.int" => {
            let value = value(node.child("expr"), vars);
            return Some(
                if let Some(value) = value.filter(|s| !s.contains(SYMBOLIC)) {
                    php_int(&value).to_string()
                } else {
                    SYMBOLIC.to_owned()
                },
            );
        }
        "expression.binary-op.concat" => {
            return Some(format!(
                "{}{}",
                value(node.child("left"), vars)?,
                value(node.child("right"), vars)?
            ));
        }
        "scalar.interpolated-string" => {
            let mut result = String::new();
            for part in node.list("parts") {
                result.push_str(&if part.kind() == "interpolated-string-part" {
                    part.field("value").as_str()?.to_owned()
                } else {
                    value(Some(part), vars)?
                });
            }
            return Some(result);
        }
        _ => {}
    }
    if node.kind() == "expression.func-call" && !node.first_class() {
        if let Some(name) = node
            .child("name")
            .filter(|n| n.is_name() && n.data()["attributes"]["namespacedName"].is_null())
        {
            let name = name.resolved().to_ascii_lowercase();
            let args = node.list("args");
            if ["rawurlencode", "urlencode"].contains(&name.as_str())
                && args.len() == 1
                && !args[0].boolean("unpack")
            {
                let Some(value) =
                    value(args[0].child("value"), vars).filter(|s| !s.contains(SYMBOLIC))
                else {
                    return Some(SYMBOLIC.to_owned());
                };
                let mut out = String::new();
                for byte in value.bytes() {
                    if byte.is_ascii_alphanumeric()
                        || [b'-', b'_', b'.'].contains(&byte)
                        || name == "rawurlencode" && byte == b'~'
                    {
                        out.push(byte as char);
                    } else if name == "urlencode" && byte == b' ' {
                        out.push('+');
                    } else {
                        out.push_str(&format!("%{byte:02X}"));
                    }
                }
                return Some(out);
            }
        }
    }
    None
}
fn php_int(value: &str) -> i64 {
    let value = value.trim_start_matches([' ', '\t', '\n', '\r', '\u{000b}', '\u{000c}']);
    let mut end = 0;
    let bytes = value.as_bytes();
    if bytes.first().is_some_and(|b| [b'+', b'-'].contains(b)) {
        end = 1;
    }
    let mut digit = false;
    while end < bytes.len() && bytes[end].is_ascii_digit() {
        digit = true;
        end += 1;
    }
    if end < bytes.len() && bytes[end] == b'.' {
        end += 1;
        while end < bytes.len() && bytes[end].is_ascii_digit() {
            digit = true;
            end += 1;
        }
    }
    if !digit {
        return 0;
    }
    if end < bytes.len() && [b'e', b'E'].contains(&bytes[end]) {
        let mut exponent = end + 1;
        if exponent < bytes.len() && [b'+', b'-'].contains(&bytes[exponent]) {
            exponent += 1;
        }
        let start = exponent;
        while exponent < bytes.len() && bytes[exponent].is_ascii_digit() {
            exponent += 1;
        }
        if exponent > start {
            end = exponent;
        }
    }
    value[..end].parse::<i64>().unwrap_or_else(|_| {
        let number = value[..end].parse::<f64>().unwrap_or(0.0);
        if number.is_finite() { number as i64 } else { 0 }
    })
}

pub fn literal(node: Option<N<'_>>, depth: usize) -> Option<String> {
    let node = node?;
    if depth > 16 {
        return None;
    }
    if node.kind() == "scalar.string" {
        return node
            .field("value")
            .as_str()
            .filter(|s| s.len() <= 500)
            .map(str::to_owned);
    }
    if node.kind() == "expression.binary-op.concat" {
        let value = format!(
            "{}{}",
            literal(node.child("left"), depth + 1)?,
            literal(node.child("right"), depth + 1)?
        );
        if value.len() <= 500 {
            return Some(value);
        }
    }
    None
}

pub fn catalog(
    ast: &Ast<'_>,
    records: &mut Records,
    invocations: &Invocations,
) -> (Vec<Value>, Vec<Value>, Vec<Value>) {
    let es = records.elements.len();
    let rs = records.relations.len();
    let ds = records.diagnostics.len();
    if !is_test(ast.path) {
        return (Vec::new(), Vec::new(), Vec::new());
    }
    let declarations = records.elements.clone();
    let by_offset: HashMap<_, _> = declarations
        .iter()
        .map(|e| (e["offset"].as_u64().unwrap() as usize, e.clone()))
        .collect();
    let all: Vec<_> = ast
        .roots()
        .into_iter()
        .flat_map(|n| n.descendants())
        .collect();
    for method in all
        .iter()
        .copied()
        .filter(|n| n.kind() == "statement.class-method")
    {
        let Some(element) = by_offset.get(&method.start()) else {
            continue;
        };
        let mut test = method
            .named("name")
            .to_ascii_lowercase()
            .starts_with("test")
            || doc_comment(method).contains("@test");
        let mut hooks = Vec::new();
        let mut providers = Vec::new();
        for attr in method
            .list("attrGroups")
            .into_iter()
            .flat_map(|n| n.list("attrs"))
        {
            let name = attr.child("name").unwrap().resolved();
            test |= name == "PHPUnit\\Framework\\Attributes\\Test";
            if [
                "PHPUnit\\Framework\\Attributes\\DataProvider",
                "PHPUnit\\Framework\\Attributes\\DataProviderExternal",
            ]
            .contains(&name.as_str())
            {
                if let Some(provider) = provider(attr).filter(|_| providers.len() < 128) {
                    providers.push(provider);
                } else {
                    records.diagnostic("test_analysis","PHPUnit data provider selector is dynamic, invalid or exceeds its source budget.",attr.line(),element["id"].clone());
                }
            }
            for hook in [
                "Before",
                "BeforeClass",
                "After",
                "AfterClass",
                "PreCondition",
                "PostCondition",
            ] {
                if name != format!("PHPUnit\\Framework\\Attributes\\{hook}") {
                    continue;
                }
                let args = attr.list("args");
                if args.len() > 1
                    || args.first().is_some_and(|n| {
                        n.boolean("unpack")
                            || n.boolean("byRef")
                            || n.child("name").is_some() && n.named("name") != "priority"
                    })
                {
                    records.diagnostic(
                        "test_analysis",
                        "PHPUnit hook attribute has incompatible arguments.",
                        attr.line(),
                        element["id"].clone(),
                    );
                    continue;
                }
                let priority = if let Some(value) = args.first().and_then(|n| n.child("value")) {
                    if value.kind() == "scalar.int" {
                        value.field("value").clone()
                    } else if value.kind() == "expression.unary-minus"
                        && value
                            .child("expr")
                            .is_some_and(|n| n.kind() == "scalar.int")
                    {
                        json!(
                            -value
                                .child("expr")
                                .unwrap()
                                .field("value")
                                .as_i64()
                                .unwrap()
                        )
                    } else {
                        Value::Null
                    }
                } else {
                    json!(0)
                };
                if hooks.iter().any(|h: &Value| h["api"] == hook) {
                    records.diagnostic(
                        "test_analysis",
                        "Repeated PHPUnit hook attributes require inspection.",
                        attr.line(),
                        element["id"].clone(),
                    );
                    continue;
                }
                hooks.push(json!({"api":hook,"priority":priority}));
            }
        }
        let public_test = test && !method.flag(4) && !method.flag(2) && !method.flag(16);
        if public_test || !hooks.is_empty() || !providers.is_empty() {
            let mut updated = element.clone();
            if public_test {
                updated["metadata"]["test_declaration_candidate"] = json!(true);
            }
            updated["metadata"]["phpunit_hooks"] = json!(hooks);
            updated["metadata"]["phpunit_providers"] = json!(providers);
            records.elements.push(updated);
        }
    }
    for call in all
        .iter()
        .copied()
        .filter(|n| n.kind() == "expression.func-call")
    {
        let Some(function) = call
            .child("name")
            .filter(|n| n.is_name())
            .filter(|_| !call.first_class())
            .map(|n| n.resolved().to_ascii_lowercase())
        else {
            continue;
        };
        if ![
            "it",
            "test",
            "beforeeach",
            "beforeall",
            "aftereach",
            "afterall",
            "dataset",
        ]
        .contains(&function.as_str())
        {
            continue;
        }
        let mut callback = None;
        for value in call
            .list("args")
            .iter()
            .filter_map(|n| n.child("value"))
            .filter(|n| matches!(n.kind(), "expression.closure" | "expression.arrow-function"))
        {
            callback = by_offset.get(&value.start());
        }
        let kind = if function == "dataset" {
            "dataset"
        } else if function.starts_with("before") || function.starts_with("after") {
            "test-hook"
        } else {
            "pest-test"
        };
        let label = if kind == "test-hook" {
            Some(function.clone())
        } else {
            literal(call.list("args").first().and_then(|n| n.child("value")), 0)
        };
        let Some(label) = label
            .filter(|s| !s.is_empty())
            .filter(|_| kind == "dataset" || callback.is_some())
        else {
            records.diagnostic(
                "test_analysis",
                "Test declaration is dynamic or has no source body.",
                call.line(),
                Value::Null,
            );
            continue;
        };
        let scope = owner(
            &declarations,
            call.line(),
            call.end_line(),
            Some(call.start()),
        );
        let id = records.element(
            ast,
            kind,
            &label,
            call.start(),
            call.line(),
            call.end_line(),
            Value::Null,
            json!({"test_api":function,"test_executed":false,"registration_scope":scope}),
        );
        if kind != "dataset" {
            if let Some(callback) = callback {
                records.relation(
                    id,
                    callback["id"].clone(),
                    "test-body",
                    call.line(),
                    call.end_line(),
                    "conditional",
                    json!({"execution_proven":false,"test_executed":false}),
                );
            }
        }
    }
    for call in all.iter().copied().filter(|n| {
        n.kind() == "expression.method-call"
            && n.child("name")
                .is_some_and(|n| n.kind() == "identifier" && n.name().eq_ignore_ascii_case("with"))
            && !n.first_class()
    }) {
        let mut root = call.child("var").unwrap();
        while root.kind() == "expression.method-call" {
            root = root.child("var").unwrap();
        }
        if root.kind() != "expression.func-call" {
            continue;
        }
        for index in es..records.elements.len() {
            if records.elements[index]["kind"] != "pest-test"
                || records.elements[index]["offset"] != json!(root.start())
            {
                continue;
            }
            let mut datasets = records.elements[index]["metadata"]["datasets"]
                .as_array()
                .cloned()
                .unwrap_or_default();
            for argument in call.list("args") {
                let name = if argument.boolean("unpack") {
                    None
                } else {
                    literal(argument.child("value"), 0)
                };
                if let Some(name) = name
                    .filter(|s| !s.is_empty())
                    .filter(|_| datasets.len() < 128)
                {
                    datasets.push(json!(name));
                } else {
                    records.diagnostic(
                        "test_analysis",
                        "Inline/dynamic dataset values are omitted; dataset coverage is partial.",
                        call.line(),
                        records.elements[index]["id"].clone(),
                    );
                }
            }
            let mut unique = Vec::new();
            for dataset in datasets {
                if !unique.contains(&dataset) {
                    unique.push(dataset);
                }
            }
            records.elements[index]["metadata"]["datasets"] = json!(unique);
        }
    }
    for (ordinal, invocation) in invocations.records.iter().enumerate() {
        let kind = invocation["kind"].as_str().unwrap();
        if !["http", "factory"].contains(&kind) {
            continue;
        }
        let mut data = invocation.clone();
        if let Some(uri) = data["uri"].as_str() {
            let mut uri = uri.split(['?', '#']).next().unwrap().to_owned();
            let prefix = if uri.to_ascii_lowercase().starts_with("https://") {
                Some(8)
            } else if uri.to_ascii_lowercase().starts_with("http://") {
                Some(7)
            } else {
                None
            };
            if let Some(prefix) = prefix {
                let authority_end = uri[prefix..]
                    .find('/')
                    .map(|n| n + prefix)
                    .unwrap_or(uri.len());
                if let Some(at) = uri[prefix..authority_end].rfind('@') {
                    uri = format!("{}{}", &uri[..prefix], &uri[prefix + at + 1..]);
                }
            }
            if uri.len() > 500 {
                data["uri"] = Value::Null;
                data["reason"] = json!("HTTP selector exceeds catalog budget.");
            } else {
                data["uri"] = json!(uri);
            }
        }
        if let Some(values) = data["parameters"].as_array_mut() {
            for value in values {
                if value.as_str().is_some_and(|s| s.len() > 256) {
                    *value = Value::Null;
                }
            }
        } else if let Some(values) = data["parameters"].as_object_mut() {
            for value in values.values_mut() {
                if value.as_str().is_some_and(|s| s.len() > 256) {
                    *value = Value::Null;
                }
            }
        }
        let line = invocation["line"].as_u64().unwrap() as usize;
        let owner = owner(
            &declarations,
            line,
            line,
            invocations.offsets.get(ordinal).copied(),
        );
        let id = records.identity(json!([
            "element",
            "test-invocation",
            format!("{kind}#{ordinal}"),
            line
        ]));
        records.elements.push(json!({"id":id,"name":kind,"kind":"test-invocation","line":line,"end_line":line,"offset":0,"parent":owner,"roles":[],"metadata":{"test_invocation":data,"execution_proven":false}}));
    }
    pest_hooks(ast, records, &declarations, &by_offset, &all);
    (
        records.elements.split_off(es),
        records.relations.split_off(rs),
        records.diagnostics.split_off(ds),
    )
}

fn owner(elements: &[Value], line: usize, end_line: usize, offset: Option<usize>) -> Value {
    let mut owner = Value::Null;
    let mut size = u64::MAX;
    for element in elements {
        let start = element["offset"].as_u64().unwrap();
        let end = element["metadata"]["end_offset"].as_u64().unwrap_or(0);
        if !["method", "closure"].contains(&element["kind"].as_str().unwrap())
            || element["line"].as_u64().unwrap() > line as u64
            || element["end_line"].as_u64().unwrap() < end_line as u64
            || offset.is_some_and(|offset| start > offset as u64 || end < offset as u64)
        {
            continue;
        }
        let span = end.saturating_sub(start);
        if span < size {
            owner = element["id"].clone();
            size = span;
        }
    }
    owner
}

fn identifier(value: &str, qualified: bool) -> bool {
    let part = |name: &str| {
        !name.is_empty()
            && name
                .bytes()
                .enumerate()
                .all(|(i, b)| b.is_ascii_alphabetic() || b == b'_' || i > 0 && b.is_ascii_digit())
    };
    if qualified {
        value.split('\\').all(part)
    } else {
        part(value)
    }
}
fn provider(attr: N<'_>) -> Option<Value> {
    let external = attr.child("name")?.resolved().ends_with("External");
    let names = if external {
        vec!["className", "methodName", "validateArgumentCount"]
    } else {
        vec!["methodName", "validateArgumentCount"]
    };
    let mut args = HashMap::new();
    let mut named = false;
    for (position, arg) in attr.list("args").iter().enumerate() {
        let name = arg
            .child("name")
            .map(|n| n.name())
            .unwrap_or_else(|| names.get(position).copied().unwrap_or(""));
        if arg.boolean("unpack")
            || arg.boolean("byRef")
            || !names.contains(&name)
            || args.contains_key(name)
            || named && arg.child("name").is_none()
        {
            return None;
        }
        named |= arg.child("name").is_some();
        args.insert(name, arg.child("value"));
    }
    let method = literal(args.get("methodName").copied().flatten(), 0)
        .filter(|s| s.len() <= 500 && identifier(s, false))?;
    let mut class = Value::Null;
    if external {
        let value = args.get("className").copied().flatten();
        let class_name = if let Some(node) = value
            .filter(|n| {
                n.kind() == "expression.class-const-fetch"
                    && n.named("name").eq_ignore_ascii_case("class")
            })
            .and_then(|n| n.child("class"))
            .filter(|n| {
                n.is_name()
                    && !["self", "static", "parent"]
                        .contains(&n.name().to_ascii_lowercase().as_str())
            }) {
            Some(node.resolved())
        } else {
            literal(value, 0)
        }?;
        if class_name.len() > 1000 || !identifier(class_name.trim_start_matches('\\'), true) {
            return None;
        }
        class = json!(class_name.trim_start_matches('\\'));
    }
    let flag = args.get("validateArgumentCount").copied().flatten();
    let validate = match flag {
        None => json!(true),
        Some(n)
            if n.kind() == "expression.const-fetch"
                && ["true", "false"].contains(&n.named("name").to_ascii_lowercase().as_str()) =>
        {
            json!(n.named("name").eq_ignore_ascii_case("true"))
        }
        _ => Value::Null,
    };
    Some(
        json!({"api":if external{"DataProviderExternal"}else{"DataProvider"},"class":class,"method":method,"validate_argument_count":validate}),
    )
}

fn pest_hooks(
    ast: &Ast<'_>,
    records: &mut Records,
    _declarations: &[Value],
    owners: &HashMap<usize, Value>,
    all: &[N<'_>],
) {
    let mut chains: Vec<(usize, N<'_>)> = Vec::new();
    for call in all
        .iter()
        .copied()
        .filter(|n| n.kind() == "expression.method-call")
    {
        if let Some((_, current)) = chains
            .iter_mut()
            .find(|(offset, _)| *offset == call.start())
        {
            if call.end() > current.end() {
                *current = call;
            }
        } else {
            chains.push((call.start(), call));
        }
    }
    for (_, outer) in chains.iter().take(4096) {
        let mut root = *outer;
        let mut calls = Vec::new();
        while root.kind() == "expression.method-call" && calls.len() < 128 {
            calls.push(root);
            root = root.child("var").unwrap();
        }
        if root.kind() == "expression.method-call" {
            records.diagnostic(
                "catalog_limit",
                "Pest configuration chain exceeded its source depth budget.",
                outer.line(),
                Value::Null,
            );
            records.diagnostics.last_mut().unwrap()["limit_reason"] = json!("structure");
            continue;
        }
        let Some(api) = root
            .child("name")
            .filter(|_| root.kind() == "expression.func-call" && !root.first_class())
            .filter(|n| n.is_name())
            .map(|n| n.resolved().to_ascii_lowercase())
            .filter(|s| ["pest", "uses"].contains(&s.as_str()))
        else {
            continue;
        };
        let mut valid = api != "pest" || root.list("args").is_empty();
        if api == "uses" {
            for argument in root.list("args") {
                valid &= string_argument(argument, true);
            }
        }
        let dirname = ast.path.rsplit_once('/').map_or(".", |(s, _)| s);
        let basename = ast.path.rsplit('/').next().unwrap();
        let mut targets = vec![if api == "pest" && basename == "Pest.php" {
            dirname.to_owned()
        } else {
            ast.path.to_owned()
        }];
        let mut hooks: Vec<(String, N<'_>, Option<Value>)> = Vec::new();
        for (position, call) in calls.into_iter().rev().enumerate() {
            let method = call
                .child("name")
                .filter(|n| n.kind() == "identifier")
                .map(|n| n.name().to_ascii_lowercase())
                .unwrap_or_default();
            if call.first_class()
                || ![
                    "in",
                    "extend",
                    "extends",
                    "use",
                    "uses",
                    "group",
                    "beforeeach",
                    "beforeall",
                    "aftereach",
                    "afterall",
                ]
                .contains(&method.as_str())
            {
                valid = false;
                continue;
            }
            if ["extends", "uses"].contains(&method.as_str()) && (api != "pest" || position != 0) {
                valid = false;
            }
            if ["extend", "extends", "use", "uses", "group"].contains(&method.as_str()) {
                for argument in call.list("args") {
                    valid &= string_argument(argument, method != "group");
                }
            }
            if method == "in" {
                targets.clear();
                for argument in call.list("args") {
                    let target = if !argument.boolean("unpack")
                        && !argument.boolean("byRef")
                        && argument.child("name").is_none()
                    {
                        literal(argument.child("value"), 0)
                    } else {
                        None
                    };
                    let Some(mut target) = target.filter(|s| {
                        !s.is_empty()
                            && s.len() <= 500
                            && !s.starts_with('/')
                            && !s.contains('\\')
                            && !s.split('/').any(|s| s == "..")
                            && !(s.len() >= 2
                                && s.as_bytes()[0].is_ascii_alphabetic()
                                && s.as_bytes()[1] == b':')
                    }) else {
                        valid = false;
                        continue;
                    };
                    while target.starts_with("./") {
                        target = target[2..].to_owned();
                    }
                    if target == "." {
                        target.clear();
                    }
                    targets.push(
                        format!("{dirname}/{target}")
                            .trim_end_matches('/')
                            .to_owned(),
                    );
                }
            } else if ["beforeeach", "beforeall", "aftereach", "afterall"]
                .contains(&method.as_str())
            {
                let args = call.list("args");
                let callback = args
                    .first()
                    .filter(|arg| {
                        args.len() == 1
                            && !arg.boolean("unpack")
                            && !arg.boolean("byRef")
                            && (arg.child("name").is_none() || arg.named("name") == "hook")
                    })
                    .and_then(|arg| arg.child("value"))
                    .filter(|n| {
                        matches!(n.kind(), "expression.closure" | "expression.arrow-function")
                    })
                    .and_then(|n| owners.get(&n.start()))
                    .cloned();
                if let Some(existing) = hooks.iter_mut().find(|(name, _, _)| name == &method) {
                    *existing = (method, call, callback);
                } else {
                    hooks.push((method, call, callback));
                }
            }
        }
        for (method, call, callback) in hooks {
            let resolved = valid && callback.is_some() && targets.len() <= 128;
            let id = records.identity(json!([
                "element",
                "test-hook",
                format!("{api}:{method}"),
                call.start()
            ]));
            records.elements.push(json!({"id":id,"name":method,"kind":"test-hook","line":call.line(),"end_line":call.end_line(),"offset":call.start(),"parent":null,"roles":[],"metadata":{"test_api":method,"test_executed":false,"registration_scope":null,"registration_api":api,"test_targets":targets.iter().take(128).collect::<Vec<_>>(),"targets_resolved":resolved}}));
            if !resolved {
                records.diagnostic(
                    "test_analysis",
                    "Pest configuration hook callback or target selection is unresolved.",
                    call.line(),
                    id.clone(),
                );
            }
            if let Some(callback) = callback {
                records.relation(
                    id,
                    callback["id"].clone(),
                    "test-body",
                    call.line(),
                    call.end_line(),
                    "conditional",
                    json!({"execution_proven":false,"test_executed":false}),
                );
            }
        }
    }
    if chains.len() > 4096 {
        records.diagnostic(
            "catalog_limit",
            "Pest configuration chains exceeded their source budget.",
            1,
            Value::Null,
        );
        records.diagnostics.last_mut().unwrap()["limit_reason"] = json!("structure");
    }
}
fn string_argument(arg: N<'_>, allow_class: bool) -> bool {
    !arg.boolean("unpack")
        && !arg.boolean("byRef")
        && arg.child("name").is_none()
        && arg.child("value").is_some_and(|n| {
            n.kind() == "scalar.string"
                || allow_class
                    && n.kind() == "expression.class-const-fetch"
                    && n.child("class").is_some_and(|n| n.is_name())
                    && n.child("name").is_some_and(|n| {
                        n.kind() == "identifier" && n.name().eq_ignore_ascii_case("class")
                    })
        })
}

#[cfg(test)]
mod tests {
    use super::*;
    use mago_allocator::LocalArena;
    use mago_database::file::FileId;
    use mago_names::resolver::NameResolver;
    use mago_syntax::parser::parse_file_content;
    #[test]
    fn test_invocations_and_catalog_match_php_oracle() {
        let fixtures: Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/Analysis/basic-tests.json"
        ))
        .unwrap();
        for fixture in fixtures["cases"].as_array().unwrap() {
            let source = fixture["source"].as_str().unwrap();
            let path = fixture["path"].as_str().unwrap();
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
            let invocations = invocations(&ast);
            let expanded = crate::basic::tests::expanded;
            assert_eq!(
                expanded(&json!(invocations.records), &[]),
                expanded(&fixture["invocations"], &[]),
                "{} invocations",
                fixture["id"]
            );
            assert_eq!(
                json!(invocations.offsets),
                fixture["offsets"],
                "{} offsets",
                fixture["id"]
            );
            let mut records = crate::basic::declarations(&ast);
            let (elements, relations, diagnostics) = catalog(&ast, &mut records, &invocations);
            let actual = json!({"v":3,"elements":elements,"relations":relations,"diagnostics":diagnostics,"descriptor_receivers":[]});
            assert_eq!(
                expanded(&actual, &records.identities),
                expanded(&fixture["expected"], &[]),
                "{} catalog",
                fixture["id"]
            );
        }
    }
}
