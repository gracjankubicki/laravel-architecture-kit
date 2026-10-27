use super::Result;
use serde::{
    Deserialize, Deserializer, Serialize, Serializer,
    de::{self, DeserializeSeed, SeqAccess, Visitor},
};
use std::{collections::HashSet, fmt, sync::Arc};

/// PHP arrays are ordered and retain key types. No JSON object is stored here.
#[derive(Clone, Debug, PartialEq)]
pub enum Value {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    String(Arc<str>),
    Array {
        list: bool,
        entries: Vec<(Key, Value)>,
    },
}
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub enum Key {
    Int(i64),
    String(Arc<str>),
}
impl Serialize for Value {
    fn serialize<S: Serializer>(&self, s: S) -> std::result::Result<S::Ok, S::Error> {
        use serde::ser::SerializeTuple;
        let mut t = s.serialize_tuple(2)?;
        match self {
            Self::Null => {
                t.serialize_element("null")?;
                t.serialize_element(&())?;
            }
            Self::Bool(v) => {
                t.serialize_element("bool")?;
                t.serialize_element(v)?;
            }
            Self::Int(v) => {
                t.serialize_element("int")?;
                t.serialize_element(v)?;
            }
            Self::Float(v) => {
                t.serialize_element("float")?;
                t.serialize_element(v)?;
            }
            Self::String(v) => {
                t.serialize_element("string")?;
                t.serialize_element(v)?;
            }
            Self::Array { list, entries } => {
                t.serialize_element(if *list { "list" } else { "map" })?;
                t.serialize_element(&Entries(entries))?;
            }
        }
        t.end()
    }
}
struct Entries<'a>(&'a [(Key, Value)]);
impl Serialize for Entries<'_> {
    fn serialize<S: Serializer>(&self, s: S) -> std::result::Result<S::Ok, S::Error> {
        use serde::ser::SerializeSeq;
        let mut seq = s.serialize_seq(Some(self.0.len()))?;
        for (k, v) in self.0 {
            match k {
                Key::Int(n) => seq.serialize_element(&("int", n, v))?,
                Key::String(n) => seq.serialize_element(&("string", n, v))?,
            }
        }
        seq.end()
    }
}
struct Seed(usize);
impl<'de> DeserializeSeed<'de> for Seed {
    type Value = Value;
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> std::result::Result<Value, D::Error> {
        if self.0 > 128 {
            return Err(de::Error::custom("value_depth"));
        }
        d.deserialize_seq(ValueVisitor(self.0))
    }
}
struct ValueVisitor(usize);
impl<'de> Visitor<'de> for ValueVisitor {
    type Value = Value;
    fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
        f.write_str("tagged PHP value")
    }
    fn visit_seq<A: SeqAccess<'de>>(self, mut a: A) -> std::result::Result<Value, A::Error> {
        let tag: String = a
            .next_element()?
            .ok_or_else(|| de::Error::custom("value_tag"))?;
        macro_rules! take {
            ($t:ty) => {
                a.next_element::<$t>()?
                    .ok_or_else(|| de::Error::custom("value_missing"))?
            };
        }
        let v = match tag.as_str() {
            "null" => {
                take!(());
                Value::Null
            }
            "bool" => Value::Bool(take!(bool)),
            "int" => Value::Int(take!(i64)),
            "float" => {
                let n = take!(FloatOnly);
                Value::Float(n.0)
            }
            "string" => Value::String(take!(Arc<str>)),
            "list" | "map" => {
                let entries = a
                    .next_element_seed(EntrySeed(self.0 + 1))?
                    .ok_or_else(|| de::Error::custom("array_missing"))?;
                let mut seen = HashSet::new();
                for (k, _) in &entries {
                    if !seen.insert(k) {
                        return Err(de::Error::custom("duplicate_key"));
                    }
                    if let Key::String(s) = k {
                        if php_int_key(s) {
                            return Err(de::Error::custom("coerced_key"));
                        }
                    }
                }
                let list = entries
                    .iter()
                    .enumerate()
                    .all(|(i, (k, _))| *k == Key::Int(i as i64));
                if list != (tag == "list") {
                    return Err(de::Error::custom("array_tag"));
                }
                Value::Array { list, entries }
            }
            _ => return Err(de::Error::custom("value_tag")),
        };
        if a.next_element::<de::IgnoredAny>()?.is_some() {
            return Err(de::Error::custom("value_shape"));
        }
        Ok(v)
    }
}
#[derive(Deserialize)]
#[serde(untagged)]
enum KeyScalar {
    Int(i64),
    String(Arc<str>),
}
struct EntrySeed(usize);
impl<'de> DeserializeSeed<'de> for EntrySeed {
    type Value = Vec<(Key, Value)>;
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> std::result::Result<Self::Value, D::Error> {
        d.deserialize_seq(self)
    }
}
impl<'de> Visitor<'de> for EntrySeed {
    type Value = Vec<(Key, Value)>;
    fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
        f.write_str("typed entries")
    }
    fn visit_seq<A: SeqAccess<'de>>(self, mut a: A) -> std::result::Result<Self::Value, A::Error> {
        let mut v = Vec::new();
        while let Some(e) = a.next_element_seed(PairSeed(self.0))? {
            v.push(e)
        }
        Ok(v)
    }
}
struct PairSeed(usize);
impl<'de> DeserializeSeed<'de> for PairSeed {
    type Value = (Key, Value);
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> std::result::Result<Self::Value, D::Error> {
        d.deserialize_seq(self)
    }
}
impl<'de> Visitor<'de> for PairSeed {
    type Value = (Key, Value);
    fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
        f.write_str("typed key and value")
    }
    fn visit_seq<A: SeqAccess<'de>>(self, mut a: A) -> std::result::Result<Self::Value, A::Error> {
        let tag: String = a
            .next_element()?
            .ok_or_else(|| de::Error::custom("key_tag"))?;
        let k = match (tag.as_str(), a.next_element::<KeyScalar>()?) {
            ("int", Some(KeyScalar::Int(k))) => Key::Int(k),
            ("string", Some(KeyScalar::String(k))) => Key::String(k),
            _ => return Err(de::Error::custom("key_type")),
        };
        let v = a
            .next_element_seed(Seed(self.0))?
            .ok_or_else(|| de::Error::custom("key_value"))?;
        if a.next_element::<de::IgnoredAny>()?.is_some() {
            return Err(de::Error::custom("key_shape"));
        }
        Ok((k, v))
    }
}
struct FloatOnly(f64);
impl<'de> Deserialize<'de> for FloatOnly {
    fn deserialize<D: Deserializer<'de>>(d: D) -> std::result::Result<Self, D::Error> {
        struct F;
        impl<'de> Visitor<'de> for F {
            type Value = FloatOnly;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("float token")
            }
            fn visit_f64<E: de::Error>(self, v: f64) -> std::result::Result<FloatOnly, E> {
                if v.is_finite() {
                    Ok(FloatOnly(v))
                } else {
                    Err(E::custom("nonfinite"))
                }
            }
        }
        d.deserialize_any(F)
    }
}
impl<'de> Deserialize<'de> for Value {
    fn deserialize<D: Deserializer<'de>>(d: D) -> std::result::Result<Self, D::Error> {
        Seed(0).deserialize(d)
    }
}
fn php_int_key(s: &str) -> bool {
    s.parse::<i64>().is_ok_and(|n| n.to_string() == s)
}
impl Value {
    pub fn field(&self, k: &str) -> Result<&Self> {
        match self {
            Self::Array { entries, .. } => entries
                .iter()
                .find_map(|(key, v)| match key {
                    Key::String(s) if s.as_ref() == k => Some(v),
                    _ => None,
                })
                .ok_or("missing_field".into()),
            _ => Err("expected_map".into()),
        }
    }
    pub fn string(&self) -> Result<&str> {
        if let Self::String(v) = self {
            Ok(v)
        } else {
            Err("expected_string".into())
        }
    }
    pub fn boolean(&self) -> Result<bool> {
        if let Self::Bool(v) = self {
            Ok(*v)
        } else {
            Err("expected_bool".into())
        }
    }
    pub fn int(&self) -> Result<i64> {
        if let Self::Int(v) = self {
            Ok(*v)
        } else {
            Err("expected_int".into())
        }
    }
    pub fn list(&self) -> Result<Vec<&Self>> {
        if let Self::Array {
            list: true,
            entries,
        } = self
        {
            Ok(entries.iter().map(|(_, v)| v).collect())
        } else {
            Err("expected_list".into())
        }
    }
    pub fn entries(&self) -> Result<&[(Key, Self)]> {
        if let Self::Array { entries, .. } = self {
            Ok(entries)
        } else {
            Err("expected_array".into())
        }
    }
    pub fn keys(&self, keys: &[&str]) -> Result<()> {
        let e = self.entries()?;
        if e.len() != keys.len()
            || e.iter()
                .zip(keys)
                .any(|((k, _), s)| !matches!(k,Key::String(k)if k.as_ref()==*s))
        {
            Err("record_shape".into())
        } else {
            Ok(())
        }
    }
    pub fn nullable_str(&self) -> Result<Option<&str>> {
        if *self == Self::Null {
            Ok(None)
        } else {
            Ok(Some(self.string()?))
        }
    }
    pub fn line(&self, lines: usize) -> Result<()> {
        let n = self.int()?;
        if n < 1 || n as usize > lines {
            Err("line_bounds".into())
        } else {
            Ok(())
        }
    }
    pub fn offset(&self, len: usize) -> Result<()> {
        let n = self.int()?;
        if n < 0 || n as usize > len {
            Err("offset_bounds".into())
        } else {
            Ok(())
        }
    }
    pub fn intern(&mut self, pool: &mut HashSet<Arc<str>>) {
        self.intern_counted(pool);
    }
    pub fn intern_counted(&mut self, pool: &mut HashSet<Arc<str>>) -> usize {
        match self {
            Self::String(s) => intern_counted(s, pool),
            Self::Array { entries, .. } => {
                let mut bytes = 0;
                for (k, v) in entries {
                    if let Key::String(s) = k {
                        bytes += intern_counted(s, pool)
                    }
                    bytes += v.intern_counted(pool)
                }
                bytes
            }
            _ => 0,
        }
    }
    pub fn detach_strings(&mut self) {
        match self {
            Self::String(s) => *s = Arc::from(s.as_ref()),
            Self::Array { entries, .. } => {
                for (k, v) in entries {
                    if let Key::String(s) = k {
                        *s = Arc::from(s.as_ref())
                    }
                    v.detach_strings()
                }
            }
            _ => (),
        }
    }
    pub fn owned(&self) -> usize {
        match self {
            Self::Array { entries, .. } => {
                entries.capacity() * std::mem::size_of::<(Key, Value)>()
                    + entries.iter().map(|(_, v)| v.owned()).sum::<usize>()
            }
            _ => 0,
        }
    }
}
pub fn intern(s: &mut Arc<str>, pool: &mut HashSet<Arc<str>>) {
    intern_counted(s, pool);
}
pub fn intern_counted(s: &mut Arc<str>, pool: &mut HashSet<Arc<str>>) -> usize {
    if let Some(old) = pool.get(s) {
        *s = old.clone();
        0
    } else {
        let bytes = s.len() + 2 * std::mem::size_of::<usize>();
        pool.insert(s.clone());
        bytes
    }
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Options {
    pub impact: bool,
    pub catalog: bool,
    pub preserve_occurrences: bool,
    pub classification_fingerprint: String,
}
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Positions {
    pub length: usize,
    pub sha256: String,
    pub newlines: Vec<usize>,
}
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Segment {
    pub start: usize,
    pub original: usize,
    pub length: usize,
    pub copied: bool,
}
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Witness {
    pub original: Positions,
    pub prepared: Positions,
    pub mapping_version: String,
    pub segments: Vec<Segment>,
    pub mapping_fingerprint: String,
}
/// Fixed full-file schema. Dynamic, ordered PHP values are confined to products
/// whose metadata is extensible; the envelope is never a serde_json::Value.
#[derive(Clone, Debug, PartialEq)]
pub struct FileEntry {
    pub symbols: Value,
    pub edges: Value,
    pub test_invocations: Value,
    pub impact: Value,
    pub catalog: Value,
    pub syntax_error: Value,
    pub syntax_error_line: Value,
}
impl FileEntry {
    fn values(&self) -> [(&str, &Value); 7] {
        [
            ("symbols", &self.symbols),
            ("edges", &self.edges),
            ("test_invocations", &self.test_invocations),
            ("impact", &self.impact),
            ("catalog", &self.catalog),
            ("syntax_error", &self.syntax_error),
            ("syntax_error_line", &self.syntax_error_line),
        ]
    }
    pub fn field(&self, key: &str) -> Result<&Value> {
        self.values()
            .into_iter()
            .find_map(|(k, v)| if k == key { Some(v) } else { None })
            .ok_or("entry_field".into())
    }
    pub fn owned(&self) -> usize {
        self.values().into_iter().map(|(_, v)| v.owned()).sum()
    }
    pub fn detach_strings(&mut self) {
        for v in [
            &mut self.symbols,
            &mut self.edges,
            &mut self.test_invocations,
            &mut self.impact,
            &mut self.catalog,
            &mut self.syntax_error,
            &mut self.syntax_error_line,
        ] {
            v.detach_strings()
        }
    }
    pub fn intern(&mut self, p: &mut HashSet<Arc<str>>) {
        self.intern_counted(p);
    }
    pub fn intern_counted(&mut self, p: &mut HashSet<Arc<str>>) -> usize {
        [
            &mut self.symbols,
            &mut self.edges,
            &mut self.test_invocations,
            &mut self.impact,
            &mut self.catalog,
            &mut self.syntax_error,
            &mut self.syntax_error_line,
        ]
        .into_iter()
        .map(|v| v.intern_counted(p))
        .sum()
    }
}
impl Serialize for FileEntry {
    fn serialize<S: Serializer>(&self, s: S) -> std::result::Result<S::Ok, S::Error> {
        let fields = self.values().map(|(key, v)| ("string", key, v));
        ("map", fields).serialize(s)
    }
}
impl<'de> Deserialize<'de> for FileEntry {
    fn deserialize<D: Deserializer<'de>>(d: D) -> std::result::Result<Self, D::Error> {
        let v = Value::deserialize(d)?;
        v.keys(&[
            "symbols",
            "edges",
            "test_invocations",
            "impact",
            "catalog",
            "syntax_error",
            "syntax_error_line",
        ])
        .map_err(de::Error::custom)?;
        let Value::Array { entries, .. } = v else {
            unreachable!()
        };
        let mut it = entries.into_iter().map(|(_, v)| v);
        Ok(Self {
            symbols: it.next().unwrap(),
            edges: it.next().unwrap(),
            test_invocations: it.next().unwrap(),
            impact: it.next().unwrap(),
            catalog: it.next().unwrap(),
            syntax_error: it.next().unwrap(),
            syntax_error_line: it.next().unwrap(),
        })
    }
}
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Record {
    pub ordinal: usize,
    pub owner: Arc<str>,
    pub kind: String,
    pub sha256: String,
    pub php_version: String,
    pub options: Options,
    pub witness: Witness,
    pub entry: FileEntry,
}
pub fn hash_shape(s: &str) -> bool {
    s.len() == 64
        && s.bytes()
            .all(|c| c.is_ascii_digit() || (b'a'..=b'f').contains(&c))
}
impl Witness {
    pub fn validate(&self) -> Result<()> {
        let _phase = super::measure::Span::unchanged(
            "validate_witness",
            Some(
                self.original.newlines.capacity() * 8
                    + self.prepared.newlines.capacity() * 8
                    + self.segments.capacity() * std::mem::size_of::<Segment>(),
            ),
        );
        for p in [&self.original, &self.prepared] {
            if p.length > 8 * 1024 * 1024
                || !hash_shape(&p.sha256)
                || p.newlines.iter().any(|n| *n >= p.length)
                || p.newlines.windows(2).any(|w| w[0] >= w[1])
            {
                return Err("position_witness".into());
            }
        }
        if self.segments.is_empty() {
            if self.original != self.prepared
                || !self.mapping_version.is_empty()
                || !self.mapping_fingerprint.is_empty()
            {
                return Err("plain_identity".into());
            }
            return Ok(());
        }
        let mut cursor = 0usize;
        for s in &self.segments {
            if s.start != cursor
                || s.length == 0
                || s.original > self.original.length
                || s.copied
                    && s.original
                        .checked_add(s.length)
                        .is_none_or(|n| n > self.original.length)
            {
                return Err("mapping_bounds".into());
            }
            cursor = cursor.checked_add(s.length).ok_or("mapping_overflow")?;
        }
        let wire = serde_json::to_vec(&(
            &self.original.sha256,
            &self.prepared.sha256,
            &self.mapping_version,
            &self.segments,
        ))
        .map_err(|_| "mapping_encode")?;
        if cursor != self.prepared.length
            || self.mapping_version.is_empty()
            || super::snapshot::hash(&wire) != self.mapping_fingerprint
        {
            return Err("mapping_identity".into());
        }
        Ok(())
    }
}
impl Record {
    pub fn decode(bytes: &[u8]) -> Result<Self> {
        if bytes.len() > super::RECORD_BYTES {
            return Err("record_limit".into());
        }
        let mut d = serde_json::Deserializer::from_slice(bytes);
        d.disable_recursion_limit();
        let r = Self::deserialize(&mut d).map_err(|_| "record_decode")?;
        d.end().map_err(|_| "record_trailing")?;
        Ok(r)
    }
    pub fn owned(&self) -> usize {
        self.entry.owned()
            + self.witness.original.newlines.capacity() * 8
            + self.witness.prepared.newlines.capacity() * 8
            + self.witness.segments.capacity() * std::mem::size_of::<Segment>()
            + self.kind.capacity()
            + self.sha256.capacity()
            + self.php_version.capacity()
            + self.options.classification_fingerprint.capacity()
            + self.witness.mapping_version.capacity()
            + self.witness.mapping_fingerprint.capacity()
            + self.witness.original.sha256.capacity()
            + self.witness.prepared.sha256.capacity()
    }
    pub fn validate(&self) -> Result<()> {
        let _phase = super::measure::Span::unchanged("validate", Some(self.owned()));
        self.witness.validate()?;
        let expected = if [
            "composer.json",
            "composer.lock",
            "vendor/composer/installed.json",
        ]
        .contains(&self.owner.as_ref())
        {
            "composer"
        } else if self.owner.ends_with(".blade.php") {
            "blade"
        } else {
            "php"
        };
        if self.owner.is_empty()
            || self.kind != expected
            || self.sha256 != self.witness.original.sha256
            || self.php_version.is_empty()
        {
            return Err("record_identity".into());
        }
        let lines = self.witness.original.newlines.len() + 1;
        let len = self.witness.original.length;
        for v in self.entry.field("symbols")?.list()? {
            let a = v.list()?;
            if a.len() != 6 {
                return Err("symbol_shape".into());
            }
            if a[0].string()?.is_empty()
                || a[4].string()?.is_empty()
                || !["class", "interface", "trait", "enum", "file"].contains(&a[3].string()?)
            {
                return Err("symbol_shape".into());
            }
            a[1].line(lines)?;
            a[2].string()?;
            a[5].boolean()?;
        }
        for v in self.entry.field("edges")?.list()? {
            let a = v.list()?;
            if a.len() != 5 {
                return Err("edge_shape".into());
            }
            if a[0].string()?.is_empty()
                || a[1].string()?.is_empty()
                || ![
                    "extends",
                    "implements",
                    "trait",
                    "parameter",
                    "return",
                    "property",
                    "new",
                    "static",
                    "instanceof",
                    "catch",
                    "attribute",
                    "eloquent-relation",
                    "class-reference",
                    "class-constant",
                ]
                .contains(&a[3].string()?)
            {
                return Err("edge_shape".into());
            }
            a[2].line(lines)?;
            a[4].boolean()?;
        }
        for v in self.entry.field("test_invocations")?.list()? {
            v.keys(&[
                "line",
                "kind",
                "context",
                "verb",
                "uri",
                "route",
                "parameters",
                "model",
                "reason",
                "dispatch",
                "command",
            ])?;
            v.field("line")?.line(lines)?;
            if !["http", "factory", "factory-config", "artisan"]
                .contains(&v.field("kind")?.string()?)
            {
                return Err("invocation_kind".into());
            }
            for k in [
                "context", "verb", "uri", "route", "model", "reason", "dispatch", "command",
            ] {
                v.field(k)?.nullable_str()?;
            }
            for (_, v) in v.field("parameters")?.entries()? {
                v.nullable_str()?;
            }
        }
        self.entry.field("syntax_error_line")?.line(lines)?;
        self.entry.field("syntax_error")?.nullable_str()?;
        for (k, want) in [
            ("impact", self.options.impact),
            ("catalog", self.options.catalog),
        ] {
            if (self.entry.field(k)? != &Value::Null) != want {
                return Err("option_product".into());
            }
        }
        if self.options.impact {
            let i = self.entry.field("impact")?;
            for (_, c) in i.field("classes")?.entries()? {
                c.field("line")?.line(lines)?;
                for (_, m) in c.field("methods")?.entries()? {
                    m.field("line")?.line(lines)?;
                }
            }
            for k in ["calls", "notices"] {
                for c in i.field(k)?.list()? {
                    c.field("line")?.line(lines)?;
                }
            }
        }
        if self.options.catalog {
            validate_catalog(self.entry.field("catalog")?, lines, len)?
        }
        Ok(())
    }
}
fn validate_catalog(c: &Value, lines: usize, len: usize) -> Result<()> {
    c.keys(&[
        "v",
        "elements",
        "relations",
        "diagnostics",
        "descriptor_receivers",
    ])?;
    if c.field("v")?.int()? != 3 {
        return Err("catalog_version".into());
    }
    let elements = c.field("elements")?.list()?;
    let mut ids = HashSet::new();
    for e in &elements {
        e.keys(&[
            "id", "name", "kind", "line", "end_line", "offset", "parent", "roles", "metadata",
        ])?;
        let id = e.field("id")?.string()?;
        if id.is_empty() {
            return Err("duplicate_local_id".into());
        }
        ids.insert(id);
        e.field("name")?.string()?;
        e.field("kind")?.string()?;
        for k in ["line", "end_line"] {
            e.field(k)?.line(lines)?;
        }
        if e.field("end_line")?.int()? < e.field("line")?.int()? {
            return Err("line_order".into());
        }
        e.field("offset")?.offset(len)?;
        for r in e.field("roles")?.list()? {
            r.string()?;
        }
    }
    for e in &elements {
        if let Some(p) = e.field("parent")?.nullable_str()? {
            if !ids.contains(p) || p == e.field("id")?.string()? {
                return Err("parent_ref".into());
            }
        }
        metadata(e.field("metadata")?, &ids, len)?;
    }
    for r in c.field("relations")?.list()? {
        r.keys(&[
            "from",
            "to",
            "kind",
            "line",
            "end_line",
            "resolution",
            "metadata",
        ])?;
        if !ids.contains(r.field("from")?.string()?) {
            return Err("from_ref".into());
        }
        let to = r.field("to")?.string()?;
        if to.starts_with("element:") && !ids.contains(to) {
            return Err("to_ref".into());
        }
        r.field("kind")?.string()?;
        r.field("resolution")?.string()?;
        r.field("line")?.line(lines)?;
        r.field("end_line")?.line(lines)?;
        metadata(r.field("metadata")?, &ids, len)?;
    }
    for d in c.field("diagnostics")?.list()? {
        d.field("line")?.line(lines)?;
        d.field("code")?.string()?;
        d.field("message")?.string()?;
        if let Some(s) = d.field("subject")?.nullable_str()? {
            if s.starts_with("element:") && !ids.contains(s) {
                return Err("subject_ref".into());
            }
        }
    }
    let table = c.field("descriptor_receivers")?;
    for (k, v) in table.entries()? {
        let Key::String(k) = k else {
            return Err("descriptor_key".into());
        };
        if super::snapshot::hash(v.string()?.as_bytes()) != k.as_ref() {
            return Err("descriptor_hash".into());
        }
    }
    descriptor_refs(c, table, 0)?;
    Ok(())
}
fn descriptor_refs(v: &Value, table: &Value, depth: usize) -> Result<()> {
    if depth > 128 {
        return Err("descriptor_depth".into());
    }
    match v {
        Value::Array { entries, .. } => {
            for (_, v) in entries {
                descriptor_refs(v, table, depth + 1)?
            }
        }
        Value::String(s) => {
            if let Some(s) = s.strip_prefix("@property:") {
                let (owner, _) = s.rsplit_once('#').ok_or("property_descriptor")?;
                if let Some(key) = owner.strip_prefix("@descriptor:") {
                    table.field(key)?.string()?;
                }
            }
            for p in ["@return:", "@method-callable:", "@after-argument:"] {
                if let Some(body) = s.strip_prefix(p) {
                    if body.len() > 16384 {
                        return Err("descriptor_size".into());
                    }
                    let data: serde_json::Value =
                        serde_json::from_str(body).map_err(|_| "descriptor_json")?;
                    let a = data.as_array().ok_or("descriptor_array")?;
                    for position in if a.len() == 7 { vec![0, 1] } else { vec![0] } {
                        if let Some(key) = a
                            .get(position)
                            .and_then(|v| v.as_str())
                            .and_then(|s| s.strip_prefix("@descriptor:"))
                        {
                            table.field(key)?.string()?;
                        }
                    }
                }
            }
        }
        _ => (),
    }
    Ok(())
}
fn metadata(v: &Value, ids: &HashSet<&str>, len: usize) -> Result<()> {
    v.entries()?;
    for k in [
        "offset",
        "end_offset",
        "receiver_origin",
        "receiver_instance_origin",
        "value_origin",
    ] {
        if let Ok(v) = v.field(k) {
            if v != &Value::Null {
                v.offset(len)?
            }
        }
    }
    for k in ["callback", "registration_scope"] {
        if let Ok(v) = v.field(k) {
            if let Some(s) = v.nullable_str()? {
                if !ids.contains(s) {
                    return Err("metadata_owner".into());
                }
            }
        }
    }
    if let Ok(v) = v.field("bound_callable") {
        if let Ok(v) = v.field("creator") {
            if let Some(s) = v.nullable_str()? {
                if !ids.contains(s) {
                    return Err("metadata_creator".into());
                }
            }
        }
    }
    Ok(())
}

