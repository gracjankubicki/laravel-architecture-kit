use super::{
    FRAME_BYTES, Result,
    model::{Options, Record, intern_counted},
};
use serde::{Deserialize, Serialize};
use std::{
    collections::{BTreeMap, HashMap, HashSet},
    mem::size_of,
    sync::Arc,
};
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Identity {
    pub repository: String,
    pub profile: String,
    pub product: String,
    pub parser: String,
    pub tool: String,
    pub configuration: String,
    pub capture: String,
}
impl Identity {
    pub fn validate(&self) -> Result<()> {
        if [
            &self.repository,
            &self.profile,
            &self.product,
            &self.parser,
            &self.tool,
            &self.configuration,
            &self.capture,
        ]
        .iter()
        .any(|v| v.is_empty() || v.len() > 4096)
        {
            Err("identity_shape".into())
        } else {
            Ok(())
        }
    }
}
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Owner {
    pub owner: Arc<str>,
    pub sha256: String,
    pub kind: String,
    pub php_version: String,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Limits {
    pub resident_bytes: usize,
    pub transition_bytes: usize,
}
#[derive(Debug, Default)]
struct Ledger {
    records: usize,
    pool: usize,
    vectors: usize,
    scope: usize,
    identity: usize,
    canonicals: usize,
}
#[derive(Debug)]
pub struct Generation {
    pub basic: Option<super::dependency::Product>,
    pub canonical_files: BTreeMap<String, String>,
    ledger: Ledger,
    pub id: String,
    pub identity: Identity,
    pub options: Options,
    pub scope: Vec<Owner>,
    pub records: Vec<Record>,
    pool: HashSet<Arc<str>>,
    pub owners: HashMap<Arc<str>, usize>,
    /// Local ID collisions in different files are kept, never overwritten.
    pub names: HashMap<Arc<str>, Vec<(usize, usize)>>,
    pub out: HashMap<Arc<str>, Vec<(usize, usize)>>,
    pub incoming: HashMap<Arc<str>, Vec<(usize, usize)>>,
}
impl Generation {
    fn equivalent(&self, other: &Self) -> bool {
        self.identity == other.identity
            && self.options == other.options
            && self.scope == other.scope
            && self.records == other.records
            && self.canonical_files == other.canonical_files
            && match (&self.basic, &other.basic) {
                (None, None) => true,
                (Some(a), Some(b)) => a.equivalent(b),
                _ => false,
            }
    }

    fn new(identity: Identity, options: Options, scope: Vec<Owner>) -> Result<Self> {
        identity.validate()?;
        let mut names = HashSet::new();
        for o in &scope {
            if o.owner.is_empty()
                || !names.insert(o.owner.clone())
                || !super::model::hash_shape(&o.sha256)
            {
                return Err("scope_shape".into());
            }
        }
        let ledger = Ledger {
            scope: scope
                .iter()
                .map(|o| {
                    o.owner.len()
                        + o.sha256.capacity()
                        + o.kind.capacity()
                        + o.php_version.capacity()
                })
                .sum(),
            identity: serde_json::to_vec(&identity).map_or(0, |v| v.len()),
            ..Ledger::default()
        };
        Ok(Self {
            ledger,
            basic: None,
            canonical_files: BTreeMap::new(),
            id: crate::session()?,
            identity,
            options,
            scope,
            records: vec![],
            pool: HashSet::new(),
            owners: HashMap::new(),
            names: HashMap::new(),
            out: HashMap::new(),
            incoming: HashMap::new(),
        })
    }
    pub fn append(&mut self, mut r: Record) -> Result<()> {
        let phase = super::measure::Span::new("append", Some(self.owned()));
        if !super::protocol::encoded_size(&r).is_ok_and(|n| n <= super::RECORD_BYTES) {
            return Err("record_limit".into());
        }
        let Some(o) = self.scope.get(self.records.len()) else {
            return Err("coverage_extra".into());
        };
        if r.ordinal != self.records.len()
            || r.owner != o.owner
            || r.sha256 != o.sha256
            || r.kind != o.kind
            || r.php_version != o.php_version
            || r.options != self.options
        {
            return Err("coverage_mismatch".into());
        }
        r.validate()?;
        self.ledger.pool += intern_counted(&mut r.owner, &mut self.pool);
        self.ledger.pool += r.entry.intern_counted(&mut self.pool);
        self.ledger.records += r.owned();
        let ordinal = r.ordinal;
        if r.options.catalog {
            let c = r.entry.field("catalog")?;
            for (idx, e) in c.field("elements")?.list()?.iter().enumerate() {
                let name: Arc<str> = Arc::from(e.field("name")?.string()?);
                let values = self
                    .names
                    .entry(self.pool.get(&name).cloned().unwrap_or(name))
                    .or_default();
                let before = values.capacity();
                values.push((ordinal, idx));
                self.ledger.vectors += (values.capacity() - before) * size_of::<(usize, usize)>();
            }
            for (idx, e) in c.field("relations")?.list()?.iter().enumerate() {
                for (field, index) in [("from", &mut self.out), ("to", &mut self.incoming)] {
                    let id: Arc<str> = Arc::from(e.field(field)?.string()?);
                    let values = index
                        .entry(self.pool.get(&id).cloned().unwrap_or(id))
                        .or_default();
                    let before = values.capacity();
                    values.push((ordinal, idx));
                    self.ledger.vectors +=
                        (values.capacity() - before) * size_of::<(usize, usize)>();
                }
            }
        }
        self.owners.insert(r.owner.clone(), ordinal);
        self.records.push(r);
        phase.finish(Some(self.owned()));
        Ok(())
    }
    /// Extra allocation headroom when existing append-only buffers grow. Frozen
    /// record payloads and interned string bytes are not copied by table growth.
    pub fn growth_reserve(&self) -> Result<usize> {
        fn bytes(capacity: usize, width: usize) -> Result<usize> {
            capacity.checked_mul(width).ok_or("budget_overflow".into())
        }
        let mut total = bytes(self.records.capacity(), size_of::<Record>())?;
        for (capacity, width) in [
            (self.pool.capacity(), size_of::<Arc<str>>() + 8),
            (self.owners.capacity(), size_of::<(Arc<str>, usize)>() + 8),
            (
                self.names.capacity(),
                size_of::<(Arc<str>, Vec<(usize, usize)>)>() + 8,
            ),
            (
                self.out.capacity(),
                size_of::<(Arc<str>, Vec<(usize, usize)>)>() + 8,
            ),
            (
                self.incoming.capacity(),
                size_of::<(Arc<str>, Vec<(usize, usize)>)>() + 8,
            ),
        ] {
            total = total
                .checked_add(bytes(capacity, width)?)
                .ok_or("budget_overflow")?;
        }
        total = total
            .checked_add(self.ledger.vectors)
            .ok_or("budget_overflow")?;
        // Covers replacement buckets/control bytes and simultaneous old/new
        // allocations during geometric growth. New entries are charged per record.
        total.checked_mul(4).ok_or("budget_overflow".into())
    }
    pub fn intern_value(&mut self, v: &mut super::model::Value) {
        self.ledger.pool += v.intern_counted(&mut self.pool);
    }
    pub fn intern_fragment(&mut self, f: &mut super::resolve::Fragment) {
        self.ledger.pool += super::dependency::intern_fragment(f, &mut self.pool);
    }
    pub(crate) fn intern_product(&mut self, p: &mut super::dependency::Product) {
        self.ledger.pool += p.intern(&mut self.pool);
        p.refresh_owned();
    }
    pub fn put_canonical(&mut self, path: String, id: String) -> Result<()> {
        if let Some(old) = self.canonical_files.get(&path) {
            if old != &id {
                return Err("canonical_files_conflict".into());
            }
            return Ok(());
        }
        self.ledger.canonicals += path.capacity() + id.capacity() + 96;
        self.canonical_files.insert(path, id);
        Ok(())
    }
    pub fn owned(&self) -> usize {
        size_of::<Self>()
            + self.basic.as_ref().map_or(0, |p| p.owned())
            + self.ledger.canonicals
            + self.records.capacity() * size_of::<Record>()
            + self.ledger.records
            + self.pool.capacity() * (size_of::<Arc<str>>() + 8)
            + self.ledger.pool
            + self.owners.capacity() * (size_of::<(Arc<str>, usize)>() + 8)
            + [&self.names, &self.out, &self.incoming]
                .iter()
                .map(|m| m.capacity() * (size_of::<(Arc<str>, Vec<(usize, usize)>)>() + 8))
                .sum::<usize>()
            + self.ledger.vectors
            + self.scope.capacity() * size_of::<Owner>()
            + self.ledger.scope
            + self.ledger.identity
            + self.options.classification_fingerprint.capacity()
            + self.id.capacity()
    }
    pub fn owned_full(&self) -> usize {
        super::measure::accounting_scan();
        fn index(m: &HashMap<Arc<str>, Vec<(usize, usize)>>) -> usize {
            m.capacity() * (size_of::<(Arc<str>, Vec<(usize, usize)>)>() + 8)
                + m.values()
                    .map(|v| v.capacity() * size_of::<(usize, usize)>())
                    .sum::<usize>()
        }
        size_of::<Self>()
            + self
                .canonical_files
                .iter()
                .map(|(p, id)| p.capacity() + id.capacity() + 96)
                .sum::<usize>()
            + self.basic.as_ref().map_or(0, |p| p.owned_full())
            + self.records.capacity() * size_of::<Record>()
            + self.records.iter().map(Record::owned).sum::<usize>()
            + self.pool.capacity() * (size_of::<Arc<str>>() + 8)
            + self
                .pool
                .iter()
                .map(|s| s.len() + 2 * size_of::<usize>())
                .sum::<usize>()
            + self.owners.capacity() * (size_of::<(Arc<str>, usize)>() + 8)
            + index(&self.names)
            + index(&self.out)
            + index(&self.incoming)
            + self.scope.capacity() * size_of::<Owner>()
            + self
                .scope
                .iter()
                .map(|o| {
                    o.owner.len()
                        + o.sha256.capacity()
                        + o.kind.capacity()
                        + o.php_version.capacity()
                })
                .sum::<usize>()
            + serde_json::to_vec(&self.identity).map_or(0, |v| v.len())
            + self.options.classification_fingerprint.capacity()
            + self.id.capacity()
    }
}
struct Patch {
    id: String,
    base: Arc<Generation>,
    identity: Identity,
    scope: Vec<Owner>,
    changed: HashMap<String, Record>,
    changed_bytes: usize,
    pool: HashSet<Arc<str>>,
    pool_bytes: usize,
    static_bytes: usize,
    canonical_bytes: usize,
    canonical_files: BTreeMap<String, String>,
}
struct Validation {
    generation: Arc<Generation>,
    lease: String,
    base: Option<String>,
}
pub struct Store {
    validation: Option<Validation>,
    patch: Option<Patch>,
    pub limits: Limits,
    pub current: Option<Arc<Generation>>,
    pub pending: Option<Generation>,
    leases: BTreeMap<String, Arc<Generation>>,
    pub peak_owned: usize,
    pub cache_status: String,
}
impl Store {
    pub fn new(limits: Limits) -> Result<Self> {
        if limits.resident_bytes == 0 || limits.transition_bytes == 0 {
            return Err("admission_required".into());
        }
        Ok(Self {
            limits,
            patch: None,
            validation: None,
            current: None,
            pending: None,
            leases: BTreeMap::new(),
            peak_owned: 0,
            cache_status: "not_attempted".into(),
        })
    }
    pub fn owned(&self) -> usize {
        let mut seen = HashSet::new();
        let mut n = 0;
        for g in self
            .current
            .iter()
            .chain(self.leases.values())
            .chain(self.patch.iter().map(|p| &p.base))
            .chain(self.validation.iter().map(|v| &v.generation))
        {
            if seen.insert(Arc::as_ptr(g)) {
                n += g.owned()
            }
        }
        n + self.patch.as_ref().map_or(0, |p| {
            p.canonical_bytes
                + p.scope.capacity() * size_of::<Owner>()
                + p.static_bytes
                + p.id.capacity()
                + p.changed.capacity() * (size_of::<(String, Record)>() + 8)
                + p.changed_bytes
                + p.pool.capacity() * (size_of::<Arc<str>>() + 8)
                + p.pool_bytes
        }) + self.pending.as_ref().map_or(0, Generation::owned)
            + self.leases.len() * (128 + size_of::<Arc<Generation>>())
    }
    /// Conservative admission estimate, not measured RSS. Covers frame, decode,
    /// temporary key sets, interning growth, indexes and one response buffer.
    pub fn frame_reservation(len: usize) -> Result<usize> {
        if len > FRAME_BYTES {
            return Err("frame_limit".into());
        }
        len.checked_mul(64)
            .and_then(|v| v.checked_add(FRAME_BYTES + 1024 * 1024))
            .ok_or("budget_overflow".into())
    }
    pub fn admit_frame(&self, len: usize) -> Result<()> {
        let scratch = Self::frame_reservation(len)?;
        if self
            .owned()
            .checked_add(scratch)
            .is_none_or(|v| v > self.limits.transition_bytes)
        {
            Err("transition_budget".into())
        } else {
            Ok(())
        }
    }
    fn check(&mut self) -> Result<()> {
        let n = self.owned();
        self.peak_owned = self.peak_owned.max(n);
        if n > self.limits.transition_bytes {
            Err("transition_budget".into())
        } else {
            Ok(())
        }
    }
    /// One bounded cache operation. Encoded bytes are the current manifest or
    /// ONE actual segment, never the sum of all serialized records on disk.
    pub fn admit_cache_step(
        &self,
        metadata: usize,
        builder: Option<&Generation>,
        encoded_bytes: usize,
        growth: usize,
    ) -> Result<usize> {
        let scratch = encoded_bytes
            .checked_mul(64)
            .and_then(|n| n.checked_add(FRAME_BYTES + 1024 * 1024))
            .ok_or("budget_overflow")?;
        let required = self
            .owned()
            .checked_add(metadata)
            .and_then(|n| n.checked_add(builder.map_or(0, Generation::owned)))
            .and_then(|n| n.checked_add(growth))
            .and_then(|n| n.checked_add(scratch))
            .ok_or("budget_overflow")?;
        if required > self.limits.transition_bytes {
            return Err("transition_budget".into());
        }
        Ok(required)
    }
    pub fn admit_cache_write(&self, generation: &Generation) -> Result<()> {
        // A segment descriptor has a bounded filename, ordinal, byte count and
        // 64-byte hash. Count the actual variable header/scope without building it.
        let mut bound = 2048usize;
        for size in [
            super::protocol::encoded_size(&generation.identity)?,
            super::protocol::encoded_size(&generation.options)?,
            super::protocol::encoded_size(&generation.scope)?,
        ] {
            bound = bound.checked_add(size).ok_or("budget_overflow")?;
        }
        bound = bound
            .checked_add(
                generation
                    .records
                    .len()
                    .checked_mul(256)
                    .ok_or("budget_overflow")?,
            )
            .ok_or("budget_overflow")?;
        if let Some(plan) = super::snapshot::basic_plan(generation, self.limits.transition_bytes)? {
            bound = bound
                .checked_add(plan.segments.checked_mul(256).ok_or("budget_overflow")?)
                .ok_or("budget_overflow")?;
        }
        bound = bound
            .checked_add(
                generation
                    .canonical_files
                    .len()
                    .div_ceil(32)
                    .checked_mul(256)
                    .ok_or("budget_overflow")?,
            )
            .ok_or("budget_overflow")?;
        let tracked = self
            .current
            .iter()
            .chain(self.leases.values())
            .any(|g| std::ptr::eq(g.as_ref(), generation));
        self.admit_cache_step(0, if tracked { None } else { Some(generation) }, bound, 0)?;
        Ok(())
    }
    pub fn begin(
        &mut self,
        identity: Identity,
        options: Options,
        scope: Vec<Owner>,
    ) -> Result<String> {
        if self.pending.is_some() || self.patch.is_some() || self.validation.is_some() {
            return Err("writer_busy".into());
        }
        let g = Generation::new(identity, options, scope)?;
        let id = g.id.clone();
        self.pending = Some(g);
        if let Err(e) = self.check() {
            self.pending = None;
            return Err(e);
        }
        Ok(id)
    }
    pub fn append(&mut self, r: Record) -> Result<()> {
        let result = self.pending.as_mut().ok_or("no_ingest")?.append(r);
        if let Err(e) = result {
            self.pending = None;
            return Err(e);
        }
        if let Err(e) = self.check() {
            self.pending = None;
            return Err(e);
        }
        Ok(())
    }
    pub fn commit(&mut self) -> Result<String> {
        let p = self.pending.as_ref().ok_or("no_ingest")?;
        if p.records.len() != p.scope.len() {
            return Err("coverage_incomplete".into());
        }
        let mut seen = HashSet::new();
        let leased_bytes = self
            .leases
            .values()
            .filter(|g| seen.insert(Arc::as_ptr(g)))
            .map(|g| g.owned())
            .sum::<usize>();
        if p.owned()
            .checked_add(leased_bytes)
            .is_none_or(|n| n > self.limits.resident_bytes)
        {
            return Err("resident_budget".into());
        }
        self.check()?;
        let g = Arc::new(self.pending.take().unwrap());
        let id = g.id.clone();
        self.current = Some(g);
        Ok(id)
    }
    /// Repack into a separate intern pool. R03 owns dependency overlays/patches;
    /// this operation changes no public record or logical ordinal.
    pub fn compact(&mut self, generation: &str, identity: &Identity) -> Result<String> {
        let old = self
            .current
            .as_ref()
            .filter(|g| g.id == generation && g.identity == *identity)
            .ok_or("stale_snapshot")?
            .clone();
        // Interned long repeated strings can expand during detached cloning.
        // Count their full encoded occurrences before any clone, not old capacity.
        let max_record = old.records.iter().try_fold(0usize, |largest, r| {
            super::protocol::encoded_size(r).map(|n| largest.max(n))
        })?;
        let reserve = max_record
            .checked_mul(64)
            .and_then(|n| {
                old.owned()
                    .checked_mul(2)
                    .and_then(|model| n.checked_add(model))
            })
            .and_then(|n| n.checked_add(FRAME_BYTES + 1024 * 1024))
            .ok_or("budget_overflow")?;
        if self
            .owned()
            .checked_add(reserve)
            .is_none_or(|n| n > self.limits.transition_bytes)
        {
            return Err("transition_budget".into());
        }
        self.begin(old.identity.clone(), old.options.clone(), old.scope.clone())?;
        for r in &old.records {
            let mut r = r.clone();
            r.owner = Arc::from(r.owner.as_ref());
            r.entry.detach_strings();
            if let Err(e) = self.append(r) {
                self.abort();
                return Err(e);
            }
        }
        if let Some(next) = self.pending.as_mut() {
            for (path, id) in &old.canonical_files {
                next.put_canonical(path.clone(), id.clone())?;
            }
            if let Some(mut product) = old.basic.clone() {
                next.intern_product(&mut product);
                next.basic = Some(product);
            }
        }
        match self.commit() {
            Ok(id) => Ok(id),
            Err(e) => {
                self.abort();
                Err(e)
            }
        }
    }
    pub fn abort(&mut self) {
        self.pending = None;
    }
    pub fn pin(&mut self, id: &str, identity: &Identity) -> Result<String> {
        if self.leases.len() >= 64 {
            return Err("lease_limit".into());
        }
        let g = self
            .current
            .as_ref()
            .filter(|g| g.id == id && g.identity == *identity)
            .ok_or("stale_snapshot")?
            .clone();
        let token = crate::session()?;
        self.leases.insert(token.clone(), g);
        if let Err(e) = self.check() {
            self.leases.remove(&token);
            return Err(e);
        }
        Ok(token)
    }
    pub fn release(&mut self, lease: &str) -> Result<()> {
        if self.validation.as_ref().is_some_and(|v| v.lease == lease) {
            self.abort_validation();
            return Ok(());
        }
        self.leases.remove(lease).ok_or("invalid_lease")?;
        Ok(())
    }
    pub fn leased(
        &self,
        lease: &str,
        generation: &str,
        identity: &Identity,
    ) -> Result<&Generation> {
        if self.validation.as_ref().is_some_and(|v| v.lease == lease) {
            return Err("canonical_validation_required".into());
        }
        let g = self
            .leases
            .get(lease)
            .filter(|g| g.id == generation && g.identity == *identity)
            .ok_or("invalid_lease")?;
        Ok(g)
    }

    pub fn canonical_generation(
        &self,
        lease: &str,
        generation: &str,
        identity: &Identity,
    ) -> Result<&Generation> {
        self.leases
            .get(lease)
            .filter(|g| g.id == generation && g.identity == *identity)
            .map(Arc::as_ref)
            .ok_or("invalid_lease".into())
    }
    pub fn prepare_restore(&mut self, g: Generation) -> Result<(String, String)> {
        if self.pending.is_some() || self.patch.is_some() || self.validation.is_some() {
            return Err("writer_busy".into());
        }
        if self.leases.len() >= 64 {
            return Err("lease_limit".into());
        }
        let mut reuse = None;
        for old in self.current.iter().chain(self.leases.values()) {
            if old.id == g.id {
                if !old.equivalent(&g) {
                    return Err("generation_conflict".into());
                }
                reuse = Some(old.clone());
                break;
            }
        }
        if self
            .owned()
            .checked_add(g.owned())
            .is_none_or(|v| v > self.limits.transition_bytes)
        {
            return Err("restore_budget".into());
        }
        let generation = reuse.unwrap_or_else(|| Arc::new(g));
        let id = generation.id.clone();
        let lease = crate::session()?;
        self.validation = Some(Validation {
            generation: generation.clone(),
            lease: lease.clone(),
            base: self.current.as_ref().map(|g| g.id.clone()),
        });
        self.leases.insert(lease.clone(), generation);
        if let Err(e) = self.check() {
            self.abort_validation();
            return Err(e);
        }
        Ok((id, lease))
    }
    pub fn abort_validation(&mut self) {
        if let Some(v) = self.validation.take() {
            self.leases.remove(&v.lease);
        }
    }
    pub fn reject_restore(
        &mut self,
        generation: &str,
        identity: &Identity,
        lease: &str,
    ) -> Result<()> {
        let v = self
            .validation
            .as_ref()
            .filter(|v| {
                v.lease == lease
                    && v.generation.id == generation
                    && v.generation.identity == *identity
            })
            .ok_or("invalid_lease")?;
        let _ = v;
        self.abort_validation();
        self.cache_status = "error".into();
        Ok(())
    }
    pub fn accept_restore(
        &mut self,
        generation: &str,
        identity: &Identity,
        lease: &str,
    ) -> Result<String> {
        let result = (|| {
            let v = self
                .validation
                .as_ref()
                .filter(|v| {
                    v.lease == lease
                        && v.generation.id == generation
                        && v.generation.identity == *identity
                })
                .ok_or("invalid_lease")?;
            if self.current.as_ref().map(|g| &g.id) != v.base.as_ref() {
                return Err("stale_snapshot".into());
            }
            let mut seen = HashSet::new();
            seen.insert(Arc::as_ptr(&v.generation));
            let mut resident = v.generation.owned();
            for g in self.leases.values() {
                if seen.insert(Arc::as_ptr(g)) {
                    resident = resident.checked_add(g.owned()).ok_or("budget_overflow")?;
                }
            }
            if resident > self.limits.resident_bytes || self.owned() > self.limits.transition_bytes
            {
                return Err("restore_budget".into());
            }
            Ok(())
        })();
        if let Err(e) = result {
            self.abort_validation();
            return Err(e);
        }
        let v = self.validation.take().unwrap();
        self.leases.remove(&v.lease);
        self.current = Some(v.generation);
        self.cache_status = "read".into();
        Ok(generation.into())
    }
    pub fn install(&mut self, g: Generation) -> Result<String> {
        if self.pending.is_some() || self.patch.is_some() || self.validation.is_some() {
            return Err("writer_busy".into());
        }
        if let Some(existing) = self
            .current
            .iter()
            .chain(self.leases.values())
            .find(|old| old.id == g.id)
        {
            if !existing.equivalent(&g) {
                return Err("generation_conflict".into());
            }
            let existing = existing.clone();
            let id = existing.id.clone();
            self.current = Some(existing);
            return Ok(id);
        }
        let n = g.owned();
        let mut seen = HashSet::new();
        let leased_bytes = self
            .leases
            .values()
            .filter(|lease| lease.id != g.id && seen.insert(Arc::as_ptr(lease)))
            .map(|g| g.owned())
            .sum::<usize>();
        if n.checked_add(leased_bytes)
            .is_none_or(|n| n > self.limits.resident_bytes)
            || self
                .owned()
                .checked_add(n)
                .is_none_or(|v| v > self.limits.transition_bytes)
        {
            return Err("restore_budget".into());
        }
        let id = g.id.clone();
        self.current = Some(Arc::new(g));
        self.peak_owned = self.peak_owned.max(self.owned());
        Ok(id)
    }

    pub fn patch_begin(
        &mut self,
        generation: &str,
        identity: &Identity,
        lease: &str,
        target_identity: Identity,
        scope: Vec<Owner>,
    ) -> Result<String> {
        if self.pending.is_some() || self.patch.is_some() || self.validation.is_some() {
            return Err("writer_busy".into());
        }
        let base = self
            .leases
            .get(lease)
            .filter(|g| g.id == generation && g.identity == *identity)
            .ok_or("invalid_lease")?
            .clone();
        if self.current.as_ref().is_none_or(|g| g.id != generation) || base.basic.is_none() {
            return Err("stale_snapshot".into());
        }
        target_identity.validate()?;
        let mut compatibility = target_identity.clone();
        compatibility.capture = identity.capture.clone();
        if compatibility != *identity {
            return Err("patch_identity".into());
        }
        self.admit_basic_work(&base, 16)?;
        let checked = Generation::new(target_identity.clone(), base.options.clone(), scope)?;
        let id = checked.id;
        let static_bytes = checked
            .scope
            .iter()
            .map(|o| {
                o.owner.len() + o.sha256.capacity() + o.kind.capacity() + o.php_version.capacity()
            })
            .sum::<usize>()
            + serde_json::to_vec(&target_identity).map_or(0, |v| v.len());
        self.patch = Some(Patch {
            id: id.clone(),
            base,
            identity: target_identity,
            scope: checked.scope,
            changed: HashMap::new(),
            changed_bytes: 0,
            pool: HashSet::new(),
            pool_bytes: 0,
            canonical_bytes: 0,
            static_bytes,
            canonical_files: BTreeMap::new(),
        });
        self.check()?;
        Ok(id)
    }
    pub fn enrich_files(&mut self, ingest: &str, paths: Vec<(String, String)>) -> Result<()> {
        let result = (|| {
            if paths.is_empty() || paths.len() > 32 {
                return Err("canonical_files_limit".into());
            }
            for (path, id) in paths {
                if path.is_empty() || !super::compose::enrichment::id_shape(&id) {
                    return Err("canonical_files_shape".into());
                }
                if let Some(g) = self.pending.as_mut().filter(|g| g.id == ingest) {
                    g.put_canonical(path, id)?;
                } else if let Some(p) = self.patch.as_mut().filter(|p| p.id == ingest) {
                    if p.canonical_files.get(&path).is_some_and(|old| old != &id) {
                        return Err("canonical_files_conflict".into());
                    }
                    if !p.canonical_files.contains_key(&path) {
                        p.canonical_bytes += path.capacity() + id.capacity() + 96;
                        p.canonical_files.insert(path, id);
                    }
                } else {
                    return Err("invalid_ingest".into());
                }
            }
            Ok(())
        })();
        if result.is_err() {
            if self.pending.as_ref().is_some_and(|g| g.id == ingest) {
                self.pending = None
            }
            if self.patch.as_ref().is_some_and(|p| p.id == ingest) {
                self.patch = None
            }
            return result;
        }
        if let Err(e) = self.check() {
            self.pending = None;
            self.patch = None;
            return Err(e);
        }
        Ok(())
    }
    pub fn patch_append(&mut self, ingest: &str, mut record: Record) -> Result<()> {
        let result = (|| {
            let p = self
                .patch
                .as_mut()
                .filter(|p| p.id == ingest)
                .ok_or("invalid_ingest")?;
            let target = p.scope.get(record.ordinal).ok_or("coverage_mismatch")?;
            if target.owner != record.owner
                || target.sha256 != record.sha256
                || target.kind != record.kind
                || target.php_version != record.php_version
                || p.base.options != record.options
                || p.changed.contains_key(record.owner.as_ref())
            {
                return Err("coverage_mismatch".into());
            }
            record.validate()?;
            p.pool_bytes += intern_counted(&mut record.owner, &mut p.pool);
            p.pool_bytes += record.entry.intern_counted(&mut p.pool);
            let key = record.owner.to_string();
            p.changed_bytes += key.capacity() + record.owned();
            p.changed.insert(key, record);
            Ok(())
        })();
        if result.is_err() {
            self.patch = None;
            return result;
        }
        if let Err(e) = self.check() {
            self.patch = None;
            return Err(e);
        }
        Ok(())
    }
    pub fn patch_abort(&mut self, ingest: &str) -> Result<()> {
        if self.patch.as_ref().is_none_or(|p| p.id != ingest) {
            return Err("invalid_ingest".into());
        }
        self.patch = None;
        Ok(())
    }
    pub fn patch_commit(&mut self, ingest: &str) -> Result<String> {
        let phase = super::measure::Span::new("patch", Some(self.owned()));
        let p = self
            .patch
            .as_ref()
            .filter(|p| p.id == ingest)
            .ok_or("invalid_ingest")?;
        if self.current.as_ref().is_none_or(|g| g.id != p.base.id) {
            return Err("stale_snapshot".into());
        }
        self.admit_basic_work(&p.base, 16)?;
        let retained = self.owned();
        let p = self.patch.take().unwrap();
        let mut next = Generation::new(p.identity, p.base.options.clone(), p.scope)?;
        for (ordinal, owner) in next.scope.clone().iter().enumerate() {
            let mut r = if let Some(changed) = p.changed.get(owner.owner.as_ref()) {
                changed.clone()
            } else {
                let idx = p
                    .base
                    .owners
                    .get(&owner.owner)
                    .ok_or("coverage_incomplete")?;
                p.base.records[*idx].clone()
            };
            r.ordinal = ordinal;
            if r.sha256 != owner.sha256
                || r.kind != owner.kind
                || r.php_version != owner.php_version
            {
                return Err("coverage_incomplete".into());
            }
            next.append(r)?;
        }
        for path in super::compose::enrichment::required(&next)? {
            let id = p
                .canonical_files
                .get(&path)
                .or_else(|| p.base.canonical_files.get(&path))
                .ok_or("canonical_files_incomplete")?;
            if p.canonical_files.get(&path).is_some_and(|new| {
                p.base
                    .canonical_files
                    .get(&path)
                    .is_some_and(|old| old != new)
            }) {
                return Err("canonical_files_conflict".into());
            }
            next.put_canonical(path, id.clone())?;
        }
        let work_limit = self
            .limits
            .transition_bytes
            .checked_sub(retained)
            .and_then(|v| v.checked_sub(next.owned()))
            .and_then(|v| v.checked_sub(super::FRAME_BYTES + 1024 * 1024))
            .ok_or("transition_budget")?;
        let product = super::dependency::compose(&mut next, p.base.basic.as_ref(), work_limit)?;
        next.basic = Some(product);
        let result = self.install(next);
        phase.finish(Some(self.owned()));
        result
    }
    pub fn lease_count(&self) -> usize {
        self.leases.len()
    }
    pub fn basic_work_reservation(g: &Generation, multiplier: usize) -> Result<usize> {
        g.owned()
            .checked_mul(multiplier)
            .and_then(|v| v.checked_add(super::FRAME_BYTES + 1024 * 1024))
            .ok_or("budget_overflow".into())
    }
    pub fn admit_basic_work(&self, g: &Generation, multiplier: usize) -> Result<()> {
        let scratch = Self::basic_work_reservation(g, multiplier)?;
        if self
            .owned()
            .checked_add(scratch)
            .is_none_or(|v| v > self.limits.transition_bytes)
        {
            Err("transition_budget".into())
        } else {
            Ok(())
        }
    }
    /// Composition is admitted before cloning any source record or allocating indexes.
    pub fn compose_basic(
        &mut self,
        generation: &str,
        identity: &Identity,
        lease: &str,
    ) -> Result<String> {
        if self.pending.is_some() || self.patch.is_some() || self.validation.is_some() {
            return Err("writer_busy".into());
        }
        let old = self
            .leases
            .get(lease)
            .filter(|g| g.id == generation && g.identity == *identity)
            .ok_or("invalid_lease")?
            .clone();
        let reserve = old
            .owned()
            .checked_mul(16)
            .and_then(|v| v.checked_add(super::FRAME_BYTES + 1024 * 1024))
            .ok_or("budget_overflow")?;
        if self
            .owned()
            .checked_add(reserve)
            .is_none_or(|n| n > self.limits.transition_bytes)
        {
            return Err("transition_budget".into());
        }
        let mut next =
            Generation::new(old.identity.clone(), old.options.clone(), old.scope.clone())?;
        for record in &old.records {
            next.append(record.clone())?;
        }
        for (path, id) in &old.canonical_files {
            next.put_canonical(path.clone(), id.clone())?;
        }
        let work_limit = self
            .limits
            .transition_bytes
            .checked_sub(self.owned())
            .and_then(|v| v.checked_sub(next.owned()))
            .and_then(|v| v.checked_sub(super::FRAME_BYTES + 1024 * 1024))
            .ok_or("transition_budget")?;
        let product = super::dependency::compose(&mut next, None, work_limit)?;
        next.basic = Some(product);
        self.install(next)
    }
    pub fn restoring(
        identity: Identity,
        options: Options,
        scope: Vec<Owner>,
        id: String,
    ) -> Result<Generation> {
        let mut g = Generation::new(identity, options, scope)?;
        g.id = id;
        Ok(g)
    }
}
