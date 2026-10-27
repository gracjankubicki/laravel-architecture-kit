//! Directed source-only context, path and impact with explicit traversal budgets.
pub mod discovery;
use super::{
    compose::{State, constants, value::*},
    model::Value,
    search::{element_cmp, trim},
};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Query {
    #[serde(rename = "type")]
    pub operation: String,
    #[serde(default)]
    pub query: Option<String>,
    #[serde(default)]
    pub kind: Option<String>,
    #[serde(default)]
    pub path: Option<String>,
    #[serde(default)]
    pub subject: Option<String>,
    #[serde(default)]
    pub target: Option<String>,
    #[serde(default = "depth")]
    pub depth: i64,
    #[serde(default = "nodes")]
    pub max_nodes: usize,
    #[serde(default = "edges")]
    pub max_edges: usize,
    #[serde(default)]
    pub short_names: bool,
}
fn depth() -> i64 {
    4
}
fn nodes() -> usize {
    5000
}
fn edges() -> usize {
    25000
}
#[derive(Clone)]
struct Seen {
    distance: usize,
    previous: Option<String>,
    edge: Option<Value>,
    seed: String,
}
fn analysis(complete: bool) -> Value {
    map([
        ("mode", s("basic")),
        ("deep_run", b(false)),
        ("checks_complete", b(complete)),
    ])
}
fn counts(complete: bool, direct: usize, indirect: usize) -> Value {
    map([
        (
            "basis",
            s(if complete {
                "exact_in_analyzed_graph"
            } else {
                "lower_bound"
            }),
        ),
        ("direct", n(direct)),
        ("indirect", n(indirect)),
    ])
}
fn select(st: &State, selector: &str, short_names: bool) -> Vec<String> {
    if st.has(selector) {
        return vec![selector.into()];
    }
    let mut ids = vec![];
    for e in st.elements() {
        let name = e.get("name").text();
        let kind = e.get("kind").text();
        let bare = selector.trim_start_matches('\\');
        if kind == "file" && e.get("path").text() == selector
            || ["method", "class", "interface", "trait", "enum"].contains(&kind)
                && (name.eq_ignore_ascii_case(bare)
                    || short_names
                        && !bare.contains('\\')
                        && name
                            .rsplit('\\')
                            .next()
                            .unwrap_or("")
                            .eq_ignore_ascii_case(selector))
        {
            ids.push(e.get("id").text().to_string())
        }
    }
    ids.sort();
    ids
}
fn semantic(edge: &Value) -> bool {
    let kind = edge.get("kind").text();
    !constants::STRUCTURAL_RELATIONS.contains(&kind)
        && kind != "callable-reference"
        && !["returned-", "registers-", "declares-", "references-"]
            .iter()
            .any(|p| kind.starts_with(p))
}
fn edge_cmp(a: &Value, b: &Value) -> std::cmp::Ordering {
    for key in ["to", "from", "kind", "path"] {
        let c = php_cmp(a.get(key).text(), b.get(key).text());
        if !c.is_eq() {
            return c;
        }
    }
    a.get("line").number().cmp(&b.get("line").number())
}
fn path_edges(id: &str, seen: &HashMap<String, Seen>, reverse: bool) -> Value {
    let mut edges = vec![];
    let mut cur = id;
    while let Some(previous) = seen.get(cur).and_then(|row| row.previous.as_deref()) {
        edges.push(seen[cur].edge.as_ref().unwrap().clone());
        cur = previous
    }
    if !reverse {
        edges.reverse()
    }
    list(edges)
}
fn path_nodes(id: &str, seen: &HashMap<String, Seen>) -> Value {
    let mut nodes = vec![s(id)];
    let mut cur = id;
    while let Some(previous) = seen.get(cur).and_then(|row| row.previous.as_deref()) {
        nodes.push(s(previous));
        cur = previous
    }
    nodes.reverse();
    list(nodes)
}
pub fn query(st: &State, q: &Query) -> Value {
    let _phase = super::measure::Span::unchanged("query", Some(st.owned()));
    let mode = if q.operation == "test_candidates" {
        "impact"
    } else {
        &q.operation
    };
    let subject = q.subject.as_deref().unwrap_or("");
    let mut base = map([
        ("analysis", analysis(false)),
        ("status", s("invalid_input")),
        ("records", empty()),
        ("mode", s(mode)),
        ("traversal_complete", b(true)),
        ("counts", counts(true, 0, 0)),
        (
            "budgets",
            map([
                ("nodes", n(q.max_nodes)),
                ("edges", n(q.max_edges)),
                (
                    "depth",
                    Value::Int(if mode == "context" { 1 } else { q.depth }),
                ),
            ]),
        ),
    ]);
    if trim(subject).is_empty()
        || !["context", "path", "impact"].contains(&mode)
        || q.depth < 1
        || q.depth > 20
        || if mode == "path" {
            q.target.as_deref().is_none_or(|t| trim(t).is_empty())
        } else {
            q.target.is_some()
        }
    {
        return base;
    }
    let subjects = select(st, subject, q.short_names);
    if subjects.len() != 1 {
        base.put(
            "status",
            s(if subjects.is_empty() {
                "not_found"
            } else {
                "ambiguous"
            }),
        );
        base.put(
            "records",
            list(subjects.iter().map(|id| {
                map([
                    ("candidate", st.element(id).clone()),
                    ("selector", s("subject")),
                ])
            })),
        );
        return base;
    }
    let id = &subjects[0];
    base.put("subject", st.element(id).clone());
    base.put("analysis", analysis(st.complete()));
    if mode == "context" {
        let mut records = vec![];
        let mut neighbors: Vec<String> = vec![];
        let mut positions = st.positions(id, false);
        for p in st.positions(id, true) {
            if !positions.contains(&p) {
                positions.push(p)
            }
        }
        let mut complete = true;
        for p in positions {
            if records.len() >= q.max_edges {
                complete = false;
                break;
            }
            let edge = st.edge(p);
            let outgoing = edge.get("from").text() == id;
            let neighbor = edge.get(if outgoing { "to" } else { "from" }).text();
            if !neighbors.iter().any(|n| n == neighbor) && neighbors.len() >= q.max_nodes {
                complete = false;
                break;
            }
            if !neighbors.iter().any(|n| n == neighbor) {
                neighbors.push(neighbor.into())
            }
            records.push(map([
                (
                    "direction",
                    s(if outgoing { "outgoing" } else { "incoming" }),
                ),
                ("relation", edge.clone()),
                ("element", st.element(neighbor).clone()),
                ("semantic_transition", b(semantic(edge))),
            ]))
        }
        php_sort(&mut records, |a, b| {
            php_cmp(a.get("direction").text(), b.get("direction").text()).then_with(|| {
                let a = a.get("relation");
                let b = b.get("relation");
                for k in ["kind", "from", "to", "path"] {
                    let c = php_cmp(a.get(k).text(), b.get(k).text());
                    if !c.is_eq() {
                        return c;
                    }
                }
                a.get("line").number().cmp(&b.get("line").number())
            })
        });
        base.put(
            "status",
            s(if records.is_empty() { "empty" } else { "found" }),
        );
        base.put("records", list(records));
        base.put("traversal_complete", b(complete));
        base.put("analysis", analysis(st.complete() && complete));
        base.put("counts", counts(complete, neighbors.len(), 0));
        return base;
    }
    let destination = if mode == "path" {
        let targets = select(st, q.target.as_deref().unwrap_or(""), q.short_names);
        if targets.len() != 1 {
            base.put(
                "status",
                s(if targets.is_empty() {
                    "not_found"
                } else {
                    "ambiguous"
                }),
            );
            base.put(
                "records",
                list(targets.iter().map(|id| {
                    map([
                        ("candidate", st.element(id).clone()),
                        ("selector", s("target")),
                    ])
                })),
            );
            return base;
        }
        base.put("target", st.element(&targets[0]).clone());
        Some(targets[0].clone())
    } else {
        None
    };
    let mut seeds = vec![id.clone()];
    let mut complete = true;
    if mode == "impact"
        && ["file", "class", "interface", "trait", "enum"]
            .contains(&st.element(id).get("kind").text())
    {
        let mut visited = 0;
        let mut cur = 0;
        'seed: while cur < seeds.len() {
            for p in st.positions(&seeds[cur], false) {
                visited += 1;
                if visited > q.max_edges {
                    complete = false;
                    break 'seed;
                }
                let e = st.edge(p);
                let to = e.get("to").text();
                if e.get("kind").text() == "contains"
                    && st.has(to)
                    && !seeds.iter().any(|v| v == to)
                {
                    if seeds.len() >= q.max_nodes {
                        complete = false;
                        break 'seed;
                    }
                    seeds.push(to.into())
                }
            }
            cur += 1
        }
    }
    let mut seen: HashMap<String, Seen> = HashMap::new();
    let mut queue = vec![];
    for seed in &seeds {
        if seen.len() >= q.max_nodes {
            complete = false;
            break;
        }
        seen.insert(
            seed.clone(),
            Seen {
                distance: 0,
                previous: None,
                edge: None,
                seed: seed.clone(),
            },
        );
        queue.push(seed.clone())
    }
    let mut edges_count = 0;
    let mut found = destination.as_ref() == Some(id);
    let mut cursor = 0;
    'traverse: while cursor < queue.len() && !found {
        let current = queue[cursor].clone();
        let state = seen[&current].clone();
        let mut positions = st.positions(&current, mode == "impact");
        php_sort(&mut positions, |a, b| edge_cmp(st.edge(*a), st.edge(*b)));
        for p in positions {
            edges_count += 1;
            if edges_count > q.max_edges {
                complete = false;
                break 'traverse;
            }
            let edge = st.edge(p);
            if mode == "path" && !semantic(edge)
                || mode == "impact" && edge.get("kind").text() == "contains"
            {
                continue;
            }
            let next = edge
                .get(if mode == "impact" { "from" } else { "to" })
                .text();
            if !st.has(next) || seen.contains_key(next) {
                continue;
            }
            if state.distance >= q.depth as usize || seen.len() >= q.max_nodes {
                complete = false;
                continue;
            }
            seen.insert(
                next.into(),
                Seen {
                    distance: state.distance + 1,
                    previous: Some(current.clone()),
                    edge: Some(edge.clone()),
                    seed: state.seed.clone(),
                },
            );
            queue.push(next.into());
            if destination.as_deref() == Some(next) {
                found = true;
                break;
            }
        }
        cursor += 1
    }
    base.put("traversal_complete", b(complete));
    base.put("analysis", analysis(st.complete() && complete));
    if mode == "path" {
        base.put(
            "status",
            s(if found {
                "found"
            } else if complete {
                "no_path_in_analyzed_graph"
            } else {
                "unavailable"
            }),
        );
        if found {
            let destination = destination.as_deref().unwrap_or(id);
            base.put(
                "records",
                list([map([
                    ("nodes", path_nodes(destination, &seen)),
                    ("relations", path_edges(destination, &seen, false)),
                    ("execution_proven", b(false)),
                ])]),
            )
        }
        return base;
    }
    let mut records = vec![];
    let mut direct = 0;
    for dependent in &queue {
        let v = &seen[dependent];
        if v.distance == 0 {
            continue;
        }
        let e = st.element(dependent);
        let is_direct = v.distance == 1;
        direct += usize::from(is_direct);
        let candidate = e
            .get("metadata")
            .find("test_registration_resolved")
            .map(Value::yes)
            .unwrap_or(true)
            && (["pest-test", "test-method"].contains(&e.get("kind").text())
                || e.get("roles").contains_text("test")
                || e.get("roles").contains_text("test-method"));
        records.push(map([
            ("element", e.clone()),
            ("distance", n(v.distance)),
            ("direct", b(is_direct)),
            ("changed_seed", s(&v.seed)),
            ("explanation", path_edges(dependent, &seen, true)),
            (
                "entrypoint",
                b([
                    "route",
                    "scheduled-task",
                    "console-command",
                    "broadcast-subscription",
                    "database-seeder",
                    "database-migration",
                ]
                .contains(&e.get("kind").text())),
            ),
            ("test_candidate", b(candidate)),
            ("execution_proven", b(false)),
            ("test_executed", b(false)),
        ]))
    }
    php_sort(&mut records, |a, b| {
        a.get("distance")
            .number()
            .cmp(&b.get("distance").number())
            .then_with(|| element_cmp(a.get("element"), b.get("element")))
    });
    let count = records.len();
    if q.operation == "test_candidates" {
        records.retain(|r| r.get("test_candidate").yes())
    }
    base.put(
        "status",
        s(if records.is_empty() { "empty" } else { "found" }),
    );
    base.put("records", list(records));
    base.put("change_seeds", list(seeds.into_iter().map(s)));
    base.put("counts", counts(complete, direct, count - direct));
    base
}
