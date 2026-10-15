//! Normalization is private to the backend. The wire format contains no CST types.
use mago_names::ResolvedNames;
use mago_span::{HasSpan, Span};
use mago_syntax::cst::*;
use serde_json::{Value, json};
use std::cell::{Cell, RefCell};
use std::collections::HashMap;

type Result<T> = std::result::Result<T, Unsupported>;

pub struct Unsupported {
    pub span: Span,
    pub message: String,
}

pub fn document(program: &Program<'_>, names: &ResolvedNames<'_>) -> Result<Value> {
    let context = Context {
        source: program.source_text,
        depth: Cell::new(0),
        nodes: RefCell::new(Vec::new()),
        imports: RefCell::new(HashMap::new()),
        names: names
            .iter()
            .map(|(s, _, n, imported)| (s, (text(n), imported)))
            .collect(),
        namespaces: program
            .statements
            .iter()
            .filter_map(|s| {
                if let Statement::Namespace(n) = s {
                    Some((
                        n.span(),
                        n.name.as_ref().map(|i| text(i.value())).unwrap_or_default(),
                    ))
                } else {
                    None
                }
            })
            .collect(),
        comments: program
            .trivia
            .iter()
            .filter(|t| t.kind.is_comment())
            .map(|t| (t.span, text(t.value)))
            .collect(),
    };
    let roots = context.statements(program.statements.iter())?;
    let roots = roots.into_iter().map(|node| node["$node"].clone()).collect::<Vec<_>>();
    Ok(json!({"roots":roots,"nodes":context.nodes.into_inner()}))
}

fn text(value: &[u8]) -> String {
    String::from_utf8_lossy(value).into_owned()
}

struct Context<'a> {
    source: &'a [u8],
    depth: Cell<usize>,
    nodes: RefCell<Vec<Value>>,
    imports: RefCell<HashMap<(u32, String), String>>,
    names: HashMap<u32, (String, bool)>,
    namespaces: Vec<(Span, String)>,
    comments: Vec<(Span, String)>,
}

struct Nesting<'a>(&'a Cell<usize>);
impl Drop for Nesting<'_> {
    fn drop(&mut self) {
        self.0.set(self.0.get() - 1);
    }
}

