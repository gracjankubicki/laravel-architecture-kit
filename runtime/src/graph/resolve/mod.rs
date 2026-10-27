//! Ordered source dispatch. Basic never executes PHP or Deep resolvers.
mod ai;
mod json;
use super::{
    compose::{State, is_type, value::*},
    model::Value,
};
use serde::{Deserialize, Serialize};
use std::collections::{BTreeMap, HashMap};
#[derive(Clone, Debug, Serialize, Deserialize, PartialEq)]
pub struct Fragment {
    pub input: Value,
    pub reads: BTreeMap<String, String>,
    pub lookups: BTreeMap<String, Vec<String>>,
    pub member_reads: BTreeMap<String, Option<Vec<String>>>,
    pub member_writes: BTreeMap<String, Vec<String>>,
    pub relations: Vec<Value>,
    pub diagnostics: Vec<Value>,
}
pub struct Resolver<'a> {
    pub state: &'a mut State,
    members: HashMap<String, HashMap<String, Vec<String>>>,
    subtypes: HashMap<String, Vec<String>>,
    pub cache: HashMap<String, Vec<String>>,
    pub reads: BTreeMap<String, Vec<String>>,
    pub cache_reads: BTreeMap<String, Option<Vec<String>>>,
    pub cache_writes: BTreeMap<String, Vec<String>>,
    limited: bool,
    failed: bool,
    factory_visits: usize,
    argument_visits: usize,
    return_visits: usize,
    known_traits: Vec<(String, Vec<String>)>,
}
#[derive(Clone)]
struct Method {
    id: String,
    scope: String,
    metadata: Value,
}
impl<'a> Resolver<'a> {
    pub fn new(state: &'a mut State) -> Self {
        let mut members: HashMap<String, HashMap<String, Vec<String>>> = HashMap::new();
        for e in state.elements() {
            let kind = e.get("kind").text();
            let mut parent = e.get("parent").text();
            if ["method", "property"].contains(&kind) && !parent.is_empty() {
                let name = e.get("name").text().rsplit("::").next().unwrap_or("");
                let name = if kind == "method" {
                    lower(name)
                } else {
                    name.into()
                };
                if kind == "property" && state.element(parent).get("kind").text() == "method" {
                    parent = state.element(parent).get("parent").text()
                }
                if !parent.is_empty() {
                    members
                        .entry(parent.into())
                        .or_default()
                        .entry(name)
                        .or_default()
                        .push(e.get("id").text().into())
                }
            }
        }
        let mut this = Self {
            state,
            members,
            subtypes: HashMap::new(),
            cache: HashMap::new(),
            reads: BTreeMap::new(),
            cache_reads: BTreeMap::new(),
            cache_writes: BTreeMap::new(),
            limited: false,
            failed: false,
            factory_visits: 0,
            argument_visits: 0,
            return_visits: 0,
            known_traits: vec![],
        };
        let ids: Vec<_> = this
            .state
            .elements()
            .filter(|e| ["class", "enum"].contains(&e.get("kind").text()))
            .map(|e| e.get("id").text().to_string())
            .collect();
        for id in ids {
            for ancestor in this.ancestors(&id, vec![]) {
                this.subtypes.entry(ancestor).or_default().push(id.clone())
            }
        }
        this
    }
    fn named(&mut self, name: &str) -> Vec<String> {
        let key = format!("name:{}", lower(name));
        if !self.reads.contains_key(&key) {
            let bytes = self
                .state
                .name_rows(name)
                .vals()
                .map(|id| id.text().len() + 48)
                .sum::<usize>();
            if !self.state.charge_aux(bytes + key.len() + 64) {
                return vec![];
            }
        }
        let ids = self.state.named(name);
        self.reads
            .insert(format!("name:{}", lower(name)), ids.clone());
        ids
    }
    fn named_types(&mut self, name: &str) -> Vec<String> {
        self.named(name)
            .into_iter()
            .filter(|id| is_type(self.state.element(id).get("kind").text()))
            .collect()
    }
    fn ancestors(&self, id: &str, mut seen: Vec<String>) -> Vec<String> {
        if seen.contains(&id.to_owned()) || seen.len() >= 32 {
            return vec![];
        }
        seen.push(id.into());
        let mut ids = vec![];
        for p in self.state.positions(id, false) {
            let e = self.state.edge(p);
            let to = e.get("to").text();
            if ["extends", "implements", "uses-trait"].contains(&e.get("kind").text())
                && self.state.has(to)
            {
                ids.push(to.into());
                ids.extend(self.ancestors(to, seen.clone()))
            }
        }
        unique(ids)
    }
    fn trait_selection(
        &self,
        owner: &str,
        method: &str,
        traits: &[String],
    ) -> Option<Vec<(String, String)>> {
        let metadata = self.state.element(owner).get("metadata");
        if metadata.get("trait_adaptations").yes() && metadata.find("trait_rules").is_none() {
            return None;
        }
        let rules = metadata.get("trait_rules");
        let mut aliases = vec![(None, method.to_string())];
        for rule in rules.vals() {
            if rule.get("alias").text() == method {
                aliases.push((
                    if matches!(rule.get("trait"), Value::Null) {
                        None
                    } else {
                        Some(rule.get("trait").text().to_string())
                    },
                    rule.get("method").text().into(),
                ))
            }
        }
        let mut out = vec![];
        for (qualified, name) in aliases {
            for t in traits {
                let tn = self.state.element(t).get("name").text();
                if qualified
                    .as_ref()
                    .is_some_and(|q| !q.eq_ignore_ascii_case(tn))
                {
                    continue;
                }
                let excluded = qualified.is_none()
                    && rules.vals().any(|r| {
                        r.get("method").text() == name
                            && r.get("excluded")
                                .vals()
                                .any(|v| v.text().eq_ignore_ascii_case(tn))
                    });
                if !excluded {
                    out.push((t.clone(), name.clone()))
                }
            }
        }
        Some(out)
    }
    fn member(&mut self, class: &str, name: &str, mut seen: Vec<String>) -> Vec<String> {
        let k = format!("{class}#{name}");
        if !self.cache_reads.contains_key(&k) && !self.cache_writes.contains_key(&k) {
            self.cache_reads
                .insert(k.clone(), self.cache.get(&k).cloned());
        }
        if let Some(v) = self.cache.get(&k) {
            return v.clone();
        }
        if seen.contains(&class.to_string()) || seen.len() >= 32 {
            if seen.len() >= 32 {
                self.limited = true
            }
            return vec![];
        }
        if let Some(v) = self.members.get(class).and_then(|m| m.get(name)) {
            return v.clone();
        }
        seen.push(class.into());
        let (mut traits, mut parents) = (vec![], vec![]);
        for p in self.state.positions(class, false) {
            let e = self.state.edge(p).clone();
            let target = e.get("to").text();
            if !self.state.has(target) {
                continue;
            }
            match e.get("kind").text() {
                "uses-trait" => traits.push(target.to_string()),
                "extends" | "implements" => parents.extend(self.member(target, name, seen.clone())),
                _ => (),
            }
        }
        let Some(selection) = self.trait_selection(class, name, &traits) else {
            return vec![];
        };
        let mut tm = vec![];
        for (t, m) in selection {
            tm.extend(self.member(&t, &m, seen.clone()))
        }
        let tm = unique(tm);
        if tm.len() > 1 {
            let e = self.state.element(class);
            self.state.diagnostic(map([("code",s("trait_method_ambiguous")),("message",s("Trait aliases or declarations expose several source methods under the same name.")),("path",e.get("path").clone()),("line",e.get("line").clone()),("subject",s(class))]))
        }
        let result = if tm.is_empty() { unique(parents) } else { tm };
        if seen.len() == 1 {
            self.cache.insert(k.clone(), result.clone());
            self.cache_writes.insert(k, result.clone());
        }
        result
    }
    fn source_method(&mut self, class: &str, name: &str, mut seen: Vec<String>) -> Option<Method> {
        self.factory_visits += 1;
        if seen.contains(&class.to_string()) || seen.len() >= 32 || self.factory_visits > 20000 {
            self.limited = true;
            return None;
        }
        seen.push(class.into());
        let own = self
            .members
            .get(class)
            .and_then(|m| m.get(name))
            .cloned()
            .unwrap_or_default();
        if !own.is_empty() {
            if own.len() != 1 || self.state.element(&own[0]).get("kind").text() != "method" {
                self.failed = true;
                return None;
            }
            return Some(Method {
                id: own[0].clone(),
                scope: self.state.element(class).get("name").text().into(),
                metadata: self.state.element(&own[0]).get("metadata").clone(),
            });
        }
        let (mut traits, mut parents) = (vec![], vec![]);
        for p in self.state.positions(class, false) {
            let e = self.state.edge(p).clone();
            let target = e.get("to").text();
            match e.get("kind").text() {
                "uses-trait" => {
                    if !self.state.has(target) {
                        let external = e.meta("target_name").text();
                        let rules = self.state.element(class).meta("trait_rules");
                        let adapted = rules.vals().any(|rule| {
                            let unqualified = matches!(rule.get("trait"), Value::Null)
                                && self
                                    .known_traits
                                    .iter()
                                    .find(|(t, _)| t == external)
                                    .is_none_or(|(_, methods)| {
                                        methods.iter().any(|m| m == rule.get("method").text())
                                    });
                            unqualified
                                || rule.get("trait").text().eq_ignore_ascii_case(external)
                                || rule
                                    .get("excluded")
                                    .vals()
                                    .any(|v| v.text().eq_ignore_ascii_case(external))
                        });
                        if self.known_traits.iter().any(|(t, _)| t == external)
                            && !adapted
                            && self.named_types(external).is_empty()
                        {
                            continue;
                        }
                        self.failed = true;
                        return None;
                    }
                    traits.push(target.to_string())
                }
                "extends" if self.state.has(target) => parents.push(target.to_string()),
                _ => (),
            }
        }
        let Some(selection) = self.trait_selection(class, name, &traits) else {
            self.failed = true;
            return None;
        };
        let mut candidates: Vec<Method> = vec![];
        for (t, m) in selection {
            if let Some(mut selected) = self.source_method(&t, &m, seen.clone()) {
                for rule in self.state.element(class).meta("trait_rules").vals() {
                    let alias = if matches!(rule.get("alias"), Value::Null) {
                        rule.get("method").text()
                    } else {
                        rule.get("alias").text()
                    };
                    if rule.get("method").text() == m
                        && alias == name
                        && (matches!(rule.get("trait"), Value::Null)
                            || rule
                                .get("trait")
                                .text()
                                .eq_ignore_ascii_case(self.state.element(&t).get("name").text()))
                        && !matches!(rule.get("visibility"), Value::Null)
                    {
                        selected
                            .metadata
                            .put("visibility", rule.get("visibility").clone())
                    }
                }
                selected.scope = self.state.element(class).get("name").text().into();
                if let Some(old) = candidates.iter_mut().find(|v| v.id == selected.id) {
                    *old = selected
                } else {
                    candidates.push(selected)
                }
            }
        }
        if candidates.len() > 1 {
            self.failed = true;
            return None;
        }
        if !candidates.is_empty() {
            return candidates.pop();
        }
        for parent in parents {
            if let Some(v) = self.source_method(&parent, name, seen.clone()) {
                return Some(v);
            }
        }
        None
    }
    fn related(&mut self, left: &str, right: &str) -> bool {
        if left.eq_ignore_ascii_case(right) {
            return true;
        }
        for (a, b) in [(left, right), (right, left)] {
            for id in self.named_types(a) {
                if self.ancestors(&id, vec![]).iter().any(|id| {
                    self.state
                        .element(id)
                        .get("name")
                        .text()
                        .eq_ignore_ascii_case(b)
                }) {
                    return true;
                }
            }
        }
        false
    }
    fn decode(&self, json: &str) -> Option<serde_json::Value> {
        let mut v = json::decode(json)?;
        let ar = v.as_array_mut()?;
        let positions = if ar.len() == 7 { vec![0, 1] } else { vec![0] };
        for p in positions {
            if let Some(key) = ar
                .get(p)?
                .as_str()
                .and_then(|s| s.strip_prefix("@descriptor:"))
            {
                let receiver = self.state.fields[3].find(key)?.string().ok()?;
                ar[p] = serde_json::Value::String(receiver.into())
            }
        }
        Some(v)
    }
    fn descriptor(&self, json: &str) -> Option<(String, String, bool)> {
        let v = self.decode(json)?;
        let a = v.as_array()?;
        if a.len() != 3 {
            return None;
        }
        Some((
            a[0].as_str()?.into(),
            a[1].as_str()?.into(),
            a[2].as_bool()?,
        ))
    }
    fn strings(json: &str) -> Vec<String> {
        let result: Option<Vec<String>> = serde_json::from_str(json).ok();
        result.filter(|v| v.len() <= 128).unwrap_or_default()
    }
    fn types(&mut self, receiver: &str, depth: usize) -> Vec<String> {
        if depth >= 16 {
            self.limited = true;
            return vec![];
        }
        if let Some(json) = receiver.strip_prefix("@types:") {
            let mut out = vec![];
            for ty in Self::strings(json) {
                out.extend(self.types(&ty, depth + 1))
            }
            return unique(out);
        }
        if receiver.starts_with("@after-argument:") {
            return self
                .argument_receiver(receiver, depth)
                .map(|v| self.types(&v, depth + 1))
                .unwrap_or_default();
        }
        if let Some(pair) = receiver.strip_prefix("@property:") {
            let Some((owner, property)) = pair.rsplit_once('#') else {
                return vec![];
            };
            let owner = if let Some(k) = owner.strip_prefix("@descriptor:") {
                self.state.fields[3].get(k).text()
            } else {
                owner
            }
            .to_string();
            let mut out = vec![];
            for ty in self.types(&owner, depth + 1) {
                let ids = self.named_types(&ty);
                if ids.len() != 1 {
                    continue;
                }
                for p in self.member(&ids[0], &format!("${property}"), vec![]) {
                    let types: Vec<_> = self
                        .state
                        .element(&p)
                        .meta("types")
                        .vals()
                        .map(|v| v.text().to_string())
                        .collect();
                    for ty in types {
                        out.extend(self.types(&ty, depth + 1))
                    }
                }
            }
            return unique(out);
        }
        if receiver.starts_with("@return:") {
            return vec![];
        }
        if let Some(name) = receiver.strip_prefix("@parent:") {
            let mut out = vec![];
            for id in self.named_types(name) {
                for p in self.state.positions(&id, false) {
                    let e = self.state.edge(p);
                    if e.get("kind").text() == "extends" && self.state.has(e.get("to").text()) {
                        out.push(
                            self.state
                                .element(e.get("to").text())
                                .get("name")
                                .text()
                                .to_string(),
                        )
                    }
                }
            }
            return unique(out);
        }
        if receiver.starts_with('@') {
            vec![]
        } else {
            vec![receiver.into()]
        }
    }
    fn callables(
        &mut self,
        receiver: &str,
        method: &str,
        exact: bool,
        depth: usize,
    ) -> Vec<String> {
        if depth >= 16 {
            self.limited = true;
            return vec![];
        }
        if let Some(json) = receiver.strip_prefix("@function:") {
            for name in Self::strings(json) {
                let ids: Vec<_> = self
                    .named(&name)
                    .into_iter()
                    .filter(|id| self.state.element(id).get("kind").text() == "function")
                    .collect();
                if !ids.is_empty() {
                    return ids;
                }
            }
            return vec![];
        }
        if let Some(name) = receiver.strip_prefix("@closure:") {
            return self
                .named(name)
                .into_iter()
                .filter(|id| self.state.element(id).get("kind").text() == "closure")
                .collect();
        }
        if let Some(json) = receiver.strip_prefix("@method-callable:") {
            return self
                .descriptor(json)
                .map(|(r, m, e)| self.callables(&r, &m, e, depth + 1))
                .unwrap_or_default();
        }
        if method == "__invoke" && receiver.starts_with("@return:") {
            let Some((r, m, e)) = self.descriptor(&receiver[8..]) else {
                return vec![];
            };
            let mut out = vec![];
            for v in self.returned_values(receiver, depth + 1) {
                out.extend(self.callables(
                    v.get("receiver").text(),
                    "__invoke",
                    v.get("exact_receiver").yes(),
                    depth + 1,
                ))
            }
            for callable in self.callables(&r, &m, e, depth + 1) {
                let types: Vec<_> = self
                    .state
                    .element(&callable)
                    .meta("return_types")
                    .vals()
                    .map(|v| v.text().to_string())
                    .collect();
                for ty in types {
                    out.extend(self.callables(&ty, "__invoke", false, depth + 1))
                }
            }
            return unique(out);
        }
        let mut out = vec![];
        for ty in self.types(receiver, depth + 1) {
            let ids = self.named_types(&ty);
            if ids.len() != 1 {
                continue;
            }
            let mut classes = vec![ids[0].clone()];
            if !exact {
                classes.extend(self.subtypes.get(&ids[0]).cloned().unwrap_or_default())
            }
            if classes.len() > 1000 {
                self.limited = true
            }
            for class in classes.into_iter().take(1001) {
                let mut found = self.member(&class, &lower(method), vec![]);
                if found.is_empty() && method == "__construct" {
                    found.push(class)
                }
                out.extend(found)
            }
        }
        unique(out)
    }
    fn source_targets(
        &mut self,
        receiver: &str,
        method: &str,
        exact: bool,
        creator: &str,
        form: &str,
        binding: Option<&str>,
    ) -> Vec<String> {
        self.factory_visits = 0;
        self.source_invocation_targets(receiver, method, exact, creator, form, binding)
    }
    fn source_invocation_targets(
        &mut self,
        receiver: &str,
        method: &str,
        exact: bool,
        creator: &str,
        form: &str,
        binding: Option<&str>,
    ) -> Vec<String> {
        let mut out = vec![];
        if form == "function" {
            let found: Vec<_> = self
                .callables(receiver, method, exact, 0)
                .into_iter()
                .filter(|id| {
                    ["function", "closure"].contains(&self.state.element(id).get("kind").text())
                })
                .collect();
            return if found.len() == 1 { found } else { vec![] };
        }
        for ty in self.types(receiver, 0) {
            let bases = self.named_types(&ty);
            if bases.len() != 1 {
                continue;
            }
            let base = &bases[0];
            let is_trait = self.state.element(base).get("kind").text() == "trait";
            let mut classes = vec![base.clone()];
            if !exact || is_trait && binding.is_some() {
                classes.extend(self.subtypes.get(base).cloned().unwrap_or_default())
            }
            if classes.len() > 1000 {
                self.limited = true
            }
            for class in classes.into_iter().take(1000) {
                if is_trait && binding.is_some() && class == *base {
                    continue;
                }
                self.failed = false;
                let selected = self.source_method(&class, &lower(method), vec![]);
                if self.failed {
                    continue;
                }
                let Some(selected) = selected else { continue };
                let creator = if is_trait && binding.is_some() {
                    self.state.element(&class).get("name").text().to_string()
                } else {
                    creator.into()
                };
                let visibility = selected.metadata.get("visibility").text();
                let accessible = visibility == "public"
                    || visibility == "private" && creator.eq_ignore_ascii_case(&selected.scope)
                    || visibility == "protected" && self.related(&creator, &selected.scope);
                if accessible
                    && (form != "static" || selected.metadata.get("static").yes())
                    && !selected.metadata.get("abstract").yes()
                {
                    out.push(selected.id)
                }
            }
        }
        unique(out)
    }
    fn invocation_targets(&mut self, call: &Value, targets: &[String]) -> Vec<String> {
        let m = call.get("metadata");
        let mut receiver = m.get("receiver").text().to_string();
        let mut method = m.get("method").text().to_string();
        let mut exact = m.get("exact_receiver").yes();
        if receiver.starts_with("@return:")
            && m.find("receiver_factory_call")
                .is_some_and(|v| !matches!(v, Value::Null))
            && self
                .factory_producers(&receiver, &plain(m.get("receiver_factory_call")), 0)
                .is_empty()
        {
            return vec![];
        }

        if receiver.starts_with("@function:") || receiver.starts_with("@closure:") {
            return targets.to_vec();
        }
        let bound = m.get("bound_callable");
        let mut creator = if bound.get("scope_bound").yes() {
            bound.get("creator").text()
        } else {
            call.get("from").text()
        };
        let mut scope = String::new();
        for _ in 0..16 {
            let e = self.state.element(creator);
            if !self.state.has(creator) {
                break;
            }
            if ["class", "trait", "enum"].contains(&e.get("kind").text()) {
                scope = e.get("name").text().to_string();
                break;
            }
            creator = e.get("parent").text()
        }
        let mut form = m.get("form").text().to_string();
        let method_callable = receiver.starts_with("@method-callable:");
        if method_callable {
            let Some((r, me, e)) = self.descriptor(&receiver[17..]) else {
                return vec![];
            };
            receiver = r;
            method = me;
            exact = e;
            form = m
                .find("callable_form")
                .map(Value::text)
                .unwrap_or("instance")
                .into()
        }
        let static_form =
            ["class", "self", "parent", "static", "bound-class"].contains(&form.as_str());
        let instance = if method_callable {
            form == "bound-class" && !scope.is_empty() && self.related(&scope, &receiver)
        } else {
            !m.find("static_context").map(Value::yes).unwrap_or(true)
                && !scope.is_empty()
                && (["self", "parent", "static"].contains(&form.as_str())
                    || self.related(&scope, &receiver))
        };
        let binding = if bound
            .find("late")
            .unwrap_or(m.get("late_static_receiver"))
            .yes()
        {
            Some("static".to_string())
        } else {
            let v = bound
                .find("lexical")
                .unwrap_or(m.get("lexical_static_receiver"));
            if matches!(v, Value::Null) {
                None
            } else {
                Some(v.text().to_string())
            }
        };
        let mut selected = self.source_targets(
            &receiver,
            &method,
            exact,
            &scope,
            if static_form && !instance {
                "static"
            } else {
                "instance"
            },
            binding.as_deref(),
        );
        for t in targets {
            if method == "__construct"
                && ["class", "enum"].contains(&self.state.element(t).get("kind").text())
            {
                selected.push(t.clone())
            }
        }
        targets
            .iter()
            .filter(|t| selected.contains(t))
            .cloned()
            .collect()
    }
    fn unknown_constructor(&self, class: &str, mut seen: Vec<String>) -> bool {
        if seen.contains(&class.to_string())
            || seen.len() >= 32
            || self.state.element(class).meta("trait_adaptations").yes()
        {
            return true;
        }
        seen.push(class.into());
        for p in self.state.positions(class, false) {
            let e = self.state.edge(p);
            if ["extends", "uses-trait"].contains(&e.get("kind").text())
                && (!self.state.has(e.get("to").text())
                    || self.unknown_constructor(e.get("to").text(), seen.clone()))
            {
                return true;
            }
        }
        false
    }
    fn external(&mut self, r: &str) -> bool {
        if r.starts_with("@function:") {
            true
        } else if r.starts_with('@') {
            false
        } else {
            self.named_types(r).is_empty()
        }
    }
    fn diagnostic(&mut self, call: &Value, code: &str, message: &str) {
        self.state.diagnostic(map([
            ("code", s(code)),
            ("message", s(message)),
            ("path", call.get("path").clone()),
            ("line", call.get("line").clone()),
            ("subject", call.get("from").clone()),
        ]))
    }
    pub fn operation(&mut self, call: &Value) {
        let phase = super::measure::Span::new("resolve", Some(self.state.owned()));
        self.limited = false;
        self.argument_visits = 0;
        self.reads.clear();
        self.cache_reads.clear();
        self.cache_writes.clear();
        let m = call.get("metadata");
        let receiver = m.get("receiver").text();
        let mut targets = unique(self.callables(
            receiver,
            m.get("method").text(),
            m.get("exact_receiver").yes(),
            0,
        ));
        if !targets.is_empty() && call.get("kind").text() == "calls" {
            let reachable = self.invocation_targets(call, &targets);
            if reachable != targets {
                self.diagnostic(call,"inaccessible_dispatch","Source method selection is inaccessible, abstract, incompatible with the invocation form, or unresolved.");
                if !self.state.preflight(call, targets.len()) {
                    return;
                }
                for t in targets.iter().filter(|t| !reachable.contains(t)) {
                    let mut row = call.clone();
                    row.put("to", s(t));
                    row.put("kind", s("references-call"));
                    row.put("resolution", s("structural"));
                    self.state.add(row)
                }
            }
            targets = reachable
        }
        if targets.len() > 1000 || self.limited {
            self.diagnostic(
                call,
                "dispatch_limit",
                "Dispatch exceeded its candidate, type-expression or inheritance budget.",
            );
            targets.truncate(1000)
        }
        if targets.len() > 1 && receiver.starts_with("@function:") {
            self.diagnostic(
                call,
                "ambiguous_function",
                "Several source declarations match this function call.",
            )
        }
        if !self.state.preflight(call, targets.len().max(1)) {
            return;
        }
        if targets.is_empty() {
            let mut row = call.clone();
            let external = self.external(receiver);
            row.metadata("external", b(external));
            if !external {
                self.diagnostic(
                    call,
                    "unresolved_dispatch",
                    "Known source types do not resolve this call to a unique implementation.",
                )
            }
            self.state.add(row);
            phase.finish(Some(self.state.owned()));
            return;
        }
        for t in &targets {
            let mut row = call.clone();
            row.put("to", s(t));
            row.put(
                "resolution",
                s(if targets.len() == 1 && m.get("exact_receiver").yes() {
                    "resolved"
                } else {
                    "conditional"
                }),
            );
            row.metadata("candidate_count", n(targets.len()));
            row.metadata("execution_proven", b(false));
            if m.get("method").text() == "__construct"
                && ["class", "enum"].contains(&self.state.element(t).get("kind").text())
            {
                row.put("kind", s("constructs"));
                let unknown = self.unknown_constructor(t, vec![]);
                row.metadata("implicit_constructor", b(!unknown));
                if unknown {
                    row.put("resolution", s("conditional"));
                    row.metadata("constructor_unresolved", b(true));
                    self.diagnostic(call,"unresolved_constructor","Object construction is known, but an external ancestor or trait may supply its constructor.")
                }
            }
            self.state.add(row)
        }
        phase.finish(Some(self.state.owned()));
    }

