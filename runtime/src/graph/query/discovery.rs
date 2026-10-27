//! Legacy literal discovery projection. Its ranking differs from GraphSearch.
use super::Query;
use crate::graph::{
    compose::{State, value::*},
    model::Value,
    search::trim,
};
use std::cmp::Ordering;
pub fn find(st: &State, q: &Query) -> Value {
    let needle = trim(q.query.as_deref().unwrap_or(""));
    let mut rows = vec![];
    for element in st.elements() {
        let kind = element.get("kind").text();
        let mut kinds = vec![s(kind)];
        for role in element.get("roles").vals() {
            if !kinds.contains(role) {
                kinds.push(role.clone());
            }
        }
        if q.kind
            .as_ref()
            .is_some_and(|k| !kinds.iter().any(|v| v.text() == k))
        {
            continue;
        }
        let name = element.get("name").text();
        let mut fields = vec![name, element.get("path").text()];
        for key in ["uri", "name", "route_name"] {
            if let Value::String(v) = element.meta(key) {
                fields.push(v);
            }
        }
        if !needle.is_empty() && !fields.iter().any(|v| lower(v).contains(&lower(needle))) {
            continue;
        }
        let symbol = ["class", "interface", "trait", "enum", "method"].contains(&kind);
        rows.push(map([
            ("id", element.get("id").clone()),
            ("name", s(name)),
            ("kind", s(kind)),
            ("kinds", list(kinds)),
            ("path", element.get("path").clone()),
            ("line", element.get("line").clone()),
            (
                "selector",
                if symbol {
                    s(name)
                } else {
                    element.get("path").clone()
                },
            ),
            ("selector_scope", s(if symbol { "symbol" } else { "file" })),
            ("supported", b(true)),
            (
                "notes",
                list([s(
                    "Source declaration or candidate; execution and passing tests are not proved.",
                )]),
            ),
            ("aliases", empty()),
            ("classification", element.meta("classification").clone()),
            (
                "exact",
                b(fields.iter().any(|v| v.eq_ignore_ascii_case(needle))),
            ),
        ]));
        if matches!(rows.last().unwrap().get("classification"), Value::Null) {
            rows.last_mut().unwrap().put("classification", empty());
        }
    }
    php_sort(&mut rows, |a, b| {
        let c = b
            .get("exact")
            .yes()
            .cmp(&a.get("exact").yes())
            .then_with(|| (a.get("kind").text() == "file").cmp(&(b.get("kind").text() == "file")))
            .then_with(|| php_cmp(&lower(a.get("name").text()), &lower(b.get("name").text())));
        if c != Ordering::Equal {
            return c;
        }
        for key in ["kind", "path"] {
            let c = php_cmp(a.get(key).text(), b.get(key).text());
            if !c.is_eq() {
                return c;
            }
        }
        a.get("line")
            .number()
            .cmp(&b.get("line").number())
            .then_with(|| php_cmp(a.get("id").text(), b.get("id").text()))
    });
    let total = rows.len();
    map([
        (
            "status",
            s(if !st.complete() {
                "incomplete"
            } else if total == 0 {
                "no_matches"
            } else {
                "found"
            }),
        ),
        ("candidates", list(rows)),
        ("total", n(total)),
        ("total_is_lower_bound", b(!st.complete())),
        ("ambiguous", b(total > 1)),
        ("limited", b(!st.complete())),
        (
            "analysis",
            map([
                ("mode", s("basic")),
                ("checks_complete", b(st.complete())),
                ("deep_run", b(false)),
            ]),
        ),
    ])
}
