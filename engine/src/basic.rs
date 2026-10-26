//! Full local Basic extraction on private normalized syntax. No syntax leaves
//! this module's response. The input IR is released after each source request.
use serde_json::{Value, json};
use std::collections::HashMap;
use std::sync::OnceLock;

pub struct Ast<'a> {
    pub syntax: &'a Value,
    pub path: &'a str,
    pub source: &'a [u8],
}

#[derive(Clone, Copy)]
pub struct N<'a> {
    pub ast: &'a Ast<'a>,
    pub id: usize,
}

pub(super) fn field_order() -> &'static Value {
    static FIELDS: OnceLock<Value> = OnceLock::new();
    FIELDS.get_or_init(|| serde_json::from_str(include_str!("basic_fields.json")).unwrap())
}

impl<'a> Ast<'a> {
    pub fn node(&'a self, value: &Value) -> Option<N<'a>> {
        value.get("$node")?.as_u64().map(|id| N {
            ast: self,
            id: id as usize,
        })
    }
    pub fn roots(&'a self) -> Vec<N<'a>> {
        self.syntax["roots"]
            .as_array()
            .unwrap()
            .iter()
            .map(|id| N {
                ast: self,
                id: id.as_u64().unwrap() as usize,
            })
            .collect()
    }
    pub fn line(&self, offset: usize) -> usize {
        self.source[..offset]
            .iter()
            .filter(|&&byte| byte == b'\n')
            .count()
            + 1
    }
}

impl<'a> N<'a> {
    pub fn data(self) -> &'a Value {
        &self.ast.syntax["nodes"][self.id]
    }
    pub fn kind(self) -> &'a str {
        self.data()["kind"].as_str().unwrap()
    }
    pub fn field(self, key: &str) -> &'a Value {
        &self.data()["fields"][key]
    }
    pub fn child(self, key: &str) -> Option<Self> {
        self.ast.node(self.field(key))
    }
    pub fn list(self, key: &str) -> Vec<Self> {
        self.field(key)
            .as_array()
            .map(|values| {
                values
                    .iter()
                    .filter_map(|value| self.ast.node(value))
                    .collect()
            })
            .unwrap_or_default()
    }
    pub fn children(self) -> Vec<Self> {
        let mut out = Vec::new();
        for key in field_order()[self.kind()].as_array().unwrap() {
            let value = self.field(key.as_str().unwrap());
            if let Some(node) = self.ast.node(value) {
                out.push(node);
            } else if let Some(values) = value.as_array() {
                out.extend(values.iter().filter_map(|value| self.ast.node(value)));
            }
        }
        out
    }
    pub fn groups(self) -> Vec<(&'static str, Vec<Self>)> {
        field_order()[self.kind()]
            .as_array()
            .unwrap()
            .iter()
            .map(|key| {
                let key = key.as_str().unwrap();
                let value = self.field(key);
                (
                    key,
                    if let Some(node) = self.ast.node(value) {
                        vec![node]
                    } else if let Some(values) = value.as_array() {
                        values.iter().filter_map(|v| self.ast.node(v)).collect()
                    } else {
                        Vec::new()
                    },
                )
            })
            .collect()
    }
    pub fn start(self) -> usize {
        self.data()["start"].as_u64().unwrap() as usize
    }
    pub fn end(self) -> usize {
        self.data()["end"].as_u64().unwrap() as usize
    }
    pub fn line(self) -> usize {
        self.ast.line(self.start())
    }
    pub fn end_line(self) -> usize {
        self.ast
            .line(self.end().saturating_sub(1).max(self.start()))
    }
    pub fn name(self) -> &'a str {
        self.field("name").as_str().unwrap_or("")
    }
    pub fn named(self, key: &str) -> &'a str {
        self.child(key).map_or("", |node| node.name())
    }
    pub fn flag(self, flag: u64) -> bool {
        self.field("flags").as_u64().unwrap_or(0) & flag != 0
    }
    pub fn boolean(self, key: &str) -> bool {
        self.field(key).as_bool().unwrap_or(false)
    }
    pub fn is_name(self) -> bool {
        self.kind() == "name" || self.kind().starts_with("name.")
    }
    pub fn class_like(self) -> bool {
        matches!(
            self.kind(),
            "statement.class" | "statement.interface" | "statement.trait" | "statement.enum"
        )
    }
    pub fn callable(self) -> bool {
        matches!(
            self.kind(),
            "statement.class-method"
                | "statement.function"
                | "expression.closure"
                | "expression.arrow-function"
        )
    }
    pub fn resolved(self) -> String {
        self.ast
            .node(&self.data()["attributes"]["resolvedName"])
            .map_or_else(|| self.name().to_owned(), |n| n.name().to_owned())
    }
    pub fn declared(self) -> Option<String> {
        self.ast
            .node(&self.data()["attributes"]["namespacedName"])
            .map(|n| n.name().to_owned())
    }
    pub fn visibility(self) -> &'static str {
        if self.flag(4) {
            "private"
        } else if self.flag(2) {
            "protected"
        } else {
            "public"
        }
    }
    pub fn first_class(self) -> bool {
        self.list("args")
            .first()
            .is_some_and(|n| n.kind() == "variadic-placeholder")
    }
    pub fn descendants(self) -> Vec<Self> {
        let mut all = Vec::new();
        let mut pending = vec![self];
        while let Some(node) = pending.pop() {
            all.push(node);
            pending.extend(node.children().into_iter().rev());
        }
        all
    }
    pub fn resolved_types(self, class: &str) -> Vec<String> {
        if self.is_name() {
            return vec![match self.name().to_ascii_lowercase().as_str() {
                "self" | "static" => class.to_owned(),
                "parent" => format!("@parent:{class}"),
                _ => self.resolved(),
            }];
        }
        if self.kind() == "nullable-type" {
            return self
                .child("type")
                .map_or_else(Vec::new, |n| n.resolved_types(class));
        }
        if matches!(self.kind(), "union-type" | "intersection-type") {
            let mut types = Vec::new();
            for node in self.list("types") {
                for name in node.resolved_types(class) {
                    if !types.contains(&name) {
                        types.push(name);
                    }
                }
            }
            return types;
        }
        Vec::new()
    }
}

