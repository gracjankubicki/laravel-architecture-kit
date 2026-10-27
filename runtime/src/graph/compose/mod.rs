//! Basic foundation. Source order, PHP key types and relation positions are observable.
pub mod constants;
pub mod enrichment;
pub mod sort;
pub mod value;
use super::{Result, model::Value, store::Generation};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use value::*;
pub const FIELDS: [&str; 8] = [
    "elements",
    "relations",
    "diagnostics",
    "descriptorReceivers",
    "names",
    "out",
    "in",
    "hierarchyLimits",
];
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct State {
    #[serde(skip)]
    cached_owned: usize,
    #[serde(skip)]
    pub work_limit: usize,
    #[serde(skip)]
    pub work_used: usize,
    #[serde(skip)]
    pub failure: Option<&'static str>,
    pub fields: [Value; 8],
    #[serde(skip)]
    pub ids: HashMap<String, usize>,
    #[serde(skip)]
    names_index: HashMap<String, usize>,
    #[serde(skip)]
    out_index: HashMap<String, usize>,
    #[serde(skip)]
    in_index: HashMap<String, usize>,
}
impl Default for State {
    fn default() -> Self {
        Self {
            cached_owned: 0,
            work_limit: usize::MAX,
            work_used: 0,
            failure: None,
            fields: std::array::from_fn(|_| empty()),
            ids: HashMap::new(),
            names_index: HashMap::new(),
            out_index: HashMap::new(),
            in_index: HashMap::new(),
        }
    }
}
impl State {
    pub fn ensure(&self) -> Result<()> {
        self.failure.map_or(Ok(()), |e| Err(e.into()))
    }
    pub fn preflight(&mut self, value: &Value, count: usize) -> bool {
        let bytes = value
            .allocation_upper()
            .checked_add(256)
            .and_then(|v| v.checked_mul(count))
            .and_then(|v| v.checked_mul(4));
        if self.failure.is_some()
            || bytes
                .and_then(|b| self.work_used.checked_add(b))
                .is_none_or(|v| v > self.work_limit)
        {
            self.failure = Some("transition_budget");
            false
        } else {
            true
        }
    }
    pub fn charge_aux(&mut self, bytes: usize) -> bool {
        if self.failure.is_some()
            || bytes
                .checked_mul(4)
                .and_then(|b| self.work_used.checked_add(b))
                .is_none_or(|v| v > self.work_limit)
        {
            self.failure = Some("transition_budget");
            false
        } else {
            self.work_used += bytes * 4;
            true
        }
    }

