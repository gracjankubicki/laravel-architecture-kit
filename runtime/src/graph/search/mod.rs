//! Shared normalized values with byte trigrams and exact budget fallback.
use super::{
    Result,
    compose::{State, constants, value::*},
    model::Value,
};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Search {
    #[serde(skip)]
    pub(crate) records: HashMap<String, Vec<String>>,
    #[serde(skip)]
    pub(crate) lookup: HashMap<String, usize>,
    #[serde(skip)]
    pub(crate) cached_owned: usize,
    pub values: Vec<(String, Vec<String>)>,
    pub budget: usize,
    pub limited: bool,
    pub(crate) dictionary: Vec<usize>,
    pub(crate) grams: HashMap<String, Vec<usize>>,
    pub postings: usize,
}
impl Search {
    pub fn equivalent(&self, other: &Self) -> bool {
        self.values == other.values
            && self.budget == other.budget
            && self.limited == other.limited
            && self.dictionary == other.dictionary
            && self.grams == other.grams
            && self.postings == other.postings
    }

    pub fn build(st: &State, budget: usize) -> Self {
        let phase = super::measure::Span::new("search", Some(st.owned()));
        let mut out = Self {
            cached_owned: 0,
            lookup: HashMap::new(),
            records: HashMap::new(),
            values: vec![],
            budget,
            limited: false,
            dictionary: vec![],
            grams: HashMap::new(),
            postings: 0,
        };
        for e in st.elements() {
            out.add(e)
        }
        out.accelerate();
        out.refresh_owned();
        phase.finish(Some(out.owned()));
        out
    }
    fn add(&mut self, e: &Value) {
        let keys = keys(e);
        self.records.insert(e.get("id").text().into(), keys.clone());
        for value in keys {
            if let Some(i) = self.lookup.get(&value) {
                self.values[*i].1.push(e.get("id").text().into())
            } else {
                if !self.limited {
                    let count = gram_count(&value, self.budget.saturating_add(1));
                    if self.postings.saturating_add(count) > self.budget {
                        self.limited = true;
                        self.postings = 0
                    } else {
                        self.postings += count
                    }
                }
                self.lookup.insert(value.clone(), self.values.len());
                self.values.push((value, vec![e.get("id").text().into()]))
            }
        }
    }
    fn remove(&mut self, id: &str) {
        self.records.remove(id);
        for (value, owners) in &mut self.values {
            let existed = owners.iter().any(|v| v == id);
            owners.retain(|v| v != id);
            if existed && owners.is_empty() && !self.limited {
                self.postings -= gram_count(value, self.budget.saturating_add(1))
            }
        }
        self.values.retain(|(_, owners)| !owners.is_empty());
        self.lookup = self
            .values
            .iter()
            .enumerate()
            .map(|(i, (v, _))| (v.clone(), i))
            .collect();
    }
    pub fn apply(&self, before: &State, after: &State) -> Self {
        let phase = super::measure::Span::new("search_patch", Some(self.owned()));
        let mut out = Self {
            records: self.records.clone(),
            lookup: self.lookup.clone(),
            cached_owned: 0,
            values: self.values.clone(),
            budget: self.budget,
            limited: self.limited,
            dictionary: vec![],
            grams: HashMap::new(),
            postings: self.postings,
        };
        // Match PHP's ordered removed-then-per-upsert remove/add, including value/owner ties.
        for old in before.elements() {
            if !after.has(old.get("id").text()) {
                out.remove(old.get("id").text())
            }
        }
        for e in after.elements() {
            let id = e.get("id").text();
            if !before.has(id) || before.element(id) != e {
                out.remove(id);
                out.add(e)
            }
        }
        out.order_as_final_state(after);
        out.accelerate();
        out.refresh_owned();
        phase.finish(Some(out.owned()));
        out
    }
    fn order_as_final_state(&mut self, state: &State) {
        // Reuse normalized footprints; only ordering follows final foundation, as a fresh PHP build.
        let positions: HashMap<(&str, &str), usize> = self
            .records
            .iter()
            .flat_map(|(id, keys)| {
                keys.iter()
                    .enumerate()
                    .map(move |(i, k)| ((id.as_str(), k.as_str()), i))
            })
            .collect();
        for (_, owners) in &mut self.values {
            owners.sort_by_key(|id| state.ids[id]);
        }
        self.values.sort_by_key(|(value, owners)| {
            let first = &owners[0];
            (
                state.ids[first],
                positions[&(first.as_str(), value.as_str())],
            )
        });
        self.lookup = self
            .values
            .iter()
            .enumerate()
            .map(|(i, (v, _))| (v.clone(), i))
            .collect();
    }
    pub fn restore(&mut self, st: &State) -> Result<()> {
        self.lookup.clear();
        let mut links = std::collections::HashSet::new();
        let mut unique_values = std::collections::HashSet::new();
        let mut actual_links = 0usize;
        for (i, (value, owners)) in self.values.iter().enumerate() {
            self.lookup.insert(value.clone(), i);
            if lower(value) != *value || !unique_values.insert(value) || owners.is_empty() {
                return Err("search_value".into());
            }
            let mut unique_owners = std::collections::HashSet::new();
            for id in owners {
                if !st.has(id) || !unique_owners.insert(id) || !keys(st.element(id)).contains(value)
                {
                    return Err("search_owner".into());
                }
                links.insert((i, id.as_str()));
                actual_links += 1
            }
        }
        self.records.clear();
        let mut expected_links = 0;
        for e in st.elements() {
            let keys = keys(e);
            self.records.insert(e.get("id").text().into(), keys.clone());
            for value in keys {
                expected_links += 1;
                if !self
                    .lookup
                    .get(&value)
                    .is_some_and(|i| links.contains(&(*i, e.get("id").text())))
                {
                    return Err("search_coverage".into());
                }
            }
        }
        if actual_links != expected_links {
            return Err("search_coverage".into());
        }
        if self.dictionary.len() != self.values.len() {
            return Err("search_dictionary".into());
        }
        let mut seen = std::collections::HashSet::new();
        let mut previous = None;
        for i in &self.dictionary {
            let value = &self.values.get(*i).ok_or("search_dictionary")?.0;
            if !seen.insert(i) || previous.is_some_and(|p: &String| p >= value) {
                return Err("search_dictionary".into());
            }
            previous = Some(value)
        }
        let mut postings = 0;
        for (g, ids) in &self.grams {
            if g.len() != 6
                || !g
                    .bytes()
                    .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            {
                return Err("search_gram".into());
            }
            let mut seen = std::collections::HashSet::new();
            for id in ids {
                let value = &self.values.get(*id).ok_or("search_gram")?.0;
                if !seen.insert(id) || !value.as_bytes().windows(3).any(|w| gram_key(w) == *g) {
                    return Err("search_gram".into());
                }
                postings += 1
            }
        }
        if postings != self.postings || postings > self.budget || self.limited && postings != 0 {
            return Err("search_postings".into());
        }
        if !self.limited {
            for (i, (value, _)) in self.values.iter().enumerate() {
                for gram in value.as_bytes().windows(3) {
                    if !self
                        .grams
                        .get(&gram_key(gram))
                        .is_some_and(|v| v.contains(&i))
                    {
                        return Err("search_missing_gram".into());
                    }
                }
            }
        }
        Ok(())
    }
    fn accelerate(&mut self) {
        self.dictionary = (0..self.values.len()).collect();
        self.dictionary
            .sort_by(|a, b| self.values[*a].0.cmp(&self.values[*b].0));
        self.grams.clear();
        self.postings = 0;
        self.limited = false;
        for (i, (value, _)) in self.values.iter().enumerate() {
            for gram in value.as_bytes().windows(3) {
                let g = gram_key(gram);
                let posting = self.grams.entry(g).or_default();
                if posting.last() == Some(&i) {
                    continue;
                }
                if self.postings >= self.budget {
                    self.limited = true;
                    self.grams.clear();
                    self.postings = 0;
                    return;
                }
                posting.push(i);
                self.postings += 1
            }
        }
    }
    pub fn owned(&self) -> usize {
        self.cached_owned
    }
    pub fn refresh_owned(&mut self) {
        self.cached_owned = self.owned_full();
    }
    pub fn owned_full(&self) -> usize {
        self.records.capacity() * 64
            + self
                .records
                .iter()
                .map(|(id, keys)| {
                    id.capacity()
                        + keys.capacity() * std::mem::size_of::<String>()
                        + keys.iter().map(String::capacity).sum::<usize>()
                })
                .sum::<usize>()
            + self.lookup.capacity() * 64
            + self.lookup.keys().map(String::capacity).sum::<usize>()
            + self.values.capacity() * 64
            + self
                .values
                .iter()
                .map(|(v, owners)| {
                    v.capacity()
                        + owners.capacity() * 24
                        + owners.iter().map(String::capacity).sum::<usize>()
                })
                .sum::<usize>()
            + self.dictionary.capacity() * 8
            + self.grams.capacity() * 64
            + self.grams.values().map(|v| v.capacity() * 8).sum::<usize>()
    }
    pub fn execution(&self, query: &str, generation: &str) -> Value {
        map([
            (
                "mode",
                s(if self.limited {
                    "exact_dictionary_scan"
                } else if lower(trim(query)).len() < 3 {
                    "short_dictionary_scan"
                } else {
                    "indexed"
                }),
            ),
            (
                "reason",
                if self.limited {
                    s("posting_budget")
                } else {
                    Value::Null
                },
            ),
            ("generation", s(generation)),
            ("posting_budget", n(self.budget)),
            ("postings", n(self.postings)),
        ])
    }
    pub fn find(
        &self,
        st: &State,
        query: &str,
        kind: Option<&str>,
        path: Option<&str>,
    ) -> (Value, Value) {
        let _phase = super::measure::Span::unchanged("query", Some(self.owned() + st.owned()));
        let supported = supported();
        let query = trim(query);
        if query.is_empty()
            || kind.is_some_and(|k| !supported.contains(&k.to_string()))
            || path.is_some_and(|p| !safe_prefix(p))
        {
            return (
                map([
                    ("status", s("invalid_input")),
                    ("candidates", empty()),
                    ("match_count", n(0)),
                    ("supported_kinds", list(supported.into_iter().map(s))),
                ]),
                map([("mode", s("invalid_input")), ("reason", Value::Null)]),
            );
        }
        let needle = lower(query);
        let mut matched: Vec<(usize, usize)> = vec![];
        let mut matched_flags = vec![false; self.values.len()];
        let lo = self
            .dictionary
            .partition_point(|i| self.values[*i].0.as_str() < needle.as_str());
        for i in &self.dictionary[lo..] {
            let value = &self.values[*i].0;
            if !value.starts_with(&needle) {
                break;
            }
            matched_flags[*i] = true;
            matched.push((*i, if *value == needle { 0 } else { 1 }));
        }
        let mut candidates: Option<&Vec<usize>> = None;
        let empty = vec![];
        if !self.limited && needle.len() >= 3 {
            for gram in needle.as_bytes().windows(3) {
                let k = gram_key(gram);
                let posting = self.grams.get(&k).unwrap_or(&empty);
                if candidates.is_none_or(|p| posting.len() < p.len()) {
                    candidates = Some(posting)
                }
                if candidates.is_some_and(|p| p.is_empty()) {
                    break;
                }
            }
        }
        let all: Vec<_> = (0..self.values.len()).collect();
        for i in candidates.unwrap_or(&all) {
            if !matched_flags[*i] && self.values[*i].0.contains(&needle) {
                matched_flags[*i] = true;
                matched.push((*i, 2))
            }
        }
        let mut ranks: Vec<(String, usize)> = vec![];
        let mut rank_index: HashMap<&str, usize> = HashMap::new();
        for (i, rank) in matched {
            for id in &self.values[i].1 {
                if let Some(i) = rank_index.get(id.as_str()) {
                    ranks[*i].1 = ranks[*i].1.min(rank)
                } else {
                    rank_index.insert(id, ranks.len());
                    ranks.push((id.clone(), rank))
                }
            }
        }
        let mut matches = vec![];
        for (id, rank) in ranks {
            let e = st.element(&id);
            if kind.is_some_and(|k| e.get("kind").text() != k && !e.get("roles").contains_text(k)) {
                continue;
            }
            if path.is_some_and(|p| {
                !std::iter::once(e.get("path"))
                    .chain(e.get("sources").vals().map(|s| s.get("path")))
                    .any(|v| {
                        v.text() == p
                            || v.text()
                                .starts_with(&format!("{}/", p.trim_end_matches('/')))
                    })
            }) {
                continue;
            }
            matches.push((e.clone(), rank))
        }
        php_sort(&mut matches, |(a, ar), (b, br)| {
            ar.cmp(br).then_with(|| element_cmp(a, b))
        });
        let candidates = list(matches.iter().map(|(e, r)| {
            let mut e = e.clone();
            e.put("match", s(["exact", "prefix", "substring"][*r]));
            e
        }));
        (
            map([
                (
                    "status",
                    s(if matches.is_empty() { "empty" } else { "found" }),
                ),
                ("candidates", candidates),
                ("match_count", n(matches.len())),
                ("count_basis", s("exact_in_analyzed_graph")),
                ("supported_kinds", list(supported.into_iter().map(s))),
            ]),
            self.execution(query, ""),
        )
    }
}
pub fn trim(v: &str) -> &str {
    v.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\u{b}'))
}
pub fn supported() -> Vec<String> {
    let mut out = unique(
        constants::PHP
            .iter()
            .chain(constants::RESOURCES)
            .chain(constants::ROLES)
            .map(|v| v.to_string())
            .collect(),
    );
    out.sort();
    out
}
pub fn safe_prefix(path: &str) -> bool {
    !path.is_empty()
        && !path.starts_with('/')
        && !path.contains('\\')
        && !path.contains("//")
        && !path.bytes().any(|b| b < 32 || b == b':')
        && !path
            .split('/')
            .any(|p| [".", "..", "vendor", "node_modules", ".git"].contains(&p))
}
pub fn element_cmp(a: &Value, b: &Value) -> std::cmp::Ordering {
    for key in ["kind", "name", "path"] {
        let c = php_cmp(a.get(key).text(), b.get(key).text());
        if !c.is_eq() {
            return c;
        }
    }
    a.get("line")
        .number()
        .cmp(&b.get("line").number())
        .then_with(|| php_cmp(a.get("id").text(), b.get("id").text()))
}
fn keys(e: &Value) -> Vec<String> {
    let mut fields: Vec<String> = ["id", "name", "path"]
        .iter()
        .map(|k| lower(e.get(k).text()))
        .collect();
    fields.extend(e.get("sources").vals().map(|s| lower(s.get("path").text())));
    fields.push(lower(e.get("kind").text()));
    fields.extend(e.get("roles").vals().map(|v| lower(v.text())));
    for key in ["name", "uri", "table", "route_name"] {
        if let Value::String(v) = e.meta(key) {
            fields.push(lower(v))
        }
    }
    unique(fields)
}