#[cfg(test)]
mod descriptor_tests_r3 {
    use super::*;
    use crate::graph::compose::value::{list, map, s};
    #[test]
    fn malformed_and_bounded_descriptors_are_rejected_without_a_resolver() {
        let table = list([]);
        for (value, reason) in [
            ("@return:bad", "descriptor_json"),
            ("@method-callable:{}", "descriptor_array"),
            ("@property:broken", "property_descriptor"),
        ] {
            assert_eq!(descriptor_refs(&s(value), &table, 0).unwrap_err(), reason);
        }
        assert!(descriptor_refs(&s("@return:[\"@descriptor:missing\"]"), &table, 0).is_err());
        assert!(descriptor_refs(&s("@property:@descriptor:missing#value"), &table, 0).is_err());
        assert_eq!(
            descriptor_refs(
                &s(format!("@after-argument:{}", " ".repeat(16385))),
                &table,
                0
            )
            .unwrap_err(),
            "descriptor_size"
        );
        let mut deep = Value::Null;
        for _ in 0..129 {
            deep = list([deep])
        }
        assert_eq!(
            descriptor_refs(&deep, &table, 0).unwrap_err(),
            "descriptor_depth"
        );
        let table = map([("known", s("[]"))]);
        descriptor_refs(&s("@return:[\"@descriptor:known\"]"), &table, 0).unwrap();
        descriptor_refs(&s("@property:@descriptor:known#value"), &table, 0).unwrap();
    }
}
