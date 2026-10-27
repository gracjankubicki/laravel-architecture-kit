//! Operations on ordered PHP values. Public keys retain PHP integer coercion.
use super::super::model::{Key, Value};
pub use super::sort::php_sort;
use std::{cmp::Ordering, sync::Arc};
pub fn s(v: impl AsRef<str>) -> Value {
    Value::String(Arc::from(v.as_ref()))
}
pub fn b(v: bool) -> Value {
    Value::Bool(v)
}
pub fn n(v: usize) -> Value {
    Value::Int(v as i64)
}
pub fn list(v: impl IntoIterator<Item = Value>) -> Value {
    Value::Array {
        list: true,
        entries: v
            .into_iter()
            .enumerate()
            .map(|(i, v)| (Key::Int(i as i64), v))
            .collect(),
    }
}
pub fn map(v: impl IntoIterator<Item = (&'static str, Value)>) -> Value {
    Value::Array {
        list: false,
        entries: v
            .into_iter()
            .map(|(k, v)| (Key::String(Arc::from(k)), v))
            .collect(),
    }
}
pub fn empty() -> Value {
    list([])
}
pub fn key(v: &str) -> Key {
    if let Ok(i) = v.parse::<i64>() {
        if i.to_string() == v {
            return Key::Int(i);
        }
    }
    Key::String(Arc::from(v))
}
pub fn key_string(k: &Key) -> String {
    match k {
        Key::Int(i) => i.to_string(),
        Key::String(s) => s.to_string(),
    }
}
impl Value {
    pub fn get(&self, k: &str) -> &Value {
        self.find(k).unwrap_or(&Value::Null)
    }
    pub fn find(&self, k: &str) -> Option<&Value> {
        let k = key(k);
        match self {
            Self::Array { entries, .. } => entries.iter().find(|(a, _)| *a == k).map(|(_, v)| v),
            _ => None,
        }
    }
    pub fn text(&self) -> &str {
        if let Self::String(v) = self { v } else { "" }
    }
    pub fn yes(&self) -> bool {
        matches!(self, Self::Bool(true))
    }
    pub fn number(&self) -> usize {
        if let Self::Int(v) = self {
            (*v).max(0) as usize
        } else {
            0
        }
    }
    pub fn rows(&self) -> &[(Key, Value)] {
        if let Self::Array { entries, .. } = self {
            entries
        } else {
            &[]
        }
    }
    pub fn vals(&self) -> impl Iterator<Item = &Value> {
        self.rows().iter().map(|(_, v)| v)
    }
    pub fn put(&mut self, k: &str, v: Value) {
        self.put_key(key(k), v)
    }
    pub fn put_key(&mut self, k: Key, v: Value) {
        if !matches!(self, Self::Array { .. }) {
            *self = empty()
        };
        if let Self::Array { entries, list } = self {
            if let Some((_, old)) = entries.iter_mut().find(|(a, _)| *a == k) {
                *old = v
            } else {
                entries.push((k, v))
            };
            *list = entries
                .iter()
                .enumerate()
                .all(|(i, (k, _))| *k == Key::Int(i as i64));
        }
    }
    pub fn push(&mut self, v: Value) {
        if !matches!(self, Self::Array { .. }) {
            *self = empty()
        }
        if let Self::Array { entries, .. } = self {
            entries.push((Key::Int(entries.len() as i64), v))
        }
    }
    pub fn contains_text(&self, t: &str) -> bool {
        self.vals().any(|v| v.text() == t)
    }
    pub fn meta(&self, k: &str) -> &Value {
        self.get("metadata").get(k)
    }
    pub fn metadata(&mut self, k: &str, v: Value) {
        let mut m = self.get("metadata").clone();
        m.put(k, v);
        self.put("metadata", m)
    }
}
pub fn lower(v: &str) -> String {
    v.to_ascii_lowercase()
}
pub fn unique(v: Vec<String>) -> Vec<String> {
    let mut seen = std::collections::HashSet::new();
    v.into_iter().filter(|v| seen.insert(v.clone())).collect()
}
/// PHP 64-bit numeric-string comparison, including integer overflow fallbacks.
/// See zendi_smart_strcmp in Zend/zend_operators.c, PHP 8.5.10.
pub fn php_cmp(a: &str, b: &str) -> Ordering {
    let (Some(x), Some(y)) = (numeric(a), numeric(b)) else {
        return a.cmp(b);
    };
    match (x, y) {
        (Numeric::Int(x), Numeric::Int(y)) => x.cmp(&y),
        (Numeric::Int(_), Numeric::Float(_, overflow)) if overflow != 0 => 0.cmp(&overflow),
        (Numeric::Float(_, overflow), Numeric::Int(_)) if overflow != 0 => overflow.cmp(&0),
        (x, y) => {
            let (x, xo) = x.float();
            let (y, yo) = y.float();
            if xo != 0 && xo == yo && x - y == 0.0 || x == y && !x.is_finite() {
                a.cmp(b)
            } else {
                x.partial_cmp(&y).unwrap_or(Ordering::Equal)
            }
        }
    }
}
#[derive(Clone, Copy)]
enum Numeric {
    Int(i64),
    Float(f64, i8),
}
impl Numeric {
    fn float(self) -> (f64, i8) {
        match self {
            Self::Int(v) => (v as f64, 0),
            Self::Float(v, o) => (v, o),
        }
    }
}
fn numeric(value: &str) -> Option<Numeric> {
    // Rust trim accepts Unicode spaces and parse accepts inf/NaN. PHP accepts neither.
    let value = value.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0b' | '\x0c'));
    let bytes = value.as_bytes();
    let mut i = 0;
    if bytes.first().is_some_and(|c| matches!(c, b'+' | b'-')) {
        i += 1;
    }
    let begin = i;
    while bytes.get(i).is_some_and(u8::is_ascii_digit) {
        i += 1;
    }
    let mut digits = i - begin;
    let mut integer = true;
    if bytes.get(i) == Some(&b'.') {
        integer = false;
        i += 1;
        let begin = i;
        while bytes.get(i).is_some_and(u8::is_ascii_digit) {
            i += 1;
        }
        digits += i - begin;
    }
    if digits == 0 {
        return None;
    }
    if bytes.get(i).is_some_and(|c| matches!(c, b'e' | b'E')) {
        integer = false;
        i += 1;
        if bytes.get(i).is_some_and(|c| matches!(c, b'+' | b'-')) {
            i += 1;
        }
        let begin = i;
        while bytes.get(i).is_some_and(u8::is_ascii_digit) {
            i += 1;
        }
        if begin == i {
            return None;
        }
    }
    if i != bytes.len() {
        return None;
    }
    if integer {
        if let Ok(v) = value.parse::<i64>() {
            return Some(Numeric::Int(v));
        }
    }
    value.parse::<f64>().ok().map(|v| {
        Numeric::Float(
            v,
            if integer {
                if value.starts_with('-') { -1 } else { 1 }
            } else {
                0
            },
        )
    })
}

/// Ordered map insertion with an auxiliary index, without scanning existing rows.
pub fn put_indexed(
    values: &mut Value,
    index: &mut std::collections::HashMap<String, usize>,
    name: &str,
    value: Value,
) {
    if !matches!(values, Value::Array { .. }) {
        *values = empty()
    }
    if let Value::Array { list, entries } = values {
        if let Some(i) = index.get(name) {
            entries[*i].1 = value
        } else {
            let k = key(name);
            *list = *list && k == Key::Int(entries.len() as i64);
            index.insert(name.into(), entries.len());
            entries.push((k, value))
        }
    }
}
pub fn append_indexed(
    values: &mut Value,
    index: &mut std::collections::HashMap<String, usize>,
    name: &str,
    value: Value,
) {
    if let Some(i) = index.get(name) {
        if let Value::Array { entries, .. } = values {
            entries[*i].1.push(value)
        }
    } else {
        put_indexed(values, index, name, list([value]))
    }
}

impl Value {
    /// Conservative allocation bound including string occurrences before interning.
    pub fn allocation_upper(&self) -> usize {
        match self{Self::String(v)=>v.len()+32,Self::Array{entries,..}=>entries.capacity()*std::mem::size_of::<(Key,Value)>()+entries.iter().map(|(k,v)|match k{Key::String(s)=>s.len()+32,Key::Int(_)=>0}+v.allocation_upper()).sum::<usize>(),_=>0}
    }
}

impl Value {
    pub fn container_bytes(&self) -> usize {
        if let Self::Array { entries, .. } = self {
            entries.capacity() * std::mem::size_of::<(Key, Value)>()
        } else {
            0
        }
    }
}
