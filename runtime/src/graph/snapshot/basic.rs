//! One bounded ready-product fragment at a time. Restore only hydrates and validates.
use super::*;
use crate::graph::{
    compose::State, dependency::Product, model::Value, resolve::Fragment, search::Search,
};
use std::collections::{BTreeMap, HashMap};
#[derive(Serialize, Deserialize)]
#[serde(tag = "part", deny_unknown_fields)]
enum Part {
    Field {
        field: usize,
        row: Value,
    },
    Operation {
        key: String,
        fragment: Fragment,
    },
    SearchMetadata {
        budget: usize,
        limited: bool,
        postings: usize,
    },
    SearchValue {
        value: String,
        owners: Vec<String>,
    },
    Dictionary {
        index: usize,
    },
    Gram {
        key: String,
        indices: Vec<usize>,
    },
}
#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Envelope {
    schema: String,
    generation: String,
    ordinal: usize,
    products: Vec<Part>,
}

#[derive(Serialize)]
#[serde(tag = "part")]
enum PartRef<'a> {
    Field {
        field: usize,
        row: RowRef<'a>,
    },
    Operation {
        key: &'a str,
        fragment: &'a Fragment,
    },
    SearchMetadata {
        budget: usize,
        limited: bool,
        postings: usize,
    },
    SearchValue {
        value: &'a str,
        owners: &'a Vec<String>,
    },
    Dictionary {
        index: usize,
    },
    Gram {
        key: &'a str,
        indices: &'a Vec<usize>,
    },
}
struct RowRef<'a> {
    key: &'a crate::graph::model::Key,
    value: &'a Value,
}
impl Serialize for RowRef<'_> {
    fn serialize<S: serde::Serializer>(&self, s: S) -> std::result::Result<S::Ok, S::Error> {
        use serde::ser::SerializeTuple;
        let mut tuple = s.serialize_tuple(2)?;
        tuple.serialize_element(if *self.key == crate::graph::model::Key::Int(0) {
            "list"
        } else {
            "map"
        })?;
        struct Entry<'a>(&'a crate::graph::model::Key, &'a Value);
        impl Serialize for Entry<'_> {
            fn serialize<S: serde::Serializer>(
                &self,
                s: S,
            ) -> std::result::Result<S::Ok, S::Error> {
                match self.0 {
                    crate::graph::model::Key::Int(k) => ("int", k, self.1).serialize(s),
                    crate::graph::model::Key::String(k) => ("string", k, self.1).serialize(s),
                }
            }
        }
        tuple.serialize_element(&[Entry(self.key, self.value)])?;
        tuple.end()
    }
}
#[derive(Serialize)]
struct EnvelopeRef<'a> {
    schema: &'static str,
    generation: &'a str,
    ordinal: usize,
    products: &'a [PartRef<'a>],
}
fn parts<'a>(p: &'a Product, mut consume: impl FnMut(PartRef<'a>) -> Result<()>) -> Result<()> {
    for (field, rows) in p.state.fields.iter().enumerate() {
        for (key, value) in rows.rows() {
            consume(PartRef::Field {
                field,
                row: RowRef { key, value },
            })?
        }
    }
    for (key, fragment) in &p.operations {
        consume(PartRef::Operation { key, fragment })?
    }
    consume(PartRef::SearchMetadata {
        budget: p.search.budget,
        limited: p.search.limited,
        postings: p.search.postings,
    })?;
    for (value, owners) in &p.search.values {
        consume(PartRef::SearchValue { value, owners })?
    }
    for index in &p.search.dictionary {
        consume(PartRef::Dictionary { index: *index })?
    }
    let mut grams: Vec<_> = p.search.grams.iter().collect();
    grams.sort_by(|a, b| a.0.cmp(b.0));
    for (key, indices) in grams {
        consume(PartRef::Gram { key, indices })?
    }
    Ok(())
}
#[derive(Clone, Copy)]
pub(crate) struct Plan {
    pub target: usize,
    pub maximum: usize,
    pub segments: usize,
    pub parts: usize,
}
pub(super) fn plan(g: &Generation, p: &Product, transition: usize) -> Result<Plan> {
    let reserve = p.validation_reserve()?;
    let headroom = transition
        .checked_sub(g.owned())
        .and_then(|v| v.checked_sub(reserve))
        .and_then(|v| v.checked_sub(crate::graph::FRAME_BYTES + 1024 * 1024))
        .ok_or("cache_basic_admission")?;
    let maximum = (headroom / 64).min(BASIC_BYTES);
    if maximum <= 512 {
        return Err("cache_basic_admission".into());
    }
    let target = maximum.min(256 * 1024);
    let (mut segments, mut count, mut bytes, mut all) = (0usize, 0usize, 0usize, 0usize);
    parts(p, |part| {
        let size = crate::graph::protocol::encoded_size(&part)?
            .checked_add(1)
            .ok_or("cache_overflow")?;
        if size.checked_add(512).is_none_or(|v| v > maximum) {
            return Err("cache_basic_part_admission".into());
        }
        if count == crate::graph::BASIC_SNAPSHOT_PARTS
            || count > 0
                && bytes
                    .checked_add(size)
                    .and_then(|v| v.checked_add(512))
                    .is_none_or(|v| v > target)
        {
            segments += 1;
            count = 0;
            bytes = 0
        }
        bytes = bytes.checked_add(size).ok_or("cache_overflow")?;
        count += 1;
        all += 1;
        Ok(())
    })?;
    if count > 0 {
        segments += 1
    }
    Ok(Plan {
        target,
        maximum,
        segments,
        parts: all,
    })
}
pub(super) fn write_product(
    dir: &Path,
    g: &Generation,
    p: &Product,
    plan: Plan,
) -> Result<Vec<Segment>> {
    let mut writer = BatchWriter {
        dir,
        generation: &g.id,
        segments: vec![],
        pending: vec![],
        pending_bytes: 0,
        plan,
    };
    parts(p, |part| writer.push(part))?;
    writer.flush()?;
    if writer.segments.len() != plan.segments {
        return Err("cache_basic_plan".into());
    }
    Ok(writer.segments)
}
pub(super) fn restore_product(
    dir: &Path,
    g: &mut Generation,
    segments: Vec<Segment>,
    store: &Store,
    metadata: usize,
) -> Result<()> {
    let mut product = Product {
        cached_owned: 0,
        state: State::default(),
        operations: BTreeMap::new(),
        trace: vec![],
        search: Search {
            cached_owned: 0,
            lookup: HashMap::new(),
            records: HashMap::new(),
            values: vec![],
            budget: 0,
            limited: false,
            dictionary: vec![],
            grams: HashMap::new(),
            postings: 0,
        },
    };
    let mut search_metadata = false;
    let mut phase = 0;
    let mut field_order = 0;
    for (i, segment) in segments.into_iter().enumerate() {
        if segment.ordinal != i
            || segment.file != format!("basic-{i:08}.json")
            || segment.bytes == 0
            || segment.bytes > BASIC_BYTES
        {
            return Err("cache_basic_segment".into());
        }
        let b = read_admitted(&dir.join(&segment.file), BASIC_BYTES, |actual| {
            if actual != segment.bytes {
                return Err("cache_segment_hash".into());
            }
            let retained = metadata
                .checked_add(product.owned_full())
                .ok_or("cache_overflow")?;
            store
                .admit_cache_step(
                    retained,
                    Some(g),
                    actual,
                    product
                        .growth_reserve()?
                        .checked_add(g.growth_reserve()?)
                        .ok_or("cache_overflow")?,
                )
                .map(|_| ())
        })?;
        if hash(&b) != segment.sha256 {
            return Err("cache_segment_hash".into());
        }
        let mut decoder = serde_json::Deserializer::from_slice(&b);
        decoder.disable_recursion_limit();
        let envelope = Envelope::deserialize(&mut decoder).map_err(|_| "cache_basic_shape")?;
        decoder.end().map_err(|_| "cache_basic_trailing")?;
        drop(b);
        if envelope.schema != "native-basic-v3"
            || envelope.generation != g.id
            || envelope.ordinal != i
        {
            return Err("cache_basic_generation".into());
        }
        if envelope.products.is_empty()
            || envelope.products.len() > crate::graph::BASIC_SNAPSHOT_PARTS
        {
            return Err("cache_basic_batch".into());
        }
        for part in envelope.products {
            match part {
                Part::Field { field, mut row } => {
                    g.intern_value(&mut row);
                    if phase > 0 || field >= 8 || field < field_order || row.rows().len() != 1 {
                        return Err("cache_basic_field".into());
                    }
                    field_order = field;
                    let (k, v) = row.rows().first().unwrap();
                    if product.state.fields[field]
                        .rows()
                        .iter()
                        .any(|(existing, _)| existing == k)
                    {
                        return Err("cache_basic_duplicate".into());
                    }
                    product.state.fields[field].put_key(k.clone(), v.clone());
                }
                Part::Operation { key, mut fragment } => {
                    g.intern_fragment(&mut fragment);
                    if phase > 1 || product.operations.insert(key, fragment).is_some() {
                        return Err("cache_dependency_duplicate".into());
                    }
                    phase = 1
                }
                Part::SearchMetadata {
                    budget,
                    limited,
                    postings,
                } => {
                    if search_metadata || phase > 1 {
                        return Err("cache_search_metadata".into());
                    }
                    search_metadata = true;
                    phase = 2;
                    product.search.budget = budget;
                    product.search.limited = limited;
                    product.search.postings = postings;
                }
                Part::SearchValue { value, owners } => {
                    if phase != 2 {
                        return Err("cache_search_order".into());
                    }
                    product.search.values.push((value, owners))
                }
                Part::Dictionary { index } => {
                    if !(2..=3).contains(&phase) {
                        return Err("cache_search_order".into());
                    }
                    phase = 3;
                    product.search.dictionary.push(index)
                }
                Part::Gram { key, indices } => {
                    if !(2..=4).contains(&phase)
                        || product.search.grams.insert(key, indices).is_some()
                    {
                        return Err("cache_search_order".into());
                    }
                    phase = 4
                }
            }
        }
    }
    if !search_metadata {
        return Err("cache_search_metadata".into());
    }
    store.admit_cache_step(
        metadata
            .checked_add(product.owned_full())
            .ok_or("cache_overflow")?,
        Some(g),
        0,
        product.validation_reserve()?,
    )?;
    product.validate()?;
    g.basic = Some(product);
    Ok(())
}