    fn complete_returns(&self, producer: &str) -> bool {
        let summary = self.state.element(producer).meta("return_sites");
        if !summary.get("complete").yes() || summary.get("offsets").rows().is_empty() {
            return false;
        }
        summary.get("offsets").vals().all(|offset| {
            self.state.positions(producer, false).into_iter().any(|p| {
                let e = self.state.edge(p);
                e.get("kind").text() == "returns-value" && e.meta("offset") == offset
            })
        })
    }
    fn factory_producers(
        &mut self,
        receiver: &str,
        call: &serde_json::Value,
        depth: usize,
    ) -> Vec<String> {
        if depth >= 16 {
            self.limited = true;
            return vec![];
        }
        let Some(descriptor) = receiver
            .strip_prefix("@return:")
            .and_then(|json| self.descriptor(json))
        else {
            return vec![];
        };
        let (r, m, e) = descriptor;
        if r.starts_with("@return:") && m == "__invoke" {
            let Some(receiver_call) = call.get("receiver_call").filter(|c| c.is_object()) else {
                return vec![];
            };
            let creators = self.factory_producers(&r, receiver_call, depth + 1);
            if creators.is_empty() || creators.iter().any(|id| !self.complete_returns(id)) {
                return vec![];
            }
            let values =
                self.returned_values_checked(&r, depth + 1, Some(receiver_call), true, true);
            let mut out = vec![];
            for value in values {
                let Some((r, m, e, context)) = self.returned_callable(&value) else {
                    return vec![];
                };
                if context["form"] != "function" && !r.starts_with("@return:") {
                    let types = self.types(&r, depth + 1);
                    if types.is_empty() {
                        return vec![];
                    }
                    for ty in types {
                        let ids = self.named_types(&ty);
                        if ids.len() != 1 {
                            return vec![];
                        }
                        self.factory_visits = 0;
                        self.failed = false;
                        let selected = self.source_method(&ids[0], &lower(&m), vec![]);
                        if self.limited || self.failed || selected.is_none() {
                            return vec![];
                        }
                    }
                }
                let encoded = json::encode(&serde_json::json!([r, m, e]));
                let selected =
                    self.factory_producers(&format!("@return:{encoded}"), &context, depth + 1);
                if selected.is_empty() {
                    return vec![];
                }
                out.extend(selected)
            }
            return unique(out);
        }
        self.source_invocation_targets(
            &r,
            &m,
            e,
            call.get("creator")
                .and_then(serde_json::Value::as_str)
                .unwrap_or(""),
            call.get("form")
                .and_then(serde_json::Value::as_str)
                .unwrap_or(""),
            call.get("binding").and_then(serde_json::Value::as_str),
        )
    }
    fn returned_values(&mut self, receiver: &str, depth: usize) -> Vec<Value> {
        self.returned_values_checked(receiver, depth, None, false, false)
    }
    fn returned_values_checked(
        &mut self,
        receiver: &str,
        depth: usize,
        call: Option<&serde_json::Value>,
        checked: bool,
        complete: bool,
    ) -> Vec<Value> {
        if !receiver.starts_with("@return:") {
            return vec![];
        }
        if depth >= 16 {
            self.limited = true;
            return vec![];
        }
        self.return_visits += 1;
        if self.return_visits > 10000 {
            self.limited = true;
            return vec![];
        }
        let Some((r, m, e)) = self.descriptor(&receiver[8..]) else {
            return vec![];
        };
        if checked && call.is_none() {
            return vec![];
        }
        let producers = if let Some(call) = call {
            self.factory_producers(receiver, call, depth)
        } else {
            self.callables(&r, &m, e, depth + 1)
        };
        let mut values = vec![];
        for producer in producers {
            if complete && !self.complete_returns(&producer) {
                return vec![];
            }
            for p in self.state.positions(&producer, false) {
                let edge = self.state.edge(p).clone();
                if edge.get("kind").text() != "returns-value" {
                    continue;
                }
                let source = map([
                    ("path", edge.get("path").clone()),
                    ("line", edge.get("line").clone()),
                    ("end_line", edge.get("end_line").clone()),
                    ("producer", s(&producer)),
                    ("offset", edge.meta("offset").clone()),
                ]);
                let context = edge
                    .get("metadata")
                    .find("factory_call")
                    .filter(|v| !matches!(v, Value::Null))
                    .map(plain);
                let nested = self.returned_values_checked(
                    edge.meta("receiver").text(),
                    depth + 1,
                    if checked { context.as_ref() } else { None },
                    checked,
                    complete,
                );
                if nested.is_empty() {
                    let mut v = edge.get("metadata").clone();
                    v.put("sources", list([source]));
                    values.push(v)
                } else {
                    for mut v in nested {
                        let mut sources = vec![source.clone()];
                        sources.extend(v.get("sources").vals().cloned());
                        v.put("sources", list(sources));
                        values.push(v)
                    }
                }
                if values.len() >= 1000 {
                    self.limited = true;
                    values.truncate(1000);
                    return values;
                }
            }
        }
        values
    }
    fn argument_receiver(&mut self, receiver: &str, depth: usize) -> Option<String> {
        if depth == 0 {
            self.argument_visits = 0
        }
        self.argument_visits += 1;
        if depth >= 16 || self.argument_visits > 10000 {
            self.limited = true;
            return None;
        }
        let json = receiver.strip_prefix("@after-argument:")?;
        let v = self.decode(json)?;
        let a = v.as_array()?;
        if a.len() != 7 {
            return None;
        }
        let original = a[0].as_str()?;
        let target = a[1].as_str()?;
        let method = a[2].as_str()?;
        let exact = a[3].as_bool()?;
        let context = a[4].as_object()?;
        let args = a[5].as_array()?;
        let positions = a[6].as_array()?;
        if args.len() > 128
            || positions.is_empty()
            || positions.len() > 128
            || context.len() < 3
            || context.len() > 4
        {
            return None;
        }
        let creator = context.get("creator")?.as_str()?;
        let form = context.get("form")?.as_str()?;
        if !["function", "instance", "static"].contains(&form) {
            return None;
        }
        let binding = context.get("binding")?;
        let binding = if binding.is_null() {
            None
        } else {
            Some(binding.as_str()?)
        };
        if binding.is_some_and(|b| !["self", "parent", "static"].contains(&b)) {
            return None;
        }
        for arg in args {
            let ob = arg.as_object()?;
            if ob.len() != 3
                || !matches!(
                    arg.get("name")?,
                    serde_json::Value::Null | serde_json::Value::String(_)
                )
                || arg.get("unpack")?.as_bool() != Some(false)
                || arg.get("by_ref")?.as_bool() != Some(false)
            {
                return None;
            }
        }
        for p in positions {
            let i = p.as_u64()? as usize;
            if i >= args.len() {
                return None;
            }
        }
        if target.starts_with("@return:") {
            return self.returned_argument(a, depth);
        }
        if self.ai_setter(a, depth) {
            return Some(original.into());
        }
        if form != "function" {
            for ty in self.types(target, depth + 1) {
                let ids = self.named_types(&ty);
                if ids.len() != 1 {
                    return None;
                }
                self.factory_visits = 0;
                self.failed = false;
                let found = self.source_method(&ids[0], &lower(method), vec![]);
                if self.failed || self.limited || found.is_none() {
                    return None;
                }
            }
        }
        let selected = self.source_targets(target, method, exact, creator, form, binding);
        if self.limited || selected.is_empty() {
            return None;
        }
        for t in selected {
            let signature = self.state.element(&t).meta("call_parameters");
            if !source_arguments(signature, args) {
                return None;
            }
            let parameters: Vec<_> = signature.get("parameters").vals().collect();
            let variadic = parameters.last().is_some_and(|p| p.get("variadic").yes());
            for p in positions {
                let i = p.as_u64()? as usize;
                let param = if let Some(name) = args[i]["name"].as_str() {
                    parameters.iter().position(|p| p.get("name").text() == name)
                } else {
                    Some(i)
                };
                let param = match param {
                    Some(i) if i < parameters.len() => Some(i),
                    _ if variadic => parameters.len().checked_sub(1),
                    _ => None,
                };
                if param.is_none_or(|i| parameters[i].get("by_ref").yes()) {
                    return None;
                }
            }
        }
        Some(original.into())
    }
    fn returned_argument(&mut self, a: &[serde_json::Value], depth: usize) -> Option<String> {
        let call = a[4].get("factory_call")?;
        let creator = call.get("creator")?.as_str()?;
        let form = call.get("form")?.as_str()?;
        let binding = call.get("binding")?.as_str();
        let (r, m, e) = self.descriptor(&a[1].as_str()?[8..])?;
        if form != "function" && !(r.starts_with("@return:") && m == "__invoke") {
            let types = self.types(&r, depth + 1);
            if types.is_empty() {
                return None;
            }
            for ty in types {
                let ids = self.named_types(&ty);
                if ids.len() != 1 {
                    return None;
                }
                self.failed = false;
                self.factory_visits = 0;
                let method = self.source_method(&ids[0], &lower(&m), vec![]);
                if self.limited || self.failed || method.is_none() {
                    return None;
                }
            }
        }
        let targets = self.source_targets(&r, &m, e, creator, form, binding);
        if self.limited || targets.is_empty() {
            return None;
        }
        for producer in targets {
            let summary = self.state.element(&producer).meta("return_sites").clone();
            if !summary.get("complete").yes() || summary.get("offsets").rows().is_empty() {
                return None;
            }
            for offset in summary.get("offsets").vals() {
                let returned = self
                    .state
                    .positions(&producer, false)
                    .into_iter()
                    .map(|p| self.state.edge(p))
                    .find(|e| {
                        e.get("kind").text() == "returns-value" && e.meta("offset") == offset
                    })?
                    .get("metadata")
                    .clone();
                let candidate = self.returned_callable(&returned)?;
                let next = serde_json::json!([
                    a[0],
                    candidate.0,
                    candidate.1,
                    candidate.2,
                    candidate.3,
                    a[5],
                    a[6]
                ]);
                self.argument_receiver(
                    &format!("@after-argument:{}", json::encode(&next)),
                    depth + 1,
                )?;
            }
        }
        Some(a[0].as_str()?.into())
    }
    fn returned_callable(&self, v: &Value) -> Option<(String, String, bool, serde_json::Value)> {
        let mut receiver = v.get("receiver").text().to_string();
        let mut method = "__invoke".to_string();
        let mut exact = v.get("exact_receiver").yes();
        let mut context = serde_json::json!({"creator":"","form":"instance","binding":null});
        if receiver.starts_with("@return:") {
            return None;
        }
        if receiver.starts_with("@function:") || receiver.starts_with("@closure:") {
            context["form"] = "function".into()
        } else if receiver.starts_with("@method-callable:") {
            let bound = v.get("bound_callable");
            let (r, m, e) = self.descriptor(&receiver[17..])?;
            receiver = r;
            method = m;
            exact = e;
            let mut creator = bound.get("creator").text();
            if !bound.get("scope_bound").yes()
                || !self.state.has(creator)
                || !["static", "instance", "bound-class"].contains(&v.get("callable_form").text())
            {
                return None;
            }
            let mut scope = "";
            for _ in 0..16 {
                let e = self.state.element(creator);
                if !self.state.has(creator) {
                    break;
                }
                if ["class", "trait", "enum"].contains(&e.get("kind").text()) {
                    scope = e.get("name").text();
                    break;
                }
                creator = e.get("parent").text()
            }
            context = serde_json::json!({"creator":scope,"form":if v.get("callable_form").text()=="static"{"static"}else{"instance"},"binding":if bound.get("late").yes(){Some("static")}else{bound.find("lexical").and_then(|v|v.string().ok())}})
        }
        Some((receiver, method, exact, context))
    }
}
fn source_arguments(signature: &Value, args: &[serde_json::Value]) -> bool {
    if !signature.get("complete").yes() {
        return false;
    }
    let params: Vec<_> = signature.get("parameters").vals().collect();
    let variadic = params.last().is_some_and(|p| p.get("variadic").yes());
    let mut seen = vec![];
    let mut named = false;
    for (i, arg) in args.iter().enumerate() {
        let provided = arg["name"].as_str();
        let name = provided.or_else(|| params.get(i).map(|p| p.get("name").text()));
        if arg["unpack"].as_bool() != Some(false)
            || arg["by_ref"].as_bool() != Some(false)
            || named && provided.is_none()
            || name.is_some_and(|n| seen.contains(&n))
        {
            return false;
        }
        if (name.is_none() || !params.iter().any(|p| Some(p.get("name").text()) == name))
            && !variadic
        {
            return false;
        }
        if let Some(name) = name {
            seen.push(name)
        }
        named |= provided.is_some()
    }
    params
        .iter()
        .all(|p| !p.get("required").yes() || seen.contains(&p.get("name").text()))
}

fn plain(value: &Value) -> serde_json::Value {
    match value {
        Value::Null => serde_json::Value::Null,
        Value::Bool(v) => (*v).into(),
        Value::Int(v) => (*v).into(),
        Value::Float(v) => serde_json::Number::from_f64(*v)
            .map(serde_json::Value::Number)
            .unwrap_or(serde_json::Value::Null),
        Value::String(v) => v.to_string().into(),
        Value::Array {
            list: true,
            entries,
        } => serde_json::Value::Array(entries.iter().map(|(_, v)| plain(v)).collect()),
        Value::Array { entries, .. } => serde_json::Value::Object(
            entries
                .iter()
                .map(|(k, v)| (key_string(k), plain(v)))
                .collect(),
        ),
    }
}