fn gram_key(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

fn gram_count(value: &str, limit: usize) -> usize {
    let mut grams = std::collections::HashSet::<[u8; 3]>::new();
    for window in value.as_bytes().windows(3) {
        grams.insert(window.try_into().unwrap());
        if grams.len() >= limit {
            break;
        }
    }
    grams.len()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn posting_budget_fallback_preserves_exact_complete_answers_for_short_utf8_and_ranked_queries()
    {
        let mut state = State::default();
        for (i, name) in ["Alpha", "Alphabet", "Φoo", "00"].into_iter().enumerate() {
            let id = format!("id:{i}");
            state.put_element(
                &id,
                map([
                    ("id", s(&id)),
                    ("name", s(name)),
                    ("kind", s("method")),
                    ("path", s("app/Example.php")),
                    ("line", n(i + 1)),
                    ("roles", list([s("service")])),
                    ("metadata", empty()),
                    ("sources", empty()),
                ]),
            );
        }
        let indexed = Search::build(&state, 100_000);
        let fallback = Search::build(&state, 0);
        assert!(!indexed.limited);
        assert!(fallback.limited);
        assert_eq!(
            fallback.execution("alpha", "g").get("reason").text(),
            "posting_budget"
        );
        for query in [
            "Alpha", "ALPHA", "alp", "ha", "a", "Φ", "φ", "00", "method", "service", "", "none",
        ] {
            assert_eq!(
                indexed.find(&state, query, None, None).0,
                fallback.find(&state, query, None, None).0,
                "{query}"
            );
        }
        assert_eq!(
            fallback
                .find(&state, "alpha", None, None)
                .0
                .get("match_count")
                .number(),
            2
        );
    }
}