const BASIC_BYTES: usize = 16 * 1024 * 1024;
struct BatchWriter<'a> {
    dir: &'a Path,
    generation: &'a str,
    segments: Vec<Segment>,
    pending: Vec<PartRef<'a>>,
    pending_bytes: usize,
    plan: Plan,
}
impl<'a> BatchWriter<'a> {
    fn push(&mut self, part: PartRef<'a>) -> Result<()> {
        let bytes = crate::graph::protocol::encoded_size(&part)?;
        if bytes.checked_add(512).is_none_or(|v| v > self.plan.maximum) {
            return Err("cache_basic_part_admission".into());
        }
        if self.pending.len() == crate::graph::BASIC_SNAPSHOT_PARTS
            || !self.pending.is_empty()
                && self
                    .pending_bytes
                    .checked_add(bytes)
                    .and_then(|v| v.checked_add(512))
                    .is_none_or(|v| v > self.plan.target)
        {
            self.flush()?
        }
        self.pending_bytes = self
            .pending_bytes
            .checked_add(bytes + 1)
            .ok_or("cache_overflow")?;
        self.pending.push(part);
        Ok(())
    }
    fn flush(&mut self) -> Result<()> {
        if self.pending.is_empty() {
            return Ok(());
        }
        let ordinal = self.segments.len();
        let envelope = EnvelopeRef {
            schema: "native-basic-v3",
            generation: self.generation,
            ordinal,
            products: &self.pending,
        };
        if crate::graph::protocol::encoded_size(&envelope)? > self.plan.maximum {
            return Err("cache_basic_batch_limit".into());
        }
        let file = format!("basic-{ordinal:08}.json");
        let mut sink = HashWriter {
            writer: BufWriter::with_capacity(
                8192,
                OpenOptions::new()
                    .write(true)
                    .create_new(true)
                    .mode(0o600)
                    .open(self.dir.join(&file))
                    .map_err(io)?,
            ),
            hasher: Sha256::new(),
            bytes: 0,
        };
        serde_json::to_writer(&mut sink, &envelope).map_err(|_| "cache_basic_write")?;
        sink.flush().map_err(io)?;
        sink.writer.get_ref().sync_all().map_err(io)?;
        self.segments.push(Segment {
            file,
            bytes: sink.bytes,
            sha256: format!("{:x}", sink.hasher.finalize()),
            ordinal,
        });
        self.pending.clear();
        self.pending_bytes = 0;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    fn dir() -> std::path::PathBuf {
        let dir =
            std::env::temp_dir().join(format!("ak-r03-basic-batch-{}", crate::session().unwrap()));
        fs::create_dir(&dir).unwrap();
        fs::set_permissions(&dir, fs::Permissions::from_mode(0o700)).unwrap();
        dir
    }
    #[test]
    fn bounded_logical_parts_cross_32_and_4096_without_changing_order() {
        for total in [1usize, 31, 32, 33, 4096, 4097] {
            let root = dir();
            let generation = "a".repeat(64);
            let plan = Plan {
                target: 256 * 1024,
                maximum: 256 * 1024,
                segments: total.div_ceil(crate::graph::BASIC_SNAPSHOT_PARTS),
                parts: total,
            };
            let mut w = BatchWriter {
                dir: &root,
                generation: &generation,
                segments: vec![],
                pending: vec![],
                pending_bytes: 0,
                plan,
            };
            for index in 0..total {
                w.push(PartRef::Dictionary { index }).unwrap()
            }
            w.flush().unwrap();
            assert_eq!(w.segments.len(), plan.segments);
            let mut actual = vec![];
            for segment in &w.segments {
                let bytes = fs::read(root.join(&segment.file)).unwrap();
                assert_eq!(hash(&bytes), segment.sha256);
                let e: Envelope = serde_json::from_slice(&bytes).unwrap();
                assert!(e.products.len() <= crate::graph::BASIC_SNAPSHOT_PARTS);
                for part in e.products {
                    let Part::Dictionary { index } = part else {
                        panic!("wrong part")
                    };
                    actual.push(index)
                }
            }
            assert_eq!(actual, (0..total).collect::<Vec<_>>());
            fs::remove_dir_all(root).unwrap();
        }
    }
    #[test]
    fn bytes_split_before_part_cap_and_large_unrestorable_part_is_not_written() {
        let root = dir();
        let generation = "b".repeat(64);
        let value = "x".repeat(70_000);
        let owners = vec!["owner".into()];
        let plan = Plan {
            target: 128 * 1024,
            maximum: 256 * 1024,
            segments: 2,
            parts: 2,
        };
        let mut w = BatchWriter {
            dir: &root,
            generation: &generation,
            segments: vec![],
            pending: vec![],
            pending_bytes: 0,
            plan,
        };
        for _ in 0..2 {
            w.push(PartRef::SearchValue {
                value: &value,
                owners: &owners,
            })
            .unwrap()
        }
        w.flush().unwrap();
        assert_eq!(w.segments.len(), 2);
        assert!(w.segments.iter().all(|s| s.bytes <= 128 * 1024));
        let denied = dir();
        let plan = Plan {
            maximum: 64 * 1024,
            ..plan
        };
        let mut w = BatchWriter {
            dir: &denied,
            generation: &generation,
            segments: vec![],
            pending: vec![],
            pending_bytes: 0,
            plan,
        };
        assert_eq!(
            w.push(PartRef::SearchValue {
                value: &value,
                owners: &owners
            })
            .unwrap_err(),
            "cache_basic_part_admission"
        );
        assert_eq!(fs::read_dir(&denied).unwrap().count(), 0);
        fs::remove_dir_all(root).unwrap();
        fs::remove_dir_all(denied).unwrap();
    }
    #[test]
    fn row_borrowing_preserves_numeric_php_key_types() {
        for key in [
            crate::graph::model::Key::Int(0),
            crate::graph::model::Key::String("00".into()),
        ] {
            let value = Value::Float(1.0);
            let part = PartRef::Field {
                field: 0,
                row: RowRef {
                    key: &key,
                    value: &value,
                },
            };
            let bytes = serde_json::to_vec(&part).unwrap();
            let decoded: Part = serde_json::from_slice(&bytes).unwrap();
            let Part::Field { row, .. } = decoded else {
                panic!("wrong row")
            };
            assert_eq!(row.rows(), &[(key, value)]);
        }
    }
}
