//! Versioned declaration components and operation fragments, including absence.
use super::{
    Result,
    compose::{State, foundation, value::*},
    model::Value,
    resolve::{Fragment, Resolver},
    store::Generation,
};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, HashMap};
use std::io::{self, Write};
struct HashSink(Sha256);
impl Write for HashSink {
    fn write(&mut self, b: &[u8]) -> io::Result<usize> {
        self.0.update(b);
        Ok(b.len())
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}
pub fn digest(v: &impl Serialize) -> String {
    let mut w = HashSink(Sha256::new());
    serde_json::to_writer(&mut w, v).expect("typed values serialize");
    format!("{:x}", w.0.finalize())
}
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Product {
    #[serde(skip)]
    pub cached_owned: usize,
    pub state: State,
    pub operations: BTreeMap<String, Fragment>,
    #[serde(skip)]
    pub trace: Vec<Value>,
    pub search: super::search::Search,
}
impl Product {
    pub fn growth_reserve(&self) -> Result<usize> {
        let outer = self
            .state
            .fields
            .iter()
            .map(Value::container_bytes)
            .sum::<usize>()
            + self.operations.len() * std::mem::size_of::<(String, Fragment)>()
            + self.search.values.capacity() * std::mem::size_of::<(String, Vec<String>)>()
            + self.search.dictionary.capacity() * 8
            + self.search.grams.capacity() * 64;
        outer.checked_mul(4).ok_or("budget_overflow".into())
    }
    pub fn validation_reserve(&self) -> Result<usize> {
        let rows = [0, 4, 5, 6]
            .into_iter()
            .map(|i| self.state.fields[i].rows().len())
            .sum::<usize>();
        let key_bytes = [0, 4, 5, 6]
            .into_iter()
            .flat_map(|i| self.state.fields[i].rows())
            .map(|(k, _)| match k {
                super::model::Key::String(s) => s.len(),
                super::model::Key::Int(_) => 20,
            })
            .sum::<usize>();
        let adjacency = self.state.fields[5]
            .owned()
            .checked_add(self.state.fields[6].owned())
            .ok_or("budget_overflow")?;
        let mut search = 0usize;
        for (value, owners) in &self.search.values {
            let footprint = value
                .len()
                .checked_add(160)
                .and_then(|v| v.checked_mul(owners.len()))
                .ok_or("budget_overflow")?;
            search = search
                .checked_add(footprint)
                .and_then(|v| v.checked_add(value.len() + 96))
                .ok_or("budget_overflow")?;
        }
        rows.checked_mul(160)
            .and_then(|v| v.checked_add(key_bytes))
            .and_then(|v| adjacency.checked_mul(4).and_then(|a| v.checked_add(a)))
            .and_then(|v| search.checked_mul(3).and_then(|s| v.checked_add(s)))
            .and_then(|v| v.checked_add(1024 * 1024))
            .ok_or("budget_overflow".into())
    }

    pub fn equivalent(&self, other: &Self) -> bool {
        self.state.fields == other.state.fields
            && self.operations == other.operations
            && self.search.equivalent(&other.search)
    }