#[derive(Default)]
pub struct Records {
    pub identities: Vec<Value>,
    pub elements: Vec<Value>,
    pub relations: Vec<Value>,
    pub diagnostics: Vec<Value>,
}

impl Records {
    pub fn identity(&mut self, tuple: Value) -> Value {
        let index = self.identities.len();
        self.identities.push(tuple);
        json!({"$identity":index})
    }
    pub fn element(
        &mut self,
        ast: &Ast<'_>,
        kind: &str,
        name: &str,
        start: usize,
        line: usize,
        end_line: usize,
        parent: Value,
        metadata: Value,
    ) -> Value {
        let id = self.identity(json!(["element", kind, name, start]));
        self.elements.push(json!({"id":id,"name":name,"kind":kind,"line":line,"end_line":end_line,"offset":start,"parent":parent,"roles":[],"metadata":metadata}));
        let _ = ast;
        id
    }
    pub fn relation(
        &mut self,
        from: Value,
        to: Value,
        kind: &str,
        line: usize,
        end_line: usize,
        resolution: &str,
        metadata: Value,
    ) {
        self.relations.push(json!({"from":from,"to":to,"kind":kind,"line":line,"end_line":end_line,"resolution":resolution,"metadata":metadata}));
    }
    pub fn diagnostic(&mut self, code: &str, message: &str, line: usize, subject: Value) {
        self.diagnostics
            .push(json!({"code":code,"message":message,"line":line,"subject":subject}));
    }
    pub fn reference(&mut self, owner: &Value, node: N<'_>, kind: &str) {
        self.relation(
            owner.clone(),
            json!(format!("php:{}", node.resolved())),
            kind,
            node.line(),
            node.end_line(),
            "resolved",
            json!({}),
        );
    }
    pub fn type_references(&mut self, owner: &Value, node: Option<N<'_>>) {
        if let Some(node) = node {
            if node.is_name() {
                self.reference(owner, node, "type-reference");
            } else if node.kind() == "nullable-type" {
                self.type_references(owner, node.child("type"));
            } else if matches!(node.kind(), "union-type" | "intersection-type") {
                for member in node.list("types") {
                    self.type_references(owner, Some(member));
                }
            }
        }
    }
}

