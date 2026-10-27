use super::{
    CONTROL_BYTES, RECORD_BYTES, Result, SCHEMA,
    model::{Options, Record},
    store::{Generation, Identity, Owner, Store},
};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs::{self, File, OpenOptions},
    io::{BufWriter, Read, Write},
    os::unix::{
        fs::{OpenOptionsExt, PermissionsExt},
        io::AsRawFd,
    },
    path::{Component, Path},
};
pub fn hash(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}
mod basic;
mod enrichment;
pub(crate) fn basic_plan(g: &Generation, limit: usize) -> Result<Option<basic::Plan>> {
    g.basic
        .as_ref()
        .map(|p| basic::plan(g, p, limit))
        .transpose()
}
const SEGMENT_BYTES: usize = RECORD_BYTES + 1024;
#[derive(Serialize)]
struct SnapshotRecordRef<'a> {
    schema: &'static str,
    generation: &'a str,
    record: &'a Record,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct SnapshotRecord {
    schema: String,
    generation: String,
    record: Record,
}
#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Segment {
    file: String,
    bytes: usize,
    sha256: String,
    ordinal: usize,
}
#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Manifest {
    schema: String,
    codec: String,
    generation: String,
    identity: Identity,
    options: Options,
    scope: Vec<Owner>,
    segments: Vec<Segment>,
    records: usize,
    decoded_bytes: usize,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    basic: Option<Vec<Segment>>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    canonical_files: Option<Vec<Segment>>,
}
#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Pointer {
    schema: String,
    generation: String,
    manifest_sha256: String,
}
fn io(_: std::io::Error) -> String {
    "cache_io".into()
}
pub fn boundary(root: &Path) -> Result<()> {
    if !root.is_absolute() {
        return Err("cache_path".into());
    }
    let mut cursor = std::path::PathBuf::new();
    for c in root.components() {
        if matches!(c, Component::ParentDir | Component::CurDir) {
            return Err("cache_path".into());
        }
        cursor.push(c);
        let m = fs::symlink_metadata(&cursor).map_err(io)?;
        if m.file_type().is_symlink() {
            return Err("cache_symlink".into());
        }
    }
    let m = fs::symlink_metadata(root).map_err(io)?;
    if !m.is_dir() || m.permissions().mode() & 0o777 != 0o700 {
        return Err("cache_permissions".into());
    }
    Ok(())
}
fn open_read(path: &Path, max: usize) -> Result<File> {
    let f = OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)
        .map_err(io)?;
    let m = f.metadata().map_err(io)?;
    if !m.is_file() || m.permissions().mode() & 0o777 != 0o600 || m.len() > max as u64 {
        return Err("cache_file_bounds".into());
    }
    Ok(f)
}
/// Stat, admission and bounded read use one file descriptor. An attacker cannot
/// swap the path between admission and read to increase the allocated length.
fn read_admitted(
    path: &Path,
    max: usize,
    admit: impl FnOnce(usize) -> Result<()>,
) -> Result<Vec<u8>> {
    let phase = super::measure::Span::new("cache_read", Some(0));
    let mut f = open_read(path, max)?;
    let size = usize::try_from(f.metadata().map_err(io)?.len()).map_err(|_| "cache_file_bounds")?;
    if size > max {
        return Err("cache_file_bounds".into());
    }
    admit(size)?;
    let mut bytes = Vec::new();
    bytes
        .try_reserve_exact(size)
        .map_err(|_| "cache_allocation_refused")?;
    bytes.resize(size, 0);
    f.read_exact(&mut bytes).map_err(|_| "cache_file_changed")?;
    let mut extra = [0u8; 1];
    if f.read(&mut extra).map_err(io)? != 0 {
        return Err("cache_file_changed".into());
    }
    phase.finish(Some(bytes.capacity()));
    Ok(bytes)
}
fn segments_owned(segments: &Vec<Segment>) -> Result<usize> {
    segments.iter().try_fold(
        segments
            .capacity()
            .checked_mul(std::mem::size_of::<Segment>())
            .ok_or("cache_overflow")?,
        |n, s| {
            n.checked_add(s.file.capacity())
                .and_then(|n| n.checked_add(s.sha256.capacity()))
                .ok_or("cache_overflow".into())
        },
    )
}
fn manifest_owned(m: &Manifest) -> Result<usize> {
    let mut n = std::mem::size_of::<Manifest>()
        .checked_add(segments_owned(&m.segments)?)
        .and_then(|n| {
            m.scope
                .capacity()
                .checked_mul(std::mem::size_of::<Owner>())
                .and_then(|scope| n.checked_add(scope))
        })
        .ok_or("cache_overflow")?;
    if let Some(segments) = &m.canonical_files {
        n = n
            .checked_add(segments_owned(segments)?)
            .ok_or("cache_overflow")?;
    }
    if let Some(segments) = &m.basic {
        n = n
            .checked_add(segments_owned(segments)?)
            .ok_or("cache_overflow")?;
    }
    for value in [
        &m.schema,
        &m.codec,
        &m.generation,
        &m.identity.repository,
        &m.identity.profile,
        &m.identity.product,
        &m.identity.parser,
        &m.identity.tool,
        &m.identity.configuration,
        &m.identity.capture,
        &m.options.classification_fingerprint,
    ] {
        n = n.checked_add(value.capacity()).ok_or("cache_overflow")?;
    }
    for owner in &m.scope {
        n = n
            .checked_add(owner.owner.len())
            .and_then(|n| n.checked_add(2 * std::mem::size_of::<usize>()))
            .and_then(|n| n.checked_add(owner.sha256.capacity()))
            .and_then(|n| n.checked_add(owner.kind.capacity()))
            .and_then(|n| n.checked_add(owner.php_version.capacity()))
            .ok_or("cache_overflow")?;
    }
    Ok(n)
}
fn write(path: &Path, bytes: &[u8]) -> Result<()> {
    let mut f = OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)
        .map_err(io)?;
    f.write_all(bytes).map_err(io)?;
    f.sync_all().map_err(io)
}
fn sync_dir(path: &Path) -> Result<()> {
    File::open(path).map_err(io)?.sync_all().map_err(io)
}
fn decode<T: serde::de::DeserializeOwned>(bytes: &[u8]) -> Result<T> {
    serde_json::from_slice(bytes).map_err(|_| "cache_shape".into())
}
struct WriterLock(File);
impl WriterLock {
    fn acquire(root: &Path) -> Result<Self> {
        let f = OpenOptions::new()
            .read(true)
            .write(true)
            .create(true)
            .truncate(false)
            .mode(0o600)
            .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
            .open(root.join("writer.lock"))
            .map_err(io)?;
        let m = f.metadata().map_err(io)?;
        if !m.is_file() || m.permissions().mode() & 0o777 != 0o600 {
            return Err("cache_lock_shape".into());
        }
        if unsafe { libc::flock(f.as_raw_fd(), libc::LOCK_EX | libc::LOCK_NB) } != 0 {
            return Err("cache_writer_busy".into());
        }
        Ok(Self(f))
    }
}
impl Drop for WriterLock {
    fn drop(&mut self) {
        unsafe {
            libc::flock(self.0.as_raw_fd(), libc::LOCK_UN);
        }
    }
}
pub fn write_snapshot(root: &Path, g: &Generation, crash: Option<&str>) -> Result<()> {
    if g.basic.is_some() {
        return Err("cache_admission_required".into());
    }
    write_guarded(root, g, crash, None)
}
pub fn write_snapshot_guarded(root: &Path, g: &Generation, store: &Store) -> Result<()> {
    write_guarded(root, g, None, basic_plan(g, store.limits.transition_bytes)?)
}
fn write_guarded(
    root: &Path,
    g: &Generation,
    crash: Option<&str>,
    plan: Option<basic::Plan>,
) -> Result<()> {
    let _phase = super::measure::Span::unchanged("cache_write", Some(g.owned()));
    boundary(root)?;
    let _lock = WriterLock::acquire(root)?;
    // A nonce per write prevents stale writers and retries from replacing segments.
    let nonce = crate::session()?;
    let name = format!("g-{}-{nonce}", g.id);
    let dir = root.join(&name);
    fs::create_dir(&dir).map_err(io)?;
    fs::set_permissions(&dir, fs::Permissions::from_mode(0o700)).map_err(io)?;
    let mut segments = Vec::new();
    for r in &g.records {
        let file = format!("record-{:08}.json", r.ordinal);
        let path = dir.join(&file);
        let mut sink = HashWriter {
            writer: BufWriter::with_capacity(
                8192,
                OpenOptions::new()
                    .write(true)
                    .create_new(true)
                    .mode(0o600)
                    .open(path)
                    .map_err(io)?,
            ),
            hasher: Sha256::new(),
            bytes: 0,
        };
        serde_json::to_writer(
            &mut sink,
            &SnapshotRecordRef {
                schema: SCHEMA,
                generation: &g.id,
                record: r,
            },
        )
        .map_err(|_| "cache_record_write")?;
        sink.flush().map_err(io)?;
        sink.writer.get_ref().sync_all().map_err(io)?;
        segments.push(Segment {
            file,
            bytes: sink.bytes,
            sha256: format!("{:x}", sink.hasher.finalize()),
            ordinal: r.ordinal,
        });
    }
    let canonical_files = if g.canonical_files.is_empty() {
        None
    } else {
        Some(enrichment::write_files(&dir, g)?)
    };
    let basic = g
        .basic
        .as_ref()
        .map(|p| basic::write_product(&dir, g, p, plan.ok_or("cache_admission_required")?))
        .transpose()?;
    fault(crash, "segments")?;
    let m = Manifest {
        schema: SCHEMA.into(),
        codec: "typed-json-v1".into(),
        generation: g.id.clone(),
        identity: g.identity.clone(),
        options: g.options.clone(),
        scope: g.scope.clone(),
        decoded_bytes: segments
            .iter()
            .map(|s| s.bytes)
            .sum::<usize>()
            .checked_mul(64)
            .ok_or("cache_overflow")?,
        segments,
        records: g.records.len(),
        basic,
        canonical_files,
    };
    let manifest_size = super::protocol::encoded_size(&m)?;
    if manifest_size > CONTROL_BYTES {
        return Err("cache_manifest_limit".into());
    }
    let mut body = Vec::new();
    body.try_reserve_exact(manifest_size)
        .map_err(|_| "cache_allocation_refused")?;
    serde_json::to_writer(&mut body, &m).map_err(|_| "cache_encode")?;
    write(&dir.join("manifest.json"), &body)?;
    sync_dir(&dir)?;
    fault(crash, "manifest")?;
    let p = Pointer {
        schema: SCHEMA.into(),
        generation: name,
        manifest_sha256: hash(&body),
    };
    let body = serde_json::to_vec(&p).map_err(|_| "cache_encode")?;
    let temp = root.join(format!("CURRENT-{nonce}"));
    write(&temp, &body)?;
    fault(crash, "pointer")?;
    fs::rename(temp, root.join("CURRENT")).map_err(io)?;
    sync_dir(root)?;
    fault(crash, "published")?;
    Ok(())
}
fn fault(point: Option<&str>, at: &str) -> Result<()> {
    if point == Some(at) {
        #[cfg(test)]
        if std::env::var("AK_R02_CRASH_CHILD").as_deref() == Ok("1") {
            std::process::exit(86)
        }
        return Err("injected_cache_crash".into());
    }
    Ok(())
}
struct HashWriter<W> {
    writer: W,
    hasher: Sha256,
    bytes: usize,
}
impl<W: Write> Write for HashWriter<W> {
    fn write(&mut self, b: &[u8]) -> std::io::Result<usize> {
        if self
            .bytes
            .checked_add(b.len())
            .is_none_or(|n| n > SEGMENT_BYTES)
        {
            return Err(std::io::Error::other("record_limit"));
        }
        let n = self.writer.write(b)?;
        self.hasher.update(&b[..n]);
        self.bytes += n;
        Ok(n)
    }
    fn flush(&mut self) -> std::io::Result<()> {
        self.writer.flush()
    }
}
pub fn restore(root: &Path, identity: &Identity, store: &Store) -> Result<Generation> {
    let phase = super::measure::Span::new("restore", Some(store.owned()));
    boundary(root)?;
    let pointer_bytes = read_admitted(&root.join("CURRENT"), 4096, |size| {
        store.admit_cache_step(0, None, size, 0).map(|_| ())
    })?;
    let p: Pointer = decode(&pointer_bytes)?;
    drop(pointer_bytes);
    if p.schema != SCHEMA
        || p.generation.len() != 131
        || !p.generation.starts_with("g-")
        || !p.generation[2..]
            .bytes()
            .all(|b| b == b'-' || b.is_ascii_hexdigit())
    {
        return Err("cache_pointer".into());
    }
    let dir = root.join(&p.generation);
    boundary(&dir)?;
    let bytes = read_admitted(&dir.join("manifest.json"), CONTROL_BYTES, |size| {
        store.admit_cache_step(0, None, size, 0).map(|_| ())
    })?;
    if hash(&bytes) != p.manifest_sha256 {
        return Err("cache_manifest_hash".into());
    }
    let manifest_size = bytes.len();
    let m: Manifest = decode(&bytes)?;
    // The raw manifest is not kept beside the builder or subsequent segment.
    drop(bytes);
    if !super::model::hash_shape(&m.generation)
        || p.generation.as_bytes()[66] != b'-'
        || !super::model::hash_shape(&p.generation[67..])
    {
        return Err("cache_generation_shape".into());
    }
    if m.schema != SCHEMA
        || m.identity != *identity
        || m.codec != "typed-json-v1"
        || p.generation[2..66] != m.generation
        || m.records != m.segments.len()
        || m.records != m.scope.len()
    {
        return Err("cache_identity".into());
    }
    let mut encoded = 0usize;
    for (i, segment) in m.segments.iter().enumerate() {
        if segment.ordinal != i
            || segment.file != format!("record-{i:08}.json")
            || segment.bytes == 0
            || segment.bytes > SEGMENT_BYTES
        {
            return Err("cache_segment_shape".into());
        }
        encoded = encoded.checked_add(segment.bytes).ok_or("cache_overflow")?;
    }
    // This deterministic bound is integrity/validation metadata only. It is NOT
    // an admission reservation for all serialized transients at the same time.
    let decoded_bound = encoded.checked_mul(64).ok_or("cache_overflow")?;
    if m.decoded_bytes != decoded_bound {
        return Err("cache_decoded_bytes".into());
    }
    let metadata = manifest_owned(&m)?;
    let validation_growth = m
        .scope
        .len()
        .checked_mul(64)
        .and_then(|n| n.checked_add(4096))
        .ok_or("cache_overflow")?;
    store.admit_cache_step(metadata, None, 0, validation_growth)?;
    let mut retained = segments_owned(&m.segments)?
        .checked_add(
            m.canonical_files
                .as_ref()
                .map(segments_owned)
                .transpose()?
                .unwrap_or(0),
        )
        .ok_or("cache_overflow")?
        .checked_add(
            m.basic
                .as_ref()
                .map(segments_owned)
                .transpose()?
                .unwrap_or(0),
        )
        .ok_or("cache_overflow")?;
    let mut g = Store::restoring(m.identity, m.options, m.scope, m.generation)?;
    store.admit_cache_step(retained, Some(&g), 0, 0)?;
    for segment in m.segments {
        let growth = g.growth_reserve()?;
        let b = read_admitted(&dir.join(&segment.file), SEGMENT_BYTES, |actual| {
            if actual != segment.bytes {
                return Err("cache_segment_hash".into());
            }
            store
                .admit_cache_step(retained, Some(&g), actual, growth)
                .map(|_| ())
        })?;
        if hash(&b) != segment.sha256 {
            return Err("cache_segment_hash".into());
        }
        let mut decoder = serde_json::Deserializer::from_slice(&b);
        decoder.disable_recursion_limit();
        let r = SnapshotRecord::deserialize(&mut decoder).map_err(|_| "cache_record_shape")?;
        decoder.end().map_err(|_| "cache_record_trailing")?;
        if r.schema != SCHEMA || r.generation != g.id {
            return Err("cache_record_generation".into());
        }
        // The pre-read ledger covers decode, validation, all new entries and
        // possible geometric growth of the existing append-only index buffers.
        g.append(r.record)?;
        drop(b);
        retained = retained
            .checked_sub(segment.file.capacity())
            .and_then(|n| n.checked_sub(segment.sha256.capacity()))
            .ok_or("cache_overflow")?;
        drop(segment);
        store.admit_cache_step(retained, Some(&g), 0, 0)?;
    }
    let total_bound = decoded_bound
        .checked_add(manifest_size.checked_mul(64).ok_or("cache_overflow")?)
        .ok_or("cache_overflow")?;
    if g.owned() > total_bound {
        return Err("cache_decoded_bytes".into());
    }
    if let Some(segments) = m.canonical_files {
        enrichment::restore_files(&dir, &mut g, segments, store, retained)?;
    }
    if m.basic.is_some() {
        crate::graph::compose::enrichment::validate(&g)?;
    }
    if let Some(segments) = m.basic {
        basic::restore_product(&dir, &mut g, segments, store, retained)?;
    }
    phase.finish(Some(g.owned()));
    Ok(g)
}

#[cfg(test)]
mod tests_r2;

#[cfg(test)]
mod tests_r3;