    pub fn intern(&mut self, pool: &mut std::collections::HashSet<std::sync::Arc<str>>) -> usize {
        let mut bytes = 0;
        for v in &mut self.state.fields {
            bytes += v.intern_counted(pool)
        }
        for f in self.operations.values_mut() {
            bytes += intern_fragment(f, pool)
        }
        for row in &mut self.trace {
            bytes += row.intern_counted(pool)
        }
        bytes
    }
    pub fn owned(&self) -> usize {
        self.cached_owned
    }
    pub fn refresh_owned(&mut self) {
        self.state.refresh_owned();
        self.search.refresh_owned();
        self.cached_owned = self.owned_full();
    }
    pub fn owned_full(&self) -> usize {
        self.state.owned_full()
            + self.search.owned_full()
            + self
                .operations
                .iter()
                .map(|(k, v)| {
                    k.capacity()
                        + v.input.owned()
                        + v.relations
                            .iter()
                            .chain(v.diagnostics.iter())
                            .map(Value::owned)
                            .sum::<usize>()
                        + v.reads
                            .iter()
                            .map(|(k, v)| k.capacity() + v.capacity() + 64)
                            .sum::<usize>()
                        + v.lookups
                            .iter()
                            .map(|(k, ids)| {
                                k.capacity()
                                    + ids.iter().map(|s| s.capacity() + 32).sum::<usize>()
                                    + 64
                            })
                            .sum::<usize>()
                        + v.member_reads
                            .iter()
                            .map(|(k, v)| {
                                k.capacity()
                                    + v.as_ref().map_or(0, |v| {
                                        v.iter().map(|s| s.capacity() + 32).sum::<usize>()
                                    })
                                    + 64
                            })
                            .sum::<usize>()
                        + v.member_writes
                            .iter()
                            .map(|(k, v)| {
                                k.capacity()
                                    + v.iter().map(|s| s.capacity() + 32).sum::<usize>()
                                    + 64
                            })
                            .sum::<usize>()
                })
                .sum::<usize>()
            + self.trace.iter().map(Value::owned).sum::<usize>()
    }
    pub fn validate(&mut self) -> Result<()> {
        self.state.validate()?;
        self.search.restore(&self.state)?;
        for f in self.operations.values() {
            for key in f.reads.keys() {
                if !(key.starts_with("name:")
                    || key.starts_with("element:")
                    || key == "descriptors:*"
                    || key == "declarations:*")
                {
                    return Err("dependency_key".into());
                }
            }
        }
        self.refresh_owned();
        Ok(())
    }
}
struct Versions {
    values: HashMap<String, String>,
    absent: String,
}
impl Versions {
    fn new(state: &State, calls: &[Value]) -> Self {
        let phase = super::measure::Span::new("dependency", Some(state.owned()));
        let mut parents: HashMap<String, String> = state
            .elements()
            .map(|e| (e.get("id").text().into(), e.get("id").text().into()))
            .collect();
        fn root(p: &HashMap<String, String>, id: &str) -> String {
            let mut r = id;
            while p[r] != r {
                r = &p[r]
            }
            r.into()
        }
        fn join(p: &mut HashMap<String, String>, a: &str, b: &str) {
            let ar = root(p, a);
            let br = root(p, b);
            p.insert(ar, br);
        }
        for e in state.elements() {
            if state.has(e.get("parent").text()) {
                join(&mut parents, e.get("id").text(), e.get("parent").text())
            }
        }
        for e in state.fields[1].vals() {
            if ["extends", "implements", "uses-trait"].contains(&e.get("kind").text())
                && state.has(e.get("from").text())
                && state.has(e.get("to").text())
            {
                join(&mut parents, e.get("from").text(), e.get("to").text())
            }
        }
        let mut groups: HashMap<String, (Vec<&Value>, Vec<&Value>)> = HashMap::new();
        for e in state.elements() {
            groups
                .entry(root(&parents, e.get("id").text()))
                .or_default()
                .0
                .push(e)
        }
        for e in state.fields[1].vals() {
            if state.has(e.get("from").text()) {
                groups
                    .get_mut(&root(&parents, e.get("from").text()))
                    .unwrap()
                    .1
                    .push(e)
            }
        }
        let hashes: HashMap<_, _> = groups.into_iter().map(|(k, v)| (k, digest(&v))).collect();
        let mut values = HashMap::new();
        for e in state.elements() {
            let id = e.get("id").text();
            values.insert(format!("element:{id}"), hashes[&root(&parents, id)].clone());
        }
        for (key, ids) in state.fields[4].rows() {
            let candidates: Vec<_> = ids
                .vals()
                .map(|id| (id.text(), values.get(&format!("element:{}", id.text()))))
                .collect();
            values.insert(format!("name:{}", key_string(key)), digest(&candidates));
        }
        values.insert("descriptors:*".into(), digest(&state.fields[3]));
        values.insert("declarations:*".into(), digest(&(&state.fields, calls)));
        let result = Self {
            values,
            absent: digest(&Value::Null),
        };
        phase.finish(Some(
            result.values.capacity() * 64
                + result
                    .values
                    .iter()
                    .map(|(k, v)| k.capacity() + v.capacity())
                    .sum::<usize>()
                + result.absent.capacity(),
        ));
        result
    }
    fn version(&self, k: &str) -> String {
        self.values.get(k).unwrap_or(&self.absent).clone()
    }
}
pub fn compose(g: &mut Generation, base: Option<&Product>, work_limit: usize) -> Result<Product> {
    let phase = super::measure::Span::new("compose", Some(g.owned()));
    let mut f = foundation(g, work_limit)?;
    let versions = Versions::new(&f.state, &f.calls);
    let mut resolver = Resolver::new(&mut f.state);
    let mut operations = BTreeMap::new();
    let mut trace = vec![];
    let mut ordinals = HashMap::new();
    for call in f.calls {
        let path = call.get("path").text();
        let ordinal = ordinals.entry(path.to_owned()).or_insert(0);
        let key = format!("1:{path}:{ordinal}");
        *ordinal += 1;
        let previous = base.and_then(|b| b.operations.get(&key));
        let mut reason = "operation_input".to_string();
        let reuse = previous.is_some_and(|p| {
            if p.input != call {
                return false;
            }
            for d in &p.diagnostics {
                if d.get("code").text().contains("limit") {
                    reason = "incomplete_operation".into();
                    return false;
                }
            }
            for (k, v) in &p.reads {
                if versions.version(k) != *v {
                    reason = format!("dependency:{k}");
                    return false;
                }
            }
            for (k, v) in &p.member_reads {
                if resolver.cache.get(k) != v.as_ref() {
                    reason = format!("member_cache:{k}");
                    return false;
                }
            }
            true
        });
        let fragment = if reuse {
            let p = previous.unwrap().clone();
            for (k, v) in &p.member_writes {
                resolver.cache.insert(k.clone(), v.clone());
            }
            for r in &p.relations {
                resolver.state.add(r.clone())
            }
            for d in &p.diagnostics {
                resolver.state.diagnostic(d.clone())
            }
            p
        } else {
            let rstart = resolver.state.fields[1].rows().len();
            let dstart = resolver.state.fields[2].rows().len();
            resolver.operation(&call);
            resolver.state.ensure()?;
            let mut keys = vec!["descriptors:*".to_string()];
            let mut creator = call
                .meta("bound_callable")
                .find("creator")
                .unwrap_or(call.get("from"))
                .text()
                .to_string();
            for _ in 0..16 {
                keys.push(format!("element:{creator}"));
                if !resolver.state.has(&creator)
                    || matches!(resolver.state.element(&creator).get("parent"), Value::Null)
                {
                    break;
                }
                creator = resolver
                    .state
                    .element(&creator)
                    .get("parent")
                    .text()
                    .to_string()
            }
            let receiver = call.meta("receiver").text();
            if receiver.starts_with('@')
                && !receiver.starts_with("@function:")
                && !receiver.starts_with("@closure:")
            {
                keys.push("declarations:*".into())
            }
            keys.extend(resolver.reads.keys().cloned());
            Fragment {
                input: call.clone(),
                reads: keys
                    .into_iter()
                    .map(|k| {
                        let v = versions.version(&k);
                        (k, v)
                    })
                    .collect(),
                lookups: resolver.reads.clone(),
                member_reads: resolver.cache_reads.clone(),
                member_writes: resolver.cache_writes.clone(),
                relations: resolver.state.fields[1]
                    .vals()
                    .skip(rstart)
                    .cloned()
                    .collect(),
                diagnostics: resolver.state.fields[2]
                    .vals()
                    .skip(dstart)
                    .cloned()
                    .collect(),
            }
        };
        trace.push(map([
            ("operation", s(&key)),
            ("path", s(path)),
            ("action", s(if reuse { "reuse" } else { "resolve" })),
            ("reason", if reuse { Value::Null } else { s(reason) }),
            ("reads", list(fragment.reads.keys().map(s))),
        ]));
        operations.insert(key, fragment);
    }
    for r in f.registrations {
        resolver.state.add(r)
    }
    resolver.state.ensure()?;
    drop(resolver);
    let search = if let Some(base) = base {
        base.search.apply(&base.state, &f.state)
    } else {
        super::search::Search::build(&f.state, 32768)
    };
    let mut product = Product {
        cached_owned: 0,
        state: f.state,
        operations,
        trace,
        search,
    };
    g.intern_product(&mut product);
    phase.finish(Some(g.owned() + product.owned()));
    Ok(product)
}

pub fn intern_fragment(
    f: &mut Fragment,
    pool: &mut std::collections::HashSet<std::sync::Arc<str>>,
) -> usize {
    let mut bytes = f.input.intern_counted(pool);
    for row in f.relations.iter_mut().chain(f.diagnostics.iter_mut()) {
        bytes += row.intern_counted(pool)
    }
    bytes
}