    pub fn element(&self, id: &str) -> &Value {
        self.ids
            .get(id)
            .map(|i| &self.fields[0].rows()[*i].1)
            .unwrap_or(&Value::Null)
    }
    pub fn has(&self, id: &str) -> bool {
        self.ids.contains_key(id)
    }
    pub fn elements(&self) -> impl Iterator<Item = &Value> {
        self.fields[0].vals()
    }
    pub fn rebuild(&mut self) -> Result<()> {
        for field in &self.fields {
            field.entries()?;
        }
        self.ids.clear();
        self.names_index.clear();
        self.out_index.clear();
        self.in_index.clear();
        for (field, index) in [
            (4, &mut self.names_index),
            (5, &mut self.out_index),
            (6, &mut self.in_index),
        ] {
            for (i, (k, _)) in self.fields[field].rows().iter().enumerate() {
                index.insert(key_string(k), i);
            }
        }
        for (i, (k, e)) in self.fields[0].rows().iter().enumerate() {
            let id = e.field("id")?.string()?;
            if key(id) != *k || self.ids.insert(id.into(), i).is_some() {
                return Err("basic_element_identity".into());
            }
        }
        Ok(())
    }
    pub fn name_rows(&self, name: &str) -> &Value {
        self.names_index
            .get(&lower(name))
            .map(|i| &self.fields[4].rows()[*i].1)
            .unwrap_or(&Value::Null)
    }
    pub fn named(&self, name: &str) -> Vec<String> {
        self.names_index
            .get(&lower(name))
            .map(|i| {
                self.fields[4].rows()[*i]
                    .1
                    .vals()
                    .map(|v| v.text().into())
                    .collect()
            })
            .unwrap_or_default()
    }
    pub fn put_element(&mut self, id: &str, row: Value) {
        let credit = self
            .element(id)
            .allocation_upper()
            .saturating_add(if self.has(id) { 256 } else { 0 })
            .saturating_mul(4);
        self.work_used = self.work_used.saturating_sub(credit);
        if !self.charge_aux(row.allocation_upper() + 256) {
            return;
        }
        put_indexed(&mut self.fields[0], &mut self.ids, id, row)
    }
    pub fn add_name(&mut self, name: &str, id: &str) {
        let name = lower(name);
        if !self.named(&name).iter().any(|v| v == id) {
            append_indexed(&mut self.fields[4], &mut self.names_index, &name, s(id))
        }
    }
    pub fn types(&self, name: &str) -> Vec<String> {
        self.named(name)
            .into_iter()
            .filter(|id| is_type(self.element(id).get("kind").text()))
            .collect()
    }
    pub fn positions(&self, id: &str, reverse: bool) -> Vec<usize> {
        let (field, index) = if reverse {
            (6, &self.in_index)
        } else {
            (5, &self.out_index)
        };
        index
            .get(id)
            .map(|i| {
                self.fields[field].rows()[*i]
                    .1
                    .vals()
                    .map(Value::number)
                    .collect()
            })
            .unwrap_or_default()
    }
    pub fn edge(&self, i: usize) -> &Value {
        &self.fields[1].rows()[i].1
    }
    pub fn add(&mut self, row: Value) {
        if !self.charge_aux(row.allocation_upper() + 256) {
            return;
        }
        let position = self.fields[1].rows().len();
        let mut delta = row.owned();
        for (field, index, endpoint) in [
            (5, &mut self.out_index, "from"),
            (6, &mut self.in_index, "to"),
        ] {
            let id = row.get(endpoint).text();
            let old_top = self.fields[field].container_bytes();
            let old_index = index.capacity();
            let old_vector = index
                .get(id)
                .map(|i| self.fields[field].rows()[*i].1.container_bytes())
                .unwrap_or(0);
            let new_key = !index.contains_key(id);
            append_indexed(&mut self.fields[field], index, id, n(position));
            let i = index[id];
            delta += self.fields[field].container_bytes() - old_top
                + self.fields[field].rows()[i].1.container_bytes()
                - old_vector
                + (index.capacity() - old_index) * 64
                + if new_key { id.len() } else { 0 };
        }
        let before = self.fields[1].container_bytes();
        self.fields[1].push(row);
        delta += self.fields[1].container_bytes() - before;
        self.cached_owned += delta;
    }
    pub fn diagnostic(&mut self, row: Value) {
        if !self.charge_aux(row.allocation_upper() + 64) {
            return;
        }
        let before = self.fields[2].container_bytes();
        let payload = row.owned();
        self.fields[2].push(row);
        self.cached_owned += payload + self.fields[2].container_bytes() - before;
    }
    pub fn owned(&self) -> usize {
        self.cached_owned
    }
    pub fn refresh_owned(&mut self) {
        self.cached_owned = self.owned_full();
    }
    pub fn owned_full(&self) -> usize {
        self.fields.iter().map(Value::owned).sum::<usize>()
            + self.ids.capacity() * 64
            + self.ids.keys().map(String::capacity).sum::<usize>()
            + [&self.names_index, &self.out_index, &self.in_index]
                .iter()
                .map(|m| m.capacity() * 64 + m.keys().map(String::capacity).sum::<usize>())
                .sum::<usize>()
    }
    pub fn complete(&self) -> bool {
        !self.fields[2].vals().any(|d| {
            let c = d.get("code").text();
            c.contains("limit") || c.contains("parse_error") || c.contains("changed_inputs")
        })
    }
    pub fn validate(&mut self) -> Result<()> {
        self.rebuild()?;
        let mut out = empty();
        let mut incoming = empty();
        let mut out_index = HashMap::new();
        let mut in_index = HashMap::new();
        for (i, e) in self.fields[1].vals().enumerate() {
            let from = e.field("from")?.string()?;
            let to = e.field("to")?.string()?;
            if !self.has(from) {
                return Err("basic_relation_source".into());
            }
            append_indexed(&mut out, &mut out_index, from, n(i));
            append_indexed(&mut incoming, &mut in_index, to, n(i));
        }

        if out != self.fields[5] || incoming != self.fields[6] {
            return Err("basic_adjacency".into());
        }
        for (_, ids) in self.fields[4].rows() {
            for id in ids.vals() {
                if !self.has(id.string()?) {
                    return Err("basic_name_reference".into());
                }
            }
        }
        Ok(())
    }
}
pub fn is_type(k: &str) -> bool {
    matches!(k, "class" | "interface" | "trait" | "enum")
}
pub struct Foundation {
    pub state: State,
    pub calls: Vec<Value>,
    pub registrations: Vec<Value>,
}
pub fn foundation(g: &mut Generation, work_limit: usize) -> Result<Foundation> {
    let phase = super::measure::Span::new("foundation", Some(g.owned()));
    enrichment::validate(g)?;
    let mut state = State::default();
    state.work_limit = work_limit;
    let mut calls = vec![];
    let (mut bindings, mut http, mut execution) = (vec![], vec![], vec![]);
    for r in &g.records {
        if !r.options.catalog {
            continue;
        }
        let c = &r.entry.catalog;
        if matches!(c, Value::Null) {
            continue;
        }
        for (k, v) in c.get("descriptor_receivers").rows() {
            state.fields[3].put_key(k.clone(), v.clone())
        }
        for e in c.get("elements").vals() {
            let id = e.field("id")?.string()?;
            if !state.preflight(e, 1) || !state.charge_aux(r.owner.len().saturating_mul(2)) {
                return Err("transition_budget".into());
            }
            let mut row = e.clone();
            row.put("path", s(&r.owner));
            row.put("knowledge", s("static"));
            row.put("role_evidence", empty());
            let mut sources = state.element(id).get("sources").clone();
            sources.push(map([
                ("path", s(&r.owner)),
                ("line", e.get("line").clone()),
                ("end_line", e.get("end_line").clone()),
            ]));
            row.put("sources", sources);
            state.put_element(id, row);
            state.ensure()?;
            state.add_name(e.get("name").text(), id);
        }
        for d in c.get("diagnostics").vals() {
            let mut row = d.clone();
            row.put("path", s(&r.owner));
            state.diagnostic(row)
        }
    }
    state.rebuild()?;
    let files = &g.canonical_files;
    for r in &g.records {
        if !r.options.catalog {
            continue;
        }
        for e in r.entry.catalog.get("relations").vals() {
            if !state.preflight(e, 1) {
                return Err("transition_budget".into());
            }
            let mut row = e.clone();
            row.put("path", s(&r.owner));
            row.put("knowledge", s("static"));
            let to = row.get("to").text().to_owned();
            if to.starts_with("execution:") {
                if !state.charge_aux(row.allocation_upper() + 64) {
                    return Err("transition_budget".into());
                }
                execution.push(row);
                continue;
            }
            if to.starts_with("http:") {
                if !state.charge_aux(row.allocation_upper() + 64) {
                    return Err("transition_budget".into());
                }
                http.push(row);
                continue;
            }
            if to.starts_with("container:") {
                if !state.charge_aux(row.allocation_upper() + 64) {
                    return Err("transition_budget".into());
                }
                bindings.push(row);
                continue;
            }
            if to.starts_with("dispatch:") {
                if !state.charge_aux(row.allocation_upper() + 64) {
                    return Err("transition_budget".into());
                }
                calls.push(row);
                continue;
            }
            if to.starts_with("include:") {
                let candidates: Vec<_> = row
                    .meta("candidate_paths")
                    .vals()
                    .filter_map(|v| files.get(v.text()).filter(|id| state.has(id)))
                    .cloned()
                    .collect();
                if candidates.is_empty() {
                    state.add(row.clone());
                    state.diagnostic(map([("code",s("include_outside_graph")),("message",s("No relative include candidate is present in the declared source graph.")),("path",s(&r.owner)),("line",row.get("line").clone()),("subject",row.get("from").clone())]));
                } else {
                    if !state.preflight(&row, candidates.len()) {
                        return Err("transition_budget".into());
                    }
                    for id in &candidates {
                        let mut a = row.clone();
                        a.put("to", s(id));
                        a.put("resolution", s("conditional"));
                        a.metadata("candidate_count", n(candidates.len()));
                        state.add(a)
                    }
                }
                continue;
            }
            if let Some(path) = to.strip_prefix("file:") {
                if let Some(id) = files.get(path).filter(|id| state.has(id)) {
                    row.put("to", s(id))
                } else {
                    row.put("resolution", s("conditional"));
                    state.diagnostic(map([("code",s("include_outside_graph")),("message",s(format!("Included file is absent or outside the declared source graph: {path}"))),("path",s(&r.owner)),("line",row.get("line").clone()),("subject",row.get("from").clone())]))
                }
            }
            if let Some(target) = to.strip_prefix("php:") {
                let ids = state.types(target);
                if ids.len() == 1 {
                    row.put("to", s(&ids[0]))
                } else {
                    row.put("resolution", s("conditional"));
                    row.metadata("target_name", s(target));
                    row.metadata("external", b(ids.is_empty()));
                    if ids.len() > 1 {
                        state.diagnostic(map([
                            ("path", s(&r.owner)),
                            ("line", row.get("line").clone()),
                            ("code", s("ambiguous_type")),
                            (
                                "message",
                                s(format!("Several declarations match type {target}.")),
                            ),
                            ("subject", row.get("from").clone()),
                        ]))
                    }
                }
            }
            state.add(row);
        }
    }
    composer(&mut state);
    classify(&mut state);
    bindings.extend(http);
    bindings.extend(execution);
    for v in &mut state.fields {
        g.intern_value(v)
    }
    for v in calls.iter_mut().chain(bindings.iter_mut()) {
        g.intern_value(v)
    }
    state.ensure()?;
    let final_bound = state
        .fields
        .iter()
        .map(Value::allocation_upper)
        .sum::<usize>()
        .checked_mul(4)
        .ok_or("budget_overflow")?;
    if final_bound > state.work_limit {
        return Err("transition_budget".into());
    }
    state.work_used = state.work_used.max(final_bound);
    state.refresh_owned();
    phase.finish(Some(
        g.owned()
            + state.owned()
            + calls
                .iter()
                .chain(bindings.iter())
                .map(Value::owned)
                .sum::<usize>(),
    ));
    Ok(Foundation {
        state,
        calls,
        registrations: bindings,
    })
}
fn external_parents(name: &str) -> &'static [&'static str] {
    match name {
        "saloon\\http\\solorequest" => &[
            "Saloon\\Http\\Request",
            "Saloon\\Traits\\Request\\HasConnector",
        ],
        "saloon\\http\\connectors\\nullconnector" => &["Saloon\\Http\\Connector"],
        "illuminate\\database\\eloquent\\relations\\pivot" => {
            &["Illuminate\\Database\\Eloquent\\Model"]
        }
        "illuminate\\database\\eloquent\\relations\\morphpivot" => &[
            "Illuminate\\Database\\Eloquent\\Relations\\Pivot",
            "Illuminate\\Database\\Eloquent\\Model",
        ],
        _ => &[],
    }
}
pub(crate) fn contracts(st: &mut State, id: &str, mut seen: Vec<String>) -> Value {
    if seen.iter().any(|s| s == id) || seen.len() >= 32 {
        if seen.len() >= 32 && !st.fields[7].get(id).yes() {
            st.fields[7].put(id, b(true));
            let e = st.element(id);
            let d = map([
                ("code", s("inheritance_limit")),
                (
                    "message",
                    s("Inheritance and trait analysis reached its depth limit of 32."),
                ),
                ("path", e.get("path").clone()),
                ("line", e.get("line").clone()),
                ("subject", s(id)),
            ]);
            st.diagnostic(d)
        }
        return empty();
    }
    seen.push(id.into());
    let mut out = empty();
    for p in st.positions(id, false) {
        let edge = st.edge(p).clone();
        if !["extends", "implements", "uses-trait"].contains(&edge.get("kind").text()) {
            continue;
        }
        let target = edge.get("to").text();
        let name = if st.has(target) {
            st.element(target).get("name")
        } else {
            edge.meta("target_name")
        };
        if let Value::String(name) = name {
            let name = lower(name);
            let evidence = map([
                ("path", edge.get("path").clone()),
                ("line", edge.get("line").clone()),
            ]);
            out.put(&name, evidence.clone());
            let ancestors = external_parents(&name);
            if !st.has(target) && ancestors.iter().all(|p| st.types(p).is_empty()) {
                for parent in ancestors {
                    let mut v = evidence.clone();
                    v.put("basis", s("framework-declared-ancestry"));
                    out.put(&lower(parent), v)
                }
            }
        }
        if st.has(target) {
            let nested = contracts(st, target, seen.clone());
            for (k, v) in nested.rows() {
                if out.find(&key_string(k)).is_none() {
                    out.put_key(k.clone(), v.clone())
                }
            }
        }
    }
    out
}
fn classify(st: &mut State) {
    let ids: Vec<_> = st
        .elements()
        .filter(|e| is_type(e.get("kind").text()))
        .map(|e| e.get("id").text().to_string())
        .collect();
    for id in ids {
        let mut row = st.element(&id).clone();
        let mut roles = row.get("roles").clone();
        let mut evidence = empty();
        for (folder, role) in constants::PLACEMENT_ROLES {
            if format!("/{}", row.get("path").text()).contains(&format!("/{folder}/")) {
                roles.push(s(role));
                evidence.push(map([
                    ("role", s(role)),
                    ("basis", s("placement-convention")),
                    ("path", row.get("path").clone()),
                    ("line", row.get("line").clone()),
                ]))
            }
        }
        let cs = contracts(st, &id, vec![]);
        for (k, v) in cs.rows() {
            let contract = key_string(k);
            if let Some((_, role)) = constants::CONTRACT_ROLES
                .iter()
                .find(|(c, _)| *c == contract)
            {
                roles.push(s(role));
                let mut ev = map([
                    ("role", s(role)),
                    ("basis", s("php-contract")),
                    ("contract", s(&contract)),
                ]);
                for (k, v) in v.rows() {
                    ev.put_key(k.clone(), v.clone())
                }
                evidence.push(ev)
            }
        }
        row.put(
            "roles",
            list(
                unique(roles.vals().map(|r| r.text().into()).collect())
                    .into_iter()
                    .map(s),
            ),
        );
        row.put("role_evidence", evidence);
        if cs
            .find("illuminate\\contracts\\queue\\shouldqueue")
            .is_some()
            || cs
                .find("illuminate\\contracts\\queue\\shouldqueueaftercommit")
                .is_some()
        {
            row.metadata("queueable", b(true))
        }
        let i = st.ids[&id];
        if let Value::Array { entries, .. } = &mut st.fields[0] {
            entries[i].1 = row
        }
    }
}
fn composer(st: &mut State) {
    let mut packages = empty();
    let es: Vec<_> = st.elements().cloned().collect();
    for e in &es {
        if e.get("kind").text() != "composer-package" {
            continue;
        }
        let name = e.get("name").text();
        let mut states = packages.get(name).clone();
        states.put(e.meta("state").text(), e.clone());
        packages.put(name, states);
        if let Some(reference) =
            constants::inspected_reference(name, e.meta("normalized_version").text())
        {
            if e.meta("reference_state").text() == "known"
                && e.meta("source_reference").text() != reference
            {
                st.diagnostic(map([("code",s("composer_profile_reference_mismatch")),("message",s(format!("Package source reference differs from the inspected contract baseline for {name}."))),("path",e.get("path").clone()),("line",e.get("line").clone()),("subject",e.get("id").clone())]))
            }
        }
    }
    for (_, states) in packages.rows() {
        let locked = states.get("locked");
        let installed = states.get("installed");
        if matches!(locked, Value::Null) || matches!(installed, Value::Null) {
            continue;
        }
        for (code, msg, condition) in [
            (
                "composer_version_mismatch",
                "Locked and installed versions differ for ",
                !matches!(locked.meta("normalized_version"), Value::Null)
                    && !matches!(installed.meta("normalized_version"), Value::Null)
                    && locked.meta("normalized_version") != installed.meta("normalized_version"),
            ),
            (
                "composer_reference_mismatch",
                "Locked and installed source references differ for ",
                locked.meta("reference_state").text() == "known"
                    && installed.meta("reference_state").text() == "known"
                    && locked.meta("source_reference") != installed.meta("source_reference"),
            ),
        ] {
            if condition {
                st.diagnostic(map([
                    ("code", s(code)),
                    (
                        "message",
                        s(format!("{msg}{}.", installed.get("name").text())),
                    ),
                    ("path", installed.get("path").clone()),
                    ("line", installed.get("line").clone()),
                    ("subject", installed.get("id").clone()),
                    (
                        "sources",
                        list(
                            locked
                                .get("sources")
                                .vals()
                                .chain(installed.get("sources").vals())
                                .cloned(),
                        ),
                    ),
                ]))
            }
        }
    }
    for e in &es {
        if e.get("kind").text() != "composer-dependency" {
            continue;
        }
        for p in packages.get(e.get("name").text()).vals() {
            st.add(map([
                ("from", e.get("id").clone()),
                ("to", p.get("id").clone()),
                ("kind", s("references-package-version")),
                ("path", e.get("path").clone()),
                ("line", e.get("line").clone()),
                ("end_line", e.get("end_line").clone()),
                ("resolution", s("conditional")),
                ("knowledge", s("static")),
                (
                    "metadata",
                    map([
                        ("state", p.meta("state").clone()),
                        ("constraint_evaluated", b(false)),
                        ("execution_proven", b(false)),
                    ]),
                ),
            ]));
        }
    }
}