impl Context<'_> {
    fn enter(&self, span: Span) -> Result<Nesting<'_>> {
        if self.depth.get() >= 8192 {
            return Err(self.unsupported(span, "syntax deeper than the structural budget"));
        }
        self.depth.set(self.depth.get() + 1);
        Ok(Nesting(&self.depth))
    }
    fn unsupported(&self, span: Span, what: &str) -> Unsupported {
        Unsupported {
            span,
            message: format!(
                "Mago syntax normalization does not yet support {what}. No PHP fallback was used."
            ),
        }
    }

    fn node(&self, kind: &str, span: Span, fields: Value) -> Value {
        let mut comments = Vec::new();
        let mut cursor = span.start.offset as usize;
        let before = self
            .comments
            .partition_point(|(range, _)| range.end.offset <= span.start.offset);
        for (range, comment) in self.comments[..before].iter().rev() {
            let end = range.end.offset as usize;
            if end <= cursor
                && self.source[end..cursor]
                    .iter()
                    .all(|b| b.is_ascii_whitespace())
            {
                comments.push(json!({"text":comment,"documentation":comment.starts_with("/**")}));
                cursor = range.start.offset as usize;
            } else {
                break;
            }
        }
        comments.reverse();
        let node = json!({"kind":kind,"start":span.start.offset,"end":span.end.offset,
            "fields":fields,"attributes":{},"comments":comments});
        let mut nodes = self.nodes.borrow_mut();
        let index = nodes.len();
        nodes.push(node);
        json!({"$node":index})
    }

    fn field(&self, node: &Value, field: &str) -> Value {
        self.nodes.borrow()[node["$node"].as_u64().unwrap() as usize]["fields"][field].clone()
    }

    fn kind(&self, node: &Value) -> String {
        self.nodes.borrow()[node["$node"].as_u64().unwrap() as usize]["kind"].as_str().unwrap().to_owned()
    }

    fn attribute(&self, node: Value, key: &str, value: Value) -> Value {
        let mut nodes = self.nodes.borrow_mut();
        let mut original = nodes[node["$node"].as_u64().unwrap() as usize].clone();
        original["attributes"][key] = value;
        let index = nodes.len();
        nodes.push(original);
        json!({"$node":index})
    }

    fn namespace(&self, span: Span) -> (u32, &str) {
        self.namespaces.iter().find(|(range,_)| range.start.offset <= span.start.offset && range.end.offset >= span.end.offset)
            .map(|(range,name)| (range.start.offset,name.as_str())).unwrap_or((0,""))
    }

    fn class_name(&self, span: Span) -> String {
        let raw = self.raw(span);
        if raw.starts_with('\\') { return raw.trim_start_matches('\\').to_owned(); }
        let (scope,namespace) = self.namespace(span);
        if raw.to_lowercase().starts_with("namespace\\") {
            return if namespace.is_empty() {raw[10..].to_owned()} else {format!("{namespace}\\{}",&raw[10..])};
        }
        let (first,rest) = raw.split_once('\\').map_or((raw.as_str(),None),|(a,b)|(a,Some(b)));
        if let Some(imported) = self.imports.borrow().get(&(scope,first.to_lowercase())) {
            return rest.map_or_else(||imported.clone(),|rest|format!("{imported}\\{rest}"));
        }
        if namespace.is_empty() {raw} else {format!("{namespace}\\{raw}")}
    }

    fn raw(&self, span: Span) -> String {
        text(&self.source[span.start.offset as usize..span.end.offset as usize])
    }
    fn identifier(&self, span: Span) -> Value {
        self.node("identifier", span, json!({"name":self.raw(span)}))
    }
    fn name(&self, span: Span, resolved: bool) -> Value {
        let raw = self.raw(span);
        let (kind, name) = if raw.starts_with('\\') {
            ("name.fully-qualified", raw.trim_start_matches('\\'))
        } else if raw.to_lowercase().starts_with("namespace\\") {
            ("name.relative", &raw[10..])
        } else {
            ("name", raw.as_str())
        };
        let mut node = self.node(kind, span, json!({"name":name}));
        if resolved {
            let name = self.names.get(&span.start.offset).map(|(name,_)|name.clone()).unwrap_or_else(||self.class_name(span));
            let resolved = self.node("name.fully-qualified", span, json!({"name":name}));
            node = self.attribute(node, "resolvedName", resolved);
        }
        node
    }

    fn runtime_name(&self, span: Span) -> Value {
        let raw = self.raw(span);
        let imported = self
            .names
            .get(&span.start.offset)
            .is_some_and(|(_, imported)| *imported);
        let namespace = self
            .namespaces
            .iter()
            .find(|(range, _)| {
                range.start.offset <= span.start.offset && range.end.offset >= span.end.offset
            })
            .map(|(_, name)| name.as_str())
            .unwrap_or("");
        if !raw.contains('\\') && !imported && !namespace.is_empty() {
            let node = self.name(span, false);
            let resolved = self.node(
                "name.fully-qualified",
                span,
                json!({"name":format!("{namespace}\\{raw}")}),
            );
            self.attribute(node, "namespacedName", resolved)
        } else {
            self.name(span, true)
        }
    }
    fn call_target(&self, expression: &Expression<'_>) -> Result<Value> {
        if let Expression::Identifier(n) = expression {
            Ok(self.runtime_name(n.span()))
        } else {
            self.expr(expression)
        }
    }
    fn declared(&self, node: Value, name: Span) -> Value {
        if let Some((resolved, _)) = self.names.get(&name.start.offset) {
            let resolved = self.node("name.fully-qualified", name, json!({"name":resolved}));
            return self.attribute(node, "namespacedName", resolved);
        }
        node
    }

    fn flags(&self, modifiers: &Sequence<'_, Modifier<'_>>) -> u32 {
        modifiers.iter().fold(0, |f, m| {
            f | match m {
                Modifier::Public(_) => 1,
                Modifier::Protected(_) => 2,
                Modifier::Private(_) => 4,
                Modifier::Static(_) => 8,
                Modifier::Abstract(_) => 16,
                Modifier::Final(_) => 32,
                Modifier::Readonly(_) => 64,
                Modifier::PublicSet(_) => 128,
                Modifier::ProtectedSet(_) => 256,
                Modifier::PrivateSet(_) => 512,
            }
        })
    }

    fn hint(&self, hint: &Hint<'_>) -> Result<Value> {
        let _nesting = self.enter(hint.span())?;
        let span = hint.span();
        Ok(match hint {
            Hint::Identifier(n) => self.name(n.span(), true),
            Hint::Parenthesized(n) => self.hint(n.hint)?,
            Hint::Nullable(n) => {
                self.node("nullable-type", span, json!({"type":self.hint(n.hint)?}))
            }
            Hint::Union(_) | Hint::Intersection(_) => {
                let mut types = Vec::new();
                self.hint_parts(hint, matches!(hint, Hint::Union(_)), &mut types)?;
                self.node(
                    if matches!(hint, Hint::Union(_)) {
                        "union-type"
                    } else {
                        "intersection-type"
                    },
                    span,
                    json!({"types":types}),
                )
            }
            Hint::Self_(_) | Hint::Parent(_) | Hint::Static(_) => self.name(span, false),
            _ => self.identifier(span),
        })
    }
    fn hint_parts(&self, hint: &Hint<'_>, union: bool, parts: &mut Vec<Value>) -> Result<()> {
        match hint {
            Hint::Union(n) if union => {
                self.hint_parts(n.left, union, parts)?;
                self.hint_parts(n.right, union, parts)?;
            }
            Hint::Intersection(n) if !union => {
                self.hint_parts(n.left, union, parts)?;
                self.hint_parts(n.right, union, parts)?;
            }
            _ => parts.push(self.hint(hint)?),
        }
        Ok(())
    }
    fn optional_hint(&self, hint: Option<&Hint<'_>>) -> Result<Value> {
        hint.map(|h| self.hint(h))
            .transpose()
            .map(|v| v.unwrap_or(Value::Null))
    }
    fn optional_expr(&self, expr: Option<&Expression<'_>>) -> Result<Value> {
        expr.map(|e| self.expr(e))
            .transpose()
            .map(|v| v.unwrap_or(Value::Null))
    }

    fn attrs(&self, lists: &Sequence<'_, AttributeList<'_>>) -> Result<Vec<Value>> {
        lists.iter().map(|list| Ok(self.node("attribute-group",list.span(),json!({"attrs":list.attributes.iter().map(|attr|
            Ok(self.node("attribute",attr.span(),json!({"name":self.name(attr.name.span(),true),
                "args":self.partial_args(attr.argument_list.as_ref())?})))).collect::<Result<Vec<_>>>()?})))).collect()
    }
    fn parameters(&self, list: &FunctionLikeParameterList<'_>) -> Result<Vec<Value>> {
        list.parameters.iter().map(|p| {
            Ok(self.node("param",p.span(),json!({"type":self.optional_hint(p.hint.as_ref())?,"byRef":p.ampersand.is_some(),
                "variadic":p.ellipsis.is_some(),"var":self.direct_variable(&p.variable),
                "default":self.optional_expr(p.default_value.as_ref().map(|d|d.value))?,"flags":self.flags(&p.modifiers),
                "attrGroups":self.attrs(&p.attribute_lists)?,"hooks":p.hooks.as_ref().map(|h|self.hooks(h)).transpose()?.unwrap_or_default()})))
        }).collect()
    }
    fn direct_variable(&self, var: &DirectVariable<'_>) -> Value {
        self.node(
            "expression.variable",
            var.span,
            json!({"name":text(var.name).trim_start_matches('$')}),
        )
    }
    fn variable(&self, var: &Variable<'_>) -> Result<Value> {
        Ok(match var {
            Variable::Direct(n) => self.direct_variable(n),
            Variable::Indirect(n) => self.node(
                "expression.variable",
                n.span(),
                json!({"name":self.expr(n.expression)?}),
            ),
            Variable::Nested(n) => self.node(
                "expression.variable",
                n.span(),
                json!({"name":self.variable(n.variable)?}),
            ),
        })
    }
    fn selector(&self, selector: &ClassLikeMemberSelector<'_>) -> Result<Value> {
        match selector {
            ClassLikeMemberSelector::Identifier(n) => Ok(self.identifier(n.span)),
            ClassLikeMemberSelector::Variable(n) => self.variable(n),
            ClassLikeMemberSelector::Expression(n) => self.expr(n.expression),
            _ => Err(self.unsupported(selector.span(), "missing member selector")),
        }
    }
    fn argument(
        &self,
        value: &Expression<'_>,
        span: Span,
        name: Option<Span>,
        unpack: bool,
    ) -> Result<Value> {
        Ok(self.node(
            "arg",
            span,
            json!({"value":self.expr(value)?,"byRef":false,"unpack":unpack,
            "name":name.map(|s|self.identifier(s))}),
        ))
    }
    fn args(&self, list: &ArgumentList<'_>) -> Result<Vec<Value>> {
        list.arguments
            .iter()
            .map(|a| match a {
                Argument::Positional(n) => {
                    self.argument(n.value, n.span(), None, n.ellipsis.is_some())
                }
                Argument::Named(n) => self.argument(n.value, n.span(), Some(n.name.span), false),
            })
            .collect()
    }
    fn partial_args(&self, list: Option<&PartialArgumentList<'_>>) -> Result<Vec<Value>> {
        list.map(|list| {
            list.arguments
                .iter()
                .map(|a| match a {
                    PartialArgument::Positional(n) => {
                        self.argument(n.value, n.span(), None, n.ellipsis.is_some())
                    }
                    PartialArgument::Named(n) => {
                        self.argument(n.value, n.span(), Some(n.name.span), false)
                    }
                    PartialArgument::VariadicPlaceholder(n) => {
                        Ok(self.node("variadic-placeholder", n.span, json!({})))
                    }
                    _ => Err(self.unsupported(a.span(), "partial application placeholder")),
                })
                .collect()
        })
        .unwrap_or_else(|| Ok(Vec::new()))
    }

    fn expr(&self, expr: &Expression<'_>) -> Result<Value> {
        let _nesting = self.enter(expr.span())?;
        let span = expr.span();
        Ok(match expr {
            Expression::Parenthesized(n) => self.expr(n.expression)?,
            Expression::Identifier(n) => self.name(n.span(),true),
            Expression::Self_(_) | Expression::Parent(_) | Expression::Static(_) => self.name(span,false),
            Expression::ConstantAccess(n) => self.node("expression.const-fetch",span,json!({"name":self.runtime_name(n.name.span())})),
            Expression::Variable(n) => self.variable(n)?,
            Expression::Literal(n) => match n {
                Literal::String(n) => self.node("scalar.string",span,json!({"value":text(n.value.ok_or_else(||self.unsupported(span,"invalid string encoding"))?)})),
                Literal::Integer(n) => {
                    match n.value {
                        Some(value) if value <= i64::MAX as u64 => self.node("scalar.int",span,json!({"value":value})),
                        value => {
                            let number = match value {
                                Some(value) => value as f64,
                                None => {
                                    let raw = self.raw(span).replace('_', "");
                                    let (digits, radix) = if raw.starts_with("0x") || raw.starts_with("0X") { (&raw[2..], 16) }
                                        else if raw.starts_with("0b") || raw.starts_with("0B") { (&raw[2..], 2) }
                                        else if raw.starts_with("0o") || raw.starts_with("0O") { (&raw[2..], 8) }
                                        else if raw.starts_with('0') && raw.len() > 1 { (&raw[1..], 8) }
                                        else { (raw.as_str(), 10) };
                                    digits.chars().try_fold(0.0, |number, digit| digit.to_digit(radix).map(|digit|number * radix as f64 + digit as f64))
                                        .ok_or_else(||self.unsupported(span,"invalid integer literal"))?
                                }
                            };
                            if !number.is_finite() { return Err(self.unsupported(span,"nonfinite integer literal")); }
                            self.node("scalar.float",span,json!({"value":number}))
                        }
                    }
                },
                Literal::Float(n) => self.node("scalar.float",span,json!({"value":n.value.0})),
                _ => self.node("expression.const-fetch",span,json!({"name":self.name(span,false)})),
            },
            Expression::Call(call) => match call {
                Call::Function(n) => self.node("expression.func-call",span,json!({"name":self.call_target(n.function)?,"args":self.args(&n.argument_list)?})),
                Call::Method(n) => self.node("expression.method-call",span,json!({"var":self.expr(n.object)?,"name":self.selector(&n.method)?,"args":self.args(&n.argument_list)?})),
                Call::NullSafeMethod(n) => self.node("expression.nullsafe-method-call",span,json!({"var":self.expr(n.object)?,"name":self.selector(&n.method)?,"args":self.args(&n.argument_list)?})),
                Call::StaticMethod(n) => self.node("expression.static-call",span,json!({"class":self.expr(n.class)?,"name":self.selector(&n.method)?,"args":self.args(&n.argument_list)?})),
            },
            Expression::PartialApplication(n) => match n {
                PartialApplication::Function(n) => self.node("expression.func-call",span,json!({"name":self.call_target(n.function)?,"args":self.partial_args(Some(&n.argument_list))?})),
                PartialApplication::Method(n) => self.node("expression.method-call",span,json!({"var":self.expr(n.object)?,"name":self.selector(&n.method)?,"args":self.partial_args(Some(&n.argument_list))?})),
                PartialApplication::StaticMethod(n) => self.node("expression.static-call",span,json!({"class":self.expr(n.class)?,"name":self.selector(&n.method)?,"args":self.partial_args(Some(&n.argument_list))?})),
            },
            Expression::Instantiation(n) => self.node("expression.new",span,json!({"class":self.expr(n.class)?,"args":n.argument_list.as_ref().map(|a|self.args(a)).transpose()?.unwrap_or_default()})),
            Expression::Array(n) => self.node("expression.array",span,json!({"items":self.elements(n.elements.iter())?})),
            Expression::LegacyArray(n) => self.node("expression.array",span,json!({"items":self.elements(n.elements.iter())?})),
            Expression::List(n) => self.node("expression.list",span,json!({"items":self.elements(n.elements.iter())?})),
            Expression::ArrayAccess(n) => self.node("expression.array-dim-fetch",span,json!({"var":self.expr(n.array)?,"dim":self.expr(n.index)?})),
            Expression::ArrayAppend(n) => self.node("expression.array-dim-fetch",span,json!({"var":self.expr(n.array)?,"dim":null})),
            Expression::Access(n) => match n {
                Access::Property(n) => self.node("expression.property-fetch",span,json!({"var":self.expr(n.object)?,"name":self.selector(&n.property)?})),
                Access::NullSafeProperty(n) => self.node("expression.nullsafe-property-fetch",span,json!({"var":self.expr(n.object)?,"name":self.selector(&n.property)?})),
                Access::StaticProperty(n) => {
                    let name=match &n.property { Variable::Direct(p) => self.node("var-like-identifier",p.span,json!({"name":text(p.name).trim_start_matches('$')})), other=>self.variable(other)? };
                    self.node("expression.static-property-fetch",span,json!({"class":self.expr(n.class)?,"name":name}))
                },
                Access::ClassConstant(n) => {
                    let name=match &n.constant { ClassLikeConstantSelector::Identifier(n)=>self.identifier(n.span),ClassLikeConstantSelector::Expression(n)=>self.expr(n.expression)?,_=>return Err(self.unsupported(span,"missing constant selector")) };
                    self.node("expression.class-const-fetch",span,json!({"class":self.expr(n.class)?,"name":name}))
                },
            },
            Expression::Conditional(n) => self.node("expression.ternary",span,json!({"cond":self.expr(n.condition)?,"if":self.optional_expr(n.then)?,"else":self.expr(n.r#else)?})),
            Expression::Throw(n) => self.node("expression.throw",span,json!({"expr":self.expr(n.exception)?})),
            Expression::Clone(n) => self.node("expression.clone",span,json!({"expr":self.expr(n.object)?})),
            Expression::Binary(n) => {
                let op=self.raw(n.operator.span()).to_lowercase();
                if op=="instanceof" { self.node("expression.instanceof",span,json!({"expr":self.expr(n.lhs)?,"class":self.expr(n.rhs)?})) }
                else {
                    let kind=match op.as_str() {"+"=>"plus","-"=>"minus","*"=>"mul","/"=>"div","%"=>"mod","**"=>"pow","."=>"concat","&"=>"bitwise-and","|"=>"bitwise-or","^"=>"bitwise-xor","<<"=>"shift-left",">>"=>"shift-right","??"=>"coalesce","=="=>"equal","!="|"<>"=>"not-equal","==="=>"identical","!=="=>"not-identical","<"=>"smaller",">"=>"greater","<="=>"smaller-or-equal",">="=>"greater-or-equal","<=>"=>"spaceship","&&"=>"boolean-and","||"=>"boolean-or","and"=>"logical-and","or"=>"logical-or","xor"=>"logical-xor",_=>return Err(self.unsupported(span,"binary operator"))};
                    self.node(&format!("expression.binary-op.{kind}"),span,json!({"left":self.expr(n.lhs)?,"right":self.expr(n.rhs)?}))
                }
            },
            Expression::Assignment(n) => {
                let op=self.raw(n.operator.span());
                let kind=match op.as_str() {"="=>"expression.assign","+="=>"expression.assign-op.plus","-="=>"expression.assign-op.minus","*="=>"expression.assign-op.mul","/="=>"expression.assign-op.div","%="=>"expression.assign-op.mod","**="=>"expression.assign-op.pow",".="=>"expression.assign-op.concat","&="=>"expression.assign-op.bitwise-and","|="=>"expression.assign-op.bitwise-or","^="=>"expression.assign-op.bitwise-xor","<<="=>"expression.assign-op.shift-left",">>="=>"expression.assign-op.shift-right","??="=>"expression.assign-op.coalesce",_=>return Err(self.unsupported(span,"assignment operator"))};
                if let Expression::UnaryPrefix(r)=n.rhs && matches!(r.operator,UnaryPrefixOperator::Reference(_)) {
                    self.node("expression.assign-ref",span,json!({"var":self.expr(n.lhs)?,"expr":self.expr(r.operand)?}))
                } else { self.node(kind,span,json!({"var":self.expr(n.lhs)?,"expr":self.expr(n.rhs)?})) }
            },
            Expression::UnaryPrefix(n) => {
                let kind=match &n.operator {UnaryPrefixOperator::Not(_)=>"expression.boolean-not",UnaryPrefixOperator::BitwiseNot(_)=>"expression.bitwise-not",UnaryPrefixOperator::Plus(_)=>"expression.unary-plus",UnaryPrefixOperator::Negation(_)=>"expression.unary-minus",UnaryPrefixOperator::ErrorControl(_)=>"expression.error-suppress",UnaryPrefixOperator::PreIncrement(_)=>"expression.pre-inc",UnaryPrefixOperator::PreDecrement(_)=>"expression.pre-dec",UnaryPrefixOperator::ArrayCast(..)=>"expression.cast.array",UnaryPrefixOperator::BoolCast(..)|UnaryPrefixOperator::BooleanCast(..)=>"expression.cast.bool",UnaryPrefixOperator::DoubleCast(..)|UnaryPrefixOperator::RealCast(..)|UnaryPrefixOperator::FloatCast(..)=>"expression.cast.double",UnaryPrefixOperator::IntCast(..)|UnaryPrefixOperator::IntegerCast(..)=>"expression.cast.int",UnaryPrefixOperator::StringCast(..)|UnaryPrefixOperator::BinaryCast(..)=>"expression.cast.string",UnaryPrefixOperator::ObjectCast(..)=>"expression.cast.object",UnaryPrefixOperator::UnsetCast(..)=>"expression.cast.unset",UnaryPrefixOperator::VoidCast(..)=>"expression.cast.void",_=>return Err(self.unsupported(span,"standalone reference"))};
                let field=if matches!(n.operator,UnaryPrefixOperator::PreIncrement(_)|UnaryPrefixOperator::PreDecrement(_)) {"var"} else {"expr"};
                self.node(kind,span,json!({field:self.expr(n.operand)?}))
            },
            Expression::UnaryPostfix(n) => self.node(if matches!(n.operator,UnaryPostfixOperator::PostIncrement(_)) {"expression.post-inc"} else {"expression.post-dec"},span,json!({"var":self.expr(n.operand)?})),
            Expression::ArrowFunction(n) => self.node("expression.arrow-function",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"static":n.r#static.is_some(),"byRef":n.ampersand.is_some(),"params":self.parameters(&n.parameter_list)?,"returnType":self.optional_hint(n.return_type_hint.as_ref().map(|r|&r.hint))?,"expr":self.expr(n.expression)?})),
            Expression::Closure(n) => {
                let uses=n.use_clause.as_ref().map(|u|u.variables.iter().map(|v|self.node("closure-use",v.span(),json!({"var":self.direct_variable(&v.variable),"byRef":v.ampersand.is_some()}))).collect::<Vec<_>>()).unwrap_or_default();
                self.node("expression.closure",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"static":n.r#static.is_some(),"byRef":n.ampersand.is_some(),"params":self.parameters(&n.parameter_list)?,"uses":uses,"returnType":self.optional_hint(n.return_type_hint.as_ref().map(|r|&r.hint))?,"stmts":self.statements(n.body.statements.iter())?}))
            },
            Expression::Construct(c) => self.construct(c,span)?,
            Expression::Pipe(n)=>self.node("expression.binary-op.pipe",span,json!({"left":self.expr(n.input)?,"right":self.expr(n.callable)?})),

            Expression::AnonymousClass(n) => {
                let class_span = Span::new(span.file_id, n.attribute_lists.first().map_or(n.class.span().start, |a|a.span().start), n.right_brace.end);
                let class=self.node("statement.class",class_span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"flags":self.flags(&n.modifiers),"name":null,
                    "extends":n.extends.as_ref().and_then(|e|e.types.iter().next()).map(|n|self.name(n.span(),true)),
                    "implements":n.implements.as_ref().map(|i|i.types.iter().map(|n|self.name(n.span(),true)).collect::<Vec<_>>()).unwrap_or_default(),"stmts":self.members(&n.members)?}));
                self.node("expression.new",span,json!({"class":class,"args":self.partial_args(n.argument_list.as_ref())?}))
            },
            Expression::MagicConstant(n) => {
                let kind=match n {MagicConstant::Line(_)=>"line",MagicConstant::File(_)=>"file",MagicConstant::Directory(_)=>"dir",MagicConstant::Trait(_)=>"trait",MagicConstant::Method(_)=>"method",MagicConstant::Function(_)=>"function",MagicConstant::Property(_)=>"property",MagicConstant::Namespace(_)=>"namespace",MagicConstant::Class(_)=>"class"};
                self.node(&format!("scalar.magic-const.{kind}"),span,json!({}))
            },
            Expression::Match(n) => self.node("expression.match",span,json!({"cond":self.expr(n.expression)?,"arms":n.arms.iter().map(|arm| {
                let (conditions,value)=match arm {
                    MatchArm::Expression(n)=>(json!(n.conditions.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?),n.expression),
                    MatchArm::Default(n)=>(Value::Null,n.expression),
                };
                Ok(self.node("match-arm",arm.span(),json!({"conds":conditions,"body":self.expr(value)?})))
            }).collect::<Result<Vec<_>>>()?})),
            Expression::Yield(n)=>match n {
                Yield::Value(n)=>self.node("expression.yield",span,json!({"key":null,"value":self.optional_expr(n.value)?})),
                Yield::Pair(n)=>self.node("expression.yield",span,json!({"key":self.expr(n.key)?,"value":self.expr(n.value)?})),
                Yield::From(n)=>self.node("expression.yield-from",span,json!({"expr":self.expr(n.iterator)?})),
            },
            Expression::CompositeString(n)=> {
                let (parts,shell,document)=match n {
                    CompositeString::Interpolated(n)=>(&n.parts,false,false),
                    CompositeString::ShellExecute(n)=>(&n.parts,true,false),
                    CompositeString::Document(n)=>(&n.parts,false,true),
                };
                let decoded=parts.iter().map(|p|match p {
                    StringPart::Literal(n)=>Ok(self.node("interpolated-string-part",n.span,json!({"value":text(n.value.ok_or_else(||self.unsupported(n.span,"invalid string part encoding"))?)}))),
                    StringPart::Expression(e)=>self.expr(e),
                    StringPart::BracedExpression(e)=>self.expr(e.expression),
                }).collect::<Result<Vec<_>>>()?;
                // A noninterpolated document is one scalar, matching a quoted literal.
                if document && parts.iter().all(|p|matches!(p,StringPart::Literal(_))) {
                    let value=decoded.iter().map(|p|self.field(p,"value").as_str().unwrap_or("").to_owned()).collect::<String>();
                    self.node("scalar.string",span,json!({"value":value}))
                } else { self.node(if shell {"expression.shell-exec"}else{"scalar.interpolated-string"},span,json!({"parts":decoded})) }
            },
            _ => return Err(self.unsupported(span,&format!("expression {expr}"))),
        })
    }

    fn construct(&self, construct: &Construct<'_>, span: Span) -> Result<Value> {
        Ok(match construct {
            Construct::Isset(n)=>self.node("expression.isset",span,json!({"vars":n.values.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?})),
            Construct::Empty(n)=>self.node("expression.empty",span,json!({"expr":self.expr(n.value)?})),
            Construct::Eval(n)=>self.node("expression.eval",span,json!({"expr":self.expr(n.value)?})),
            Construct::Print(n)=>self.node("expression.print",span,json!({"expr":self.expr(n.value)?})),
            Construct::Include(n)=>self.node("expression.include",span,json!({"expr":self.expr(n.value)?,"type":1})),
            Construct::IncludeOnce(n)=>self.node("expression.include",span,json!({"expr":self.expr(n.value)?,"type":2})),
            Construct::Require(n)=>self.node("expression.include",span,json!({"expr":self.expr(n.value)?,"type":3})),
            Construct::RequireOnce(n)=>self.node("expression.include",span,json!({"expr":self.expr(n.value)?,"type":4})),
            Construct::Exit(n)=>self.node("expression.exit",span,json!({"expr":n.arguments.as_ref().map(|a| self.args(a)).transpose()?.and_then(|a|a.first().map(|v|self.field(v,"value")))})),
            Construct::Die(n)=>self.node("expression.exit",span,json!({"expr":n.arguments.as_ref().map(|a| self.args(a)).transpose()?.and_then(|a|a.first().map(|v|self.field(v,"value")))})),
        })
    }
    fn elements<'a>(
        &self,
        elements: impl Iterator<Item = &'a ArrayElement<'a>>,
    ) -> Result<Vec<Value>> {
        elements.map(|item| {
            let (key,value,unpack)=match item {ArrayElement::KeyValue(n)=>(Some(n.key),n.value,false),ArrayElement::Value(n)=>(None,n.value,false),ArrayElement::Variadic(n)=>(None,n.value,true),ArrayElement::Missing(_)=>return Ok(Value::Null)};
            let (value,by_ref)=if let Expression::UnaryPrefix(n)=value && matches!(n.operator,UnaryPrefixOperator::Reference(_)) {(n.operand,true)} else {(value,false)};
            Ok(self.node("array-item",item.span(),json!({"key":self.optional_expr(key)?,"value":self.expr(value)?,"byRef":by_ref,"unpack":unpack})))
        }).collect()
    }

    fn statements<'a>(
        &self,
        statements: impl Iterator<Item = &'a Statement<'a>>,
    ) -> Result<Vec<Value>> {
        let mut out = Vec::new();
        for statement in statements {
            if let Some(value) = self.statement(statement)? {
                out.push(value);
            }
        }
        Ok(out)
    }
    fn body(&self, statement: &Statement<'_>) -> Result<Vec<Value>> {
        match statement {
            Statement::Block(n) => self.statements(n.statements.iter()),
            _ => self.statement(statement).map(|n| n.into_iter().collect()),
        }
    }
    fn statement(&self, statement: &Statement<'_>) -> Result<Option<Value>> {
        let _nesting = self.enter(statement.span())?;
        let span = statement.span();
        Ok(Some(match statement {
            Statement::OpeningTag(_) | Statement::ClosingTag(_) => return Ok(None),
            Statement::Namespace(n) => {
                let statements=match &n.body {NamespaceBody::Implicit(n)=>self.statements(n.statements.iter())?,NamespaceBody::BraceDelimited(n)=>self.statements(n.statements.iter())?};
                self.node("statement.namespace",span,json!({"name":n.name.as_ref().map(|n|self.name(n.span(),false)),"stmts":statements}))
            },
            Statement::Expression(n)=>self.node("statement.expression",span,json!({"expr":self.expr(n.expression)?})),
            Statement::Return(n)=>self.node("statement.return",span,json!({"expr":self.optional_expr(n.value)?})),
            Statement::Block(n)=>self.node("statement.block",span,json!({"stmts":self.statements(n.statements.iter())?})),
            Statement::Noop(_)=>self.node("statement.nop",span,json!({})),
            Statement::Class(n)=>self.declared(self.node("statement.class",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"flags":self.flags(&n.modifiers),"name":self.identifier(n.name.span),
                "extends":n.extends.as_ref().and_then(|e|e.types.iter().next()).map(|n|self.name(n.span(),true)),
                "implements":n.implements.as_ref().map(|i|i.types.iter().map(|n|self.name(n.span(),true)).collect::<Vec<_>>()).unwrap_or_default(),
                "stmts":self.members(&n.members)?})),n.name.span),
            Statement::Interface(n)=>self.declared(self.node("statement.interface",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"name":self.identifier(n.name.span),
                "extends":n.extends.as_ref().map(|e|e.types.iter().map(|n|self.name(n.span(),true)).collect::<Vec<_>>()).unwrap_or_default(),"stmts":self.members(&n.members)?})),n.name.span),
            Statement::Trait(n)=>self.declared(self.node("statement.trait",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"name":self.identifier(n.name.span),"stmts":self.members(&n.members)?})),n.name.span),
            Statement::Enum(n)=>self.declared(self.node("statement.enum",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"name":self.identifier(n.name.span),
                "scalarType":n.backing_type_hint.as_ref().map(|t|self.identifier(t.hint.span())),
                "implements":n.implements.as_ref().map(|i|i.types.iter().map(|n|self.name(n.span(),true)).collect::<Vec<_>>()).unwrap_or_default(),"stmts":self.members(&n.members)?})),n.name.span),
            Statement::Function(n)=>self.declared(self.node("statement.function",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"byRef":n.ampersand.is_some(),"name":self.identifier(n.name.span),"params":self.parameters(&n.parameter_list)?,
                "returnType":self.optional_hint(n.return_type_hint.as_ref().map(|r|&r.hint))?,"stmts":self.statements(n.body.statements.iter())?})),n.name.span),
            Statement::Use(n)=>self.import(n)?,
            Statement::Echo(n)=>self.node("statement.echo",span,json!({"exprs":n.values.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?})),
            Statement::If(n)=>{
                let (stmts,elseifs,other)=match &n.body {
                    IfBody::Statement(b)=> (self.body(b.statement)?, b.else_if_clauses.iter().map(|e| Ok(self.node("statement.else-if",e.span(),json!({"cond":self.expr(e.condition)?,"stmts":self.body(e.statement)?})))).collect::<Result<Vec<_>>>()?, b.else_clause.as_ref().map(|e|Ok(self.node("statement.else",e.span(),json!({"stmts":self.body(e.statement)?})))).transpose()?),
                    IfBody::ColonDelimited(b)=> (self.statements(b.statements.iter())?, b.else_if_clauses.iter().map(|e| Ok(self.node("statement.else-if",e.span(),json!({"cond":self.expr(e.condition)?,"stmts":self.statements(e.statements.iter())?})))).collect::<Result<Vec<_>>>()?, b.else_clause.as_ref().map(|e|Ok(self.node("statement.else",e.span(),json!({"stmts":self.statements(e.statements.iter())?})))).transpose()?),
                };
                self.node("statement.if",span,json!({"cond":self.expr(n.condition)?,"stmts":stmts,"elseifs":elseifs,"else":other}))
            },
            Statement::Foreach(n)=> {
                let (key,value)=match &n.target {ForeachTarget::Value(t)=>(None,t.value),ForeachTarget::KeyValue(t)=>(Some(t.key),t.value)};
                let (value,by_ref)=if let Expression::UnaryPrefix(n)=value && matches!(n.operator,UnaryPrefixOperator::Reference(_)) {(n.operand,true)}else{(value,false)};
                let body=match &n.body {ForeachBody::Statement(s)=>self.body(s)?,ForeachBody::ColonDelimited(s)=>self.statements(s.statements.iter())?};
                self.node("statement.foreach",span,json!({"expr":self.expr(n.expression)?,"keyVar":self.optional_expr(key)?,"valueVar":self.expr(value)?,"byRef":by_ref,"stmts":body}))
            },
            Statement::While(n)=>self.node("statement.while",span,json!({"cond":self.expr(n.condition)?,"stmts":match &n.body {WhileBody::Statement(s)=>self.body(s)?,WhileBody::ColonDelimited(s)=>self.statements(s.statements.iter())?}})),
            Statement::For(n)=>self.node("statement.for",span,json!({"init":n.initializations.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?,"cond":n.conditions.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?,"loop":n.increments.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?,"stmts":match &n.body {ForBody::Statement(s)=>self.body(s)?,ForBody::ColonDelimited(s)=>self.statements(s.statements.iter())?}})),

            Statement::Break(n)=>self.node("statement.break",span,json!({"num":self.optional_expr(n.level)?})),
            Statement::Continue(n)=>self.node("statement.continue",span,json!({"num":self.optional_expr(n.level)?})),
            Statement::DoWhile(n)=>self.node("statement.do",span,json!({"cond":self.expr(n.condition)?,"stmts":self.body(n.statement)?})),
            Statement::Try(n)=>self.node("statement.try-catch",span,json!({"stmts":self.statements(n.block.statements.iter())?,"catches":n.catch_clauses.iter().map(|c| {
                let hint=self.hint(&c.hint)?;
                let types=if self.kind(&hint)=="union-type" {self.field(&hint,"types")} else {json!([hint])};
                Ok(self.node("statement.catch",c.span(),json!({"types":types,"var":c.variable.as_ref().map(|v|self.direct_variable(v)),"stmts":self.statements(c.block.statements.iter())?})))
            }).collect::<Result<Vec<_>>>()?,"finally":n.finally_clause.as_ref().map(|f|Ok(self.node("statement.finally",f.span(),json!({"stmts":self.statements(f.block.statements.iter())?})))).transpose()?})),
            Statement::Switch(n)=>{
                let cases=match &n.body {SwitchBody::BraceDelimited(b)=>&b.cases,SwitchBody::ColonDelimited(b)=>&b.cases};
                self.node("statement.switch",span,json!({"cond":self.expr(n.expression)?,"cases":cases.iter().map(|c| {
                    let (cond,stmts)=match c {SwitchCase::Expression(n)=>(Some(n.expression),&n.statements),SwitchCase::Default(n)=>(None,&n.statements)};
                    Ok(self.node("statement.case",c.span(),json!({"cond":self.optional_expr(cond)?,"stmts":self.statements(stmts.iter())?})))
                }).collect::<Result<Vec<_>>>()?}))
            },
            Statement::Declare(n)=>{
                let stmts=match &n.body {DeclareBody::Statement(Statement::Noop(_))=>Value::Null,DeclareBody::Statement(s)=>json!(self.body(s)?),DeclareBody::ColonDelimited(s)=>json!(self.statements(s.statements.iter())?)};
                self.node("statement.declare",span,json!({"declares":n.items.iter().map(|d|Ok(self.node("declare-item",d.span(),json!({"key":self.identifier(d.name.span),"value":self.expr(d.value)?})))).collect::<Result<Vec<_>>>()?,"stmts":stmts}))
            },
            Statement::Constant(n)=>self.node("statement.const",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"consts":n.items.iter().map(|c|Ok(self.declared(self.node("const",c.span(),json!({"name":self.identifier(c.name.span),"value":self.expr(c.value)?})),c.name.span))).collect::<Result<Vec<_>>>()?})),
            Statement::Global(n)=>self.node("statement.global",span,json!({"vars":n.variables.iter().map(|v|self.variable(v)).collect::<Result<Vec<_>>>()?})),
            Statement::Static(n)=>self.node("statement.static",span,json!({"vars":n.items.iter().map(|item| {
                let (var,value)=match item {StaticItem::Abstract(n)=>(&n.variable,None),StaticItem::Concrete(n)=>(&n.variable,Some(n.value))};
                Ok(self.node("static-var",item.span(),json!({"var":self.direct_variable(var),"default":self.optional_expr(value)?})))
            }).collect::<Result<Vec<_>>>()?})),
            Statement::Unset(n)=>self.node("statement.unset",span,json!({"vars":n.values.iter().map(|v|self.expr(v)).collect::<Result<Vec<_>>>()?})),
            Statement::Inline(n)=>self.node("statement.inline-html",span,json!({"value":text(n.value)})),
            Statement::EchoTag(n)=>self.node("statement.echo",span,json!({"exprs":n.values.iter().map(|e|self.expr(e)).collect::<Result<Vec<_>>>()?})),
            Statement::Goto(n)=>self.node("statement.goto",span,json!({"name":self.identifier(n.label.span)})),
            Statement::Label(n)=>self.node("statement.label",span,json!({"name":self.identifier(n.name.span)})),
            Statement::HaltCompiler(n)=>self.node("statement.halt-compiler",span,json!({"remaining":text(&self.source[n.terminator.span().end.offset as usize..])})),
            _=>return Err(self.unsupported(span,&format!("statement {statement}"))),
        }))
    }

    fn members(&self, members: &Sequence<'_, ClassLikeMember<'_>>) -> Result<Vec<Value>> {
        members.iter().map(|member| {
            let span=member.span();
            Ok(match member {
                ClassLikeMember::Method(n)=>self.node("statement.class-method",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"flags":self.flags(&n.modifiers),"byRef":n.ampersand.is_some(),"name":self.identifier(n.name.span),"params":self.parameters(&n.parameter_list)?,"returnType":self.optional_hint(n.return_type_hint.as_ref().map(|r|&r.hint))?,
                    "stmts":match &n.body {MethodBody::Abstract(_)=>Value::Null,MethodBody::Concrete(b)=>json!(self.statements(b.statements.iter())?)}})),
                ClassLikeMember::TraitUse(n)=> {
                    self.node("statement.trait-use",span,json!({"traits":n.trait_names.iter().map(|n|self.name(n.span(),true)).collect::<Vec<_>>(),"adaptations":match &n.specification {TraitUseSpecification::Abstract(_)=>Vec::new(),TraitUseSpecification::Concrete(c)=>c.adaptations.iter().map(|a|self.adaptation(a)).collect::<Result<Vec<_>>>()?}}))
                },
                ClassLikeMember::Property(Property::Plain(n))=>self.node("statement.property",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"flags":self.flags(&n.modifiers),"type":self.optional_hint(n.hint.as_ref())?,"hooks":[],"props":n.items.iter().map(|item| {
                    let (var,value)=match item {PropertyItem::Abstract(i)=>(&i.variable,None),PropertyItem::Concrete(i)=>(&i.variable,Some(i.value))};
                    Ok(self.node("property-item",item.span(),json!({"name":self.node("var-like-identifier",var.span,json!({"name":text(var.name).trim_start_matches('$')})),"default":self.optional_expr(value)?})))
                }).collect::<Result<Vec<_>>>()?})),
                ClassLikeMember::Constant(n)=>self.node("statement.class-const",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"flags":self.flags(&n.modifiers),"type":self.optional_hint(n.hint.as_ref())?,"consts":n.items.iter().map(|c|Ok(self.node("const",c.span(),json!({"name":self.identifier(c.name.span),"value":self.expr(c.value)?})))).collect::<Result<Vec<_>>>()?})),
                ClassLikeMember::EnumCase(n)=>{
                    let (name,value)=match &n.item {EnumCaseItem::Unit(n)=>(n.name.span,None),EnumCaseItem::Backed(n)=>(n.name.span,Some(n.value))};
                    self.node("statement.enum-case",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"name":self.identifier(name),"expr":self.optional_expr(value)?}))
                },
                ClassLikeMember::Property(Property::Hooked(n))=>self.node("statement.property",span,json!({"attrGroups":self.attrs(&n.attribute_lists)?,"flags":self.flags(&n.modifiers),"type":self.optional_hint(n.hint.as_ref())?,"props":[self.property_item(&n.item)?],"hooks":self.hooks(&n.hook_list)?})),
            })
        }).collect()
    }

    fn property_item(&self, item: &PropertyItem<'_>) -> Result<Value> {
        let (var, value) = match item {
            PropertyItem::Abstract(i) => (&i.variable, None),
            PropertyItem::Concrete(i) => (&i.variable, Some(i.value)),
        };
        Ok(self.node("property-item",item.span(),json!({"name":self.node("var-like-identifier",var.span,json!({"name":text(var.name).trim_start_matches('$')})),"default":self.optional_expr(value)?})))
    }
    fn hooks(&self, list: &PropertyHookList<'_>) -> Result<Vec<Value>> {
        list.hooks.iter().map(|h| {
            let body=match &h.body {
                PropertyHookBody::Abstract(_)=>Value::Null,
                PropertyHookBody::Concrete(PropertyHookConcreteBody::Expression(e))=>self.expr(e.expression)?,
                PropertyHookBody::Concrete(PropertyHookConcreteBody::Block(b))=>json!(self.statements(b.statements.iter())?),
            };
            Ok(self.node("property-hook",h.span(),json!({"attrGroups":self.attrs(&h.attribute_lists)?,"flags":self.flags(&h.modifiers),"byRef":h.ampersand.is_some(),"name":self.identifier(h.name.span),"params":h.parameter_list.as_ref().map(|p|self.parameters(p)).transpose()?.unwrap_or_default(),"body":body})))
        }).collect()
    }
    fn adaptation(&self, adaptation: &TraitUseAdaptation<'_>) -> Result<Value> {
        Ok(match adaptation {
            TraitUseAdaptation::Precedence(n)=>self.node("statement.trait-use-adaptation.precedence",n.span(),json!({"trait":self.name(n.method_reference.trait_name.span(),true),"method":self.identifier(n.method_reference.method_name.span),"insteadof":n.trait_names.iter().map(|n|self.name(n.span(),true)).collect::<Vec<_>>()})),
            TraitUseAdaptation::Alias(n)=>{
                let (trait_name,method)=match &n.method_reference {TraitUseMethodReference::Identifier(n)=>(Value::Null,n.span),TraitUseMethodReference::Absolute(n)=>(self.name(n.trait_name.span(),true),n.method_name.span)};
                let flag=n.modifier.as_ref().map(|m|match m {Modifier::Public(_)=>1,Modifier::Protected(_)=>2,Modifier::Private(_)=>4,Modifier::Final(_)=>32,_=>0});
                self.node("statement.trait-use-adaptation.alias",n.span(),json!({"trait":trait_name,"method":self.identifier(method),"newModifier":flag,"newName":n.alias.as_ref().map(|n|self.identifier(n.span))}))
            },
        })
    }
    fn use_type(kind: &UseType<'_>) -> u8 {
        match kind {
            UseType::Function(_) => 2,
            UseType::Const(_) => 3,
        }
    }
    fn use_item(&self, item: &UseItem<'_>, kind: u8) -> Value {
        self.node("use-item",item.span(),json!({"type":kind,"name":self.name(item.name.span(),false),"alias":item.alias.as_ref().map(|a|self.identifier(a.identifier.span))}))
    }
    fn import(&self, import: &Use<'_>) -> Result<Value> {
        let node = match &import.items {
            UseItems::Sequence(n)=>self.node("statement.use",import.span(),json!({"type":1,"uses":n.items.iter().map(|i|self.use_item(i,0)).collect::<Vec<_>>()})),
            UseItems::TypedSequence(n)=>self.node("statement.use",import.span(),json!({"type":Self::use_type(&n.r#type),"uses":n.items.iter().map(|i|self.use_item(i,0)).collect::<Vec<_>>()})),
            UseItems::TypedList(n)=>self.node("statement.group-use",import.span(),json!({"type":Self::use_type(&n.r#type),"prefix":self.name(n.namespace.span(),false),"uses":n.items.iter().map(|i|self.use_item(i,0)).collect::<Vec<_>>()})),
            UseItems::MixedList(n)=>self.node("statement.group-use",import.span(),json!({"type":0,"prefix":self.name(n.namespace.span(),false),"uses":n.items.iter().map(|i|self.use_item(&i.item,i.r#type.as_ref().map_or(1,Self::use_type))).collect::<Vec<_>>()})),
        };
        let default_type = self.field(&node,"type").as_u64().unwrap_or(0);
        let prefix = self.field(&node,"prefix");
        let prefix = if prefix.is_null() { String::new() } else {self.field(&prefix,"name").as_str().unwrap_or("").to_owned()};
        for item in self.field(&node,"uses").as_array().unwrap() {
            let kind = self.field(item,"type").as_u64().unwrap_or(0);
            let kind = if kind == 0 {default_type} else {kind};
            if kind != 1 {continue;}
            let name = self.field(&self.field(item,"name"),"name").as_str().unwrap_or("").to_owned();
            let alias = self.field(item,"alias");
            let alias = if alias.is_null() {name.rsplit('\\').next().unwrap_or("").to_owned()} else {self.field(&alias,"name").as_str().unwrap_or("").to_owned()};
            let full = if prefix.is_empty() {name} else {format!("{prefix}\\{name}")};
            self.imports.borrow_mut().insert((self.namespace(import.span()).0,alias.to_lowercase()),full);
        }
        Ok(node)
    }
}