pub fn declarations(ast: &Ast<'_>) -> Records {
    let mut reader = DeclarationReader {
        records: Records::default(),
        visited: 0,
        limited: false,
        properties: HashMap::new(),
    };
    let file = reader.records.element(
        ast,
        "file",
        ast.path,
        0,
        1,
        ast.line(ast.source.len()),
        Value::Null,
        json!({}),
    );
    for root in ast.roots() {
        reader.visit(root, file.clone(), "");
    }
    reader.records
}

struct DeclarationReader {
    records: Records,
    visited: usize,
    limited: bool,
    properties: HashMap<String, Value>,
}

impl DeclarationReader {
    fn visit(&mut self, node: N<'_>, mut owner: Value, class: &str) {
        if self.limited {
            return;
        }
        self.visited += 1;
        if self.visited > 25000 || self.records.elements.len() >= 10000 {
            self.limited = true;
            self.records.diagnostic(
                "catalog_limit",
                "PHP catalog extraction reached its node or memory budget.",
                node.line(),
                owner,
            );
            return;
        }
        let mut class = class.to_owned();
        let mut kind = None;
        let mut name = String::new();
        let mut metadata = json!({});
        if node.class_like() {
            kind = Some(match node.kind() {
                "statement.interface" => "interface",
                "statement.trait" => "trait",
                "statement.enum" => "enum",
                _ => "class",
            });
            name = node
                .declared()
                .unwrap_or_else(|| format!("(anonymous) {}:{}", node.ast.path, node.start()));
            class = name.clone();
            let uses: Vec<_> = node
                .list("stmts")
                .into_iter()
                .filter(|n| n.kind() == "statement.trait-use")
                .collect();
            let mut rules = Vec::new();
            for use_ in &uses {
                for adaptation in use_.list("adaptations") {
                    let alias = adaptation.kind() == "statement.trait-use-adaptation.alias";
                    let flags = adaptation.field("newModifier").as_u64().unwrap_or(0);
                    let visibility = match flags & 7 {
                        1 => json!("public"),
                        2 => json!("protected"),
                        4 => json!("private"),
                        _ => Value::Null,
                    };
                    rules.push(json!({"trait":adaptation.child("trait").map(|n|n.resolved()),"method":adaptation.named("method").to_ascii_lowercase(),
                    "alias":if alias{adaptation.child("newName").map(|n|json!(n.name().to_ascii_lowercase())).unwrap_or(Value::Null)}else{Value::Null},
                    "excluded":if alias{Vec::new()}else{adaptation.list("insteadof").iter().map(|n|n.resolved()).collect()},"visibility":if alias{visibility}else{Value::Null},"final":alias&&flags&32!=0}));
                }
            }
            metadata = json!({"anonymous":node.child("name").is_none(),"abstract":node.kind()=="statement.class"&&node.flag(16),"final":node.kind()=="statement.class"&&node.flag(32),
                "trait_adaptations":uses.iter().any(|n|!n.list("adaptations").is_empty()),"trait_rules":rules});
            for property in node
                .list("stmts")
                .into_iter()
                .filter(|n| n.kind() == "statement.property")
            {
                for item in property.list("props") {
                    self.properties.insert(format!("{class}::${}",item.named("name")),json!({"types":property.child("type").map(|n|n.resolved_types(&class)).unwrap_or_default(),"visibility":property.visibility(),"static":property.flag(8)}));
                }
            }
        } else if node.kind() == "statement.class-method" {
            kind = Some("method");
            name = format!("{class}::{}", node.named("name"));
            metadata = json!({"static":node.flag(8),"final":node.flag(32),"abstract":node.flag(16),"visibility":node.visibility(),"return_types":self.return_types(node,&class)});
        } else if node.kind() == "statement.function" {
            kind = Some("function");
            name = node
                .declared()
                .unwrap_or_else(|| node.named("name").to_owned());
            metadata = json!({"return_types":self.return_types(node,"")});
        } else if matches!(
            node.kind(),
            "expression.closure" | "expression.arrow-function"
        ) {
            kind = Some("closure");
            name = format!("(closure) {}:{}", node.ast.path, node.start());
            metadata = json!({"arrow":node.kind()=="expression.arrow-function","return_types":self.return_types(node,&class)});
        } else if node.kind() == "property-item" {
            kind = Some("property");
            name = format!("{class}::${}", node.named("name"));
            metadata = self
                .properties
                .get(&name)
                .cloned()
                .unwrap_or_else(|| json!({"types":[]}));
            if node.named("name") == "collects" {
                metadata["resource_collects"] =
                    Self::resource_collects(node.child("default"), &class);
            }
            if node.named("name") == "connector" {
                let type_ = node
                    .child("default")
                    .filter(|n| {
                        n.kind() == "expression.class-const-fetch"
                            && n.named("name").eq_ignore_ascii_case("class")
                    })
                    .and_then(|n| n.child("class"))
                    .filter(|n| {
                        n.is_name()
                            && !["self", "static", "parent"]
                                .contains(&n.name().to_ascii_lowercase().as_str())
                    })
                    .map(|n| n.resolved())
                    .filter(|n| Self::valid_type(n));
                metadata["saloon_connector"] = json!({"resolved":type_.is_some(),"type":type_});
            }
            if node.named("name") == "allowBaseUrlOverride" {
                let name = node
                    .child("default")
                    .filter(|n| n.kind() == "expression.const-fetch")
                    .map(|n| n.named("name").to_ascii_lowercase());
                metadata["saloon_url_override"] = json!({"resolved":name.as_deref().is_some_and(|n|["true","false","null"].contains(&n)),"value":match name.as_deref(){Some("true")=>json!(true),Some("false")=>json!(false),_=>Value::Null}});
            }
        } else if node.kind() == "param"
            && node.field("flags").as_u64().unwrap_or(0) != 0
            && node
                .child("var")
                .is_some_and(|n| n.kind() == "expression.variable" && n.field("name").is_string())
        {
            kind = Some("property");
            name = format!("{class}::${}", node.child("var").unwrap().name());
            metadata = json!({"promoted":true,"visibility":node.visibility(),"static":false,"types":node.child("type").map(|n|n.resolved_types(&class)).unwrap_or_default()});
        } else if node.kind() == "attribute" {
            kind = Some("attribute");
            name = node.child("name").unwrap().resolved();
            if name == "Illuminate\\Routing\\Attributes\\Controllers\\Middleware" {
                metadata["controller_middleware"] = self.middleware(node, &class);
            }
            if name == "Illuminate\\Http\\Resources\\Attributes\\Collects" {
                let args = node.list("args");
                let shape = args.len() == 1
                    && !args[0].boolean("unpack")
                    && (args[0].child("name").is_none() || args[0].named("name") == "class");
                let mut result = if shape {
                    Self::resource_collects(args[0].child("value"), &class)
                } else {
                    json!({"resolved":false,"target":null})
                };
                if result["target"].is_null() {
                    result["resolved"] = json!(false);
                }
                metadata["resource_collects"] = result;
            }
        } else if node.kind() == "statement.enum-case" {
            kind = Some("enum-case");
            name = format!("{class}::{}", node.named("name"));
        }
        if let Some(kind) = kind {
            if node.callable() {
                self.callable_metadata(node, &class, &mut metadata);
            }
            metadata["end_offset"] = json!(node.end().saturating_sub(1).max(node.start()));
            let id = self.records.element(
                node.ast,
                kind,
                &name,
                node.start(),
                node.line(),
                node.end_line(),
                owner.clone(),
                metadata,
            );
            self.records.relation(
                owner,
                id.clone(),
                "contains",
                node.line(),
                node.end_line(),
                "resolved",
                json!({}),
            );
            owner = id;
            if kind == "attribute" {
                self.records.relation(
                    owner.clone(),
                    json!(format!("php:{name}")),
                    "attribute-type",
                    node.line(),
                    node.end_line(),
                    "resolved",
                    json!({}),
                );
            }
        }
        if node.class_like() {
            if node.kind() == "statement.class" {
                if let Some(parent) = node.child("extends") {
                    self.records.reference(&owner, parent, "extends");
                }
            }
            let field = if node.kind() == "statement.interface" {
                "extends"
            } else {
                "implements"
            };
            for parent in node.list(field) {
                self.records.reference(
                    &owner,
                    parent,
                    if field == "extends" {
                        "extends"
                    } else {
                        "implements"
                    },
                );
            }
        }
        if node.kind() == "statement.trait-use" {
            for trait_ in node.list("traits") {
                self.records.reference(&owner, trait_, "uses-trait");
            }
        }
        if matches!(node.kind(), "param" | "statement.property") {
            self.records.type_references(&owner, node.child("type"));
        }
        if node.callable() {
            self.records
                .type_references(&owner, node.child("returnType"));
        }
        if node.kind() == "expression.include" {
            self.include(node, &owner);
        }
        if matches!(
            node.kind(),
            "expression.method-call" | "expression.nullsafe-method-call" | "expression.static-call"
        ) && node
            .child("name")
            .is_some_and(|n| n.kind().starts_with("expression."))
        {
            self.records.diagnostic(
                "dynamic_call",
                "Dynamic call target needs source inspection.",
                node.line(),
                owner.clone(),
            );
        }
        for child in node.children() {
            self.visit(child, owner.clone(), &class);
        }
    }
    fn callable_metadata(&mut self, node: N<'_>, class: &str, metadata: &mut Value) {
        let params = node.list("params");
        let mut complete = params.len() <= 128;
        let mut parameters = Vec::new();
        let mut seen = Vec::new();
        let last = params
            .iter()
            .rposition(|n| n.child("default").is_none() && !n.boolean("variadic"));
        for (position, param) in params.iter().take(128).enumerate() {
            let name = param.child("var").map_or("", |n| n.name());
            if name.len() > 1000 || name.is_empty() || seen.contains(&name) {
                complete = false;
                continue;
            }
            seen.push(name);
            if param.boolean("variadic")
                && (position != params.len() - 1 || param.child("default").is_some())
            {
                complete = false;
            }
            parameters.push(json!({"name":name,"required":!param.boolean("variadic")&&last.is_some_and(|last|position<=last),"variadic":param.boolean("variadic"),"by_ref":param.boolean("byRef")}));
        }
        metadata["call_parameters"] = json!({"complete":complete,"parameters":parameters});
        metadata["return_sites"] = self.return_sites(node);
        metadata["parameters"]=json!(params.iter().map(|param|json!({"types":param.child("type").map(|n|n.resolved_types(class)).unwrap_or_default(),"line":param.line()})).collect::<Vec<_>>());
        if let Some(returned) = Self::returned(node) {
            if returned.kind() == "expression.static-call"
                && !returned.first_class()
                && returned.child("class").is_some_and(|n| n.is_name())
                && returned
                    .child("name")
                    .is_some_and(|n| n.kind() == "identifier")
            {
                metadata["static_return"] = json!({"class":returned.child("class").unwrap().resolved_types(class)[0],"method":returned.named("name")});
            }
        }
    }
    fn returned(node: N<'_>) -> Option<N<'_>> {
        if node.kind() == "expression.arrow-function" {
            return node.child("expr");
        }
        let stmts = node.list("stmts");
        if stmts.len() == 1 && stmts[0].kind() == "statement.return" {
            stmts[0].child("expr")
        } else {
            None
        }
    }
    fn return_types(&self, node: N<'_>, class: &str) -> Vec<String> {
        let declared = node
            .child("returnType")
            .map(|n| n.resolved_types(class))
            .unwrap_or_default();
        if !declared.is_empty() {
            return declared;
        }
        if let Some(expr) = Self::returned(node) {
            if expr.kind() == "expression.new" {
                if let Some(target) = expr.child("class") {
                    if target.is_name() {
                        return target.resolved_types(class);
                    }
                    if target.kind() == "statement.class" {
                        return vec![format!("(anonymous) {}:{}", node.ast.path, target.start())];
                    }
                }
            }
            if matches!(
                expr.kind(),
                "expression.closure" | "expression.arrow-function"
            ) {
                return vec![format!(
                    "@closure:(closure) {}:{}",
                    node.ast.path,
                    expr.start()
                )];
            }
            if expr.kind() == "expression.variable" {
                if expr.name() == "this" && !class.is_empty() {
                    return vec![class.to_owned()];
                }
                for param in node.list("params") {
                    if param.child("var").is_some_and(|n| n.name() == expr.name()) {
                        return param
                            .child("type")
                            .map(|n| n.resolved_types(class))
                            .unwrap_or_default();
                    }
                }
            }
        }
        Vec::new()
    }
    fn return_sites(&mut self, node: N<'_>) -> Value {
        let arrow = node.kind() == "expression.arrow-function";
        let mut offsets = if arrow {
            vec![node.start()]
        } else {
            Vec::new()
        };
        let mut complete = arrow || !node.field("stmts").is_null();
        let mut pending = if arrow {
            node.child("expr").into_iter().collect()
        } else {
            node.list("stmts")
        };
        let mut visited = 0;
        while let Some(current) = pending.pop() {
            visited += 1;
            if visited > 10000 || current.kind() == "statement.return" && offsets.len() >= 128 {
                self.records.diagnostic(
                    "catalog_limit",
                    "Callable return summary reached its node, return count or memory budget.",
                    node.line(),
                    Value::Null,
                );
                self.records.diagnostics.last_mut().unwrap()["limit_reason"] = json!("structure");
                return json!({"complete":false,"offsets":offsets,"limit_reason":"structure"});
            }
            if current.class_like() || current.callable() {
                continue;
            }
            if matches!(current.kind(), "expression.yield" | "expression.yield-from") {
                complete = false;
            }
            if current.kind() == "statement.return" {
                offsets.push(current.start());
            }
            pending.extend(current.children());
        }
        offsets.sort();
        json!({"complete":complete,"offsets":offsets,"limit_reason":null})
    }
    fn valid_type(name: &str) -> bool {
        name.len() <= 500
            && name.split('\\').all(|part| {
                !part.is_empty()
                    && part.bytes().enumerate().all(|(i, b)| {
                        b.is_ascii_alphabetic() || b == b'_' || i > 0 && b.is_ascii_digit()
                    })
            })
    }
    fn resource_collects(value: Option<N<'_>>, class: &str) -> Value {
        let Some(value) = value else {
            return json!({"resolved":true,"target":null});
        };
        if value.kind() == "expression.class-const-fetch"
            && value.named("name").eq_ignore_ascii_case("class")
            && value.child("class").is_some_and(|n| n.is_name())
        {
            return json!({"resolved":true,"target":value.child("class").unwrap().resolved_types(class)[0]});
        }
        if value.kind() == "scalar.string" {
            let name = value.field("value").as_str().unwrap();
            if !name.is_empty()
                && name.bytes().enumerate().all(|(i, b)| {
                    b == b'\\'
                        || b == b'_'
                        || b.is_ascii_alphabetic()
                        || i > 0 && b.is_ascii_digit()
                })
            {
                return json!({"resolved":true,"target":name.trim_start_matches('\\')});
            }
        }
        let falsy = value.kind() == "expression.const-fetch"
            && ["null", "false"].contains(&value.named("name").to_ascii_lowercase().as_str())
            || value.kind() == "scalar.string"
                && ["", "0"].contains(&value.field("value").as_str().unwrap())
            || matches!(value.kind(), "scalar.int" | "scalar.float")
                && value.field("value").as_f64() == Some(0.0)
            || value.kind() == "expression.array" && value.list("items").is_empty();
        json!({"resolved":falsy,"target":null})
    }
    fn middleware(&mut self, node: N<'_>, class: &str) -> Value {
        let mut args = HashMap::new();
        let mut shape = true;
        for (position, arg) in node.list("args").iter().enumerate() {
            let key = arg.child("name").map(|n| n.name()).unwrap_or_else(|| {
                ["middleware", "only", "except"]
                    .get(position)
                    .copied()
                    .unwrap_or("unknown")
            });
            if arg.boolean("unpack")
                || !["middleware", "only", "except"].contains(&key)
                || args.contains_key(key)
            {
                shape = false;
            }
            args.insert(key, arg.child("value"));
        }
        let middleware = args.get("middleware").copied().flatten();
        let mut name = middleware
            .filter(|n| n.kind() == "scalar.string")
            .map(|n| n.field("value").clone())
            .unwrap_or(Value::Null);
        if let Some(value) = middleware.filter(|n| {
            n.kind() == "expression.class-const-fetch"
                && n.named("name").eq_ignore_ascii_case("class")
        }) {
            if let Some(class_node) = value.child("class").filter(|n| n.is_name()) {
                name = class_node
                    .resolved_types(class)
                    .first()
                    .map(|n| json!(n))
                    .unwrap_or(Value::Null);
            }
        }
        let mut filters = json!({"only":[],"except":[]});
        let mut resolved = true;
        for key in ["only", "except"] {
            let Some(value) = args.get(key).copied().flatten() else {
                continue;
            };
            if value.kind() == "expression.const-fetch"
                && value.named("name").eq_ignore_ascii_case("null")
            {
                continue;
            }
            if value.kind() == "expression.array"
                && value.field("items").as_array().unwrap().len() > 1000
            {
                self.limited = true;
                self.records.diagnostic(
                    "catalog_limit",
                    "Controller middleware attribute filters exceeded their 1000 item budget.",
                    node.line(),
                    Value::Null,
                );
                resolved = false;
                continue;
            }
            if value.kind() != "expression.array" {
                resolved = false;
                continue;
            }
            for item in value.field("items").as_array().unwrap() {
                let Some(item) = value.ast.node(item) else {
                    resolved = false;
                    continue;
                };
                if item.boolean("unpack")
                    || item.child("key").is_some()
                    || !item
                        .child("value")
                        .is_some_and(|n| n.kind() == "scalar.string")
                {
                    resolved = false;
                } else {
                    filters[key]
                        .as_array_mut()
                        .unwrap()
                        .push(item.child("value").unwrap().field("value").clone());
                }
            }
        }
        json!({"middleware":name,"only":filters["only"],"except":filters["except"],"filters_resolved":resolved,"shape_resolved":shape})
    }
    fn include(&mut self, node: N<'_>, owner: &Value) {
        let Some(expression) = node.child("expr") else {
            return;
        };
        let literal = Self::path_literal(expression, 0);
        let anchored = Self::anchored(expression, 0);
        let path = literal
            .as_deref()
            .filter(|s| !s.starts_with('/') && !s.contains('\0'))
            .and_then(|s| {
                Self::normalize_path(s).or_else(|| {
                    if anchored {
                        None
                    } else {
                        Self::normalize_path(&format!("{}/{}", Self::dirname(node.ast.path), s))
                    }
                })
            });
        if let Some(path) = path {
            let target = if anchored {
                json!(format!("file:{path}"))
            } else {
                let mut candidates = vec![path];
                if let Some(caller) = Self::normalize_path(&format!(
                    "{}/{}",
                    Self::dirname(node.ast.path),
                    literal.unwrap()
                )) {
                    if !candidates.contains(&caller) {
                        candidates.push(caller);
                    }
                }
                self.records.identity(json!(["include", candidates]))
            };
            let metadata = if anchored {
                json!({})
            } else {
                let index = target["$identity"].as_u64().unwrap() as usize;
                json!({"candidate_paths":self.records.identities[index][1]})
            };
            self.records.relation(
                owner.clone(),
                target,
                "includes-file",
                node.line(),
                node.end_line(),
                if anchored { "resolved" } else { "conditional" },
                metadata,
            );
            if !anchored {
                self.records.diagnostic("include_search_path","Relative include candidates depend on the runtime working directory and include_path; source candidates are not a proven selection.",node.line(),owner.clone());
            }
        } else {
            self.records.diagnostic(
                "dynamic_include",
                "Included file is dynamic or cannot be located safely from source.",
                node.line(),
                owner.clone(),
            );
        }
    }
    fn dirname(path: &str) -> &str {
        path.rsplit_once('/')
            .map_or(".", |(dir, _)| if dir.is_empty() { "/" } else { dir })
    }
    fn normalize_path(path: &str) -> Option<String> {
        let mut parts = Vec::new();
        for part in path.split('/') {
            match part {
                ".." => {
                    parts.pop()?;
                }
                "" | "." => {}
                _ => parts.push(part),
            }
        }
        if parts.is_empty() {
            None
        } else {
            Some(parts.join("/"))
        }
    }
    fn anchored(node: N<'_>, depth: usize) -> bool {
        depth < 16
            && (matches!(
                node.kind(),
                "scalar.magic-const.dir" | "scalar.magic-const.file"
            ) || node.kind() == "expression.binary-op.concat"
                && node
                    .child("left")
                    .is_some_and(|n| Self::anchored(n, depth + 1)))
    }
    fn path_literal(node: N<'_>, depth: usize) -> Option<String> {
        if depth >= 16 {
            return None;
        }
        match node.kind() {
            "scalar.string" => node
                .field("value")
                .as_str()
                .filter(|s| s.len() <= 500)
                .map(str::to_owned),
            "scalar.magic-const.dir" => Some(Self::dirname(node.ast.path).to_owned()),
            "scalar.magic-const.file" => Some(node.ast.path.to_owned()),
            "expression.binary-op.concat" => Some(format!(
                "{}{}",
                Self::path_literal(node.child("left")?, depth + 1)?,
                Self::path_literal(node.child("right")?, depth + 1)?
            )),
            _ => None,
        }
    }
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;
    use mago_allocator::LocalArena;
    use mago_database::file::FileId;
    use mago_names::resolver::NameResolver;
    use mago_syntax::parser::parse_file_content;

    pub(crate) fn expanded(value: &Value, identities: &[Value]) -> Value {
        match value {
            Value::Object(fields) if fields.contains_key("$identity") => {
                json!({"$tuple":expanded(&identities[value["$identity"].as_u64().unwrap() as usize],identities)})
            }
            Value::Object(fields) => {
                if fields.is_empty() {
                    return json!([]);
                }
                let mut result = serde_json::Map::new();
                for (key, value) in fields {
                    result.insert(key.clone(), expanded(value, identities));
                }
                Value::Object(result)
            }
            Value::Array(values) => json!(
                values
                    .iter()
                    .map(|value| expanded(value, identities))
                    .collect::<Vec<_>>()
            ),
            _ => value.clone(),
        }
    }
    #[test]
    fn declaration_catalog_matches_frozen_php_oracle() {
        let fixtures: Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/Analysis/basic-declarations.json"
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
                .unwrap_or_else(|error| panic!("{}", error.message));
            let ast = Ast {
                syntax: &syntax,
                path,
                source: source.as_bytes(),
            };
            let result = declarations(&ast);
            let actual = json!({"v":3,"elements":result.elements,"relations":result.relations,"diagnostics":result.diagnostics,"descriptor_receivers":[]});
            assert_eq!(
                expanded(&actual, &result.identities),
                expanded(&fixture["expected"], &[]),
                "{}",
                fixture["id"]
            );
        }
    }
    #[test]
    fn call_and_container_catalog_parts_match_php_oracle() {
        let fixtures: Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/Analysis/basic-catalog-parts.json"
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
                .unwrap_or_else(|error| panic!("{}", error.message));
            let ast = Ast {
                syntax: &syntax,
                path,
                source: source.as_bytes(),
            };
            let mut records = declarations(&ast);
            let (relations, diagnostics, descriptors) = if fixture["part"] == "calls" {
                let impact = crate::basic_impact::extract(&ast, true);
                let (relations, diagnostics) =
                    crate::basic_calls::extract(&ast, &mut records, &impact);
                (relations, diagnostics, json!(impact.descriptors))
            } else {
                let (relations, diagnostics) = crate::basic_container::extract(&ast, &mut records);
                (relations, diagnostics, json!([]))
            };
            let actual = json!({"v":3,"elements":[],"relations":relations,"diagnostics":diagnostics,"descriptor_receivers":descriptors});
            assert_eq!(
                expanded(&actual, &records.identities),
                expanded(&fixture["expected"], &[]),
                "{}",
                fixture["id"]
            );
        }
    }
}
