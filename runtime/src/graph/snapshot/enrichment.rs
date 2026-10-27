use super::*;
#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Canonicals {
    schema: String,
    generation: String,
    ordinal: usize,
    paths: Vec<(String, String)>,
}
pub(super) fn write_files(dir: &Path, g: &Generation) -> Result<Vec<Segment>> {
    let mut out = vec![];
    let mut pairs = vec![];
    for (path, id) in &g.canonical_files {
        pairs.push((path.clone(), id.clone()));
        if pairs.len() == 32 {
            write_part(dir, g, &mut out, std::mem::take(&mut pairs))?
        }
    }
    if !pairs.is_empty() {
        write_part(dir, g, &mut out, pairs)?
    }
    Ok(out)
}
fn write_part(
    dir: &Path,
    g: &Generation,
    out: &mut Vec<Segment>,
    paths: Vec<(String, String)>,
) -> Result<()> {
    let ordinal = out.len();
    let file = format!("canonical-{ordinal:08}.json");
    let mut sink = HashWriter {
        writer: BufWriter::with_capacity(
            8192,
            OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(dir.join(&file))
                .map_err(io)?,
        ),
        hasher: Sha256::new(),
        bytes: 0,
    };
    serde_json::to_writer(
        &mut sink,
        &Canonicals {
            schema: "native-file-ids-v1".into(),
            generation: g.id.clone(),
            ordinal,
            paths,
        },
    )
    .map_err(|_| "cache_canonical_write")?;
    sink.flush().map_err(io)?;
    sink.writer.get_ref().sync_all().map_err(io)?;
    out.push(Segment {
        file,
        bytes: sink.bytes,
        sha256: format!("{:x}", sink.hasher.finalize()),
        ordinal,
    });
    Ok(())
}
pub(super) fn restore_files(
    dir: &Path,
    g: &mut Generation,
    segments: Vec<Segment>,
    store: &Store,
    retained: usize,
) -> Result<()> {
    let mut previous = None;
    for (i, segment) in segments.into_iter().enumerate() {
        if segment.ordinal != i
            || segment.file != format!("canonical-{i:08}.json")
            || segment.bytes == 0
            || segment.bytes > SEGMENT_BYTES
        {
            return Err("cache_canonical_segment".into());
        }
        let b = read_admitted(&dir.join(&segment.file), SEGMENT_BYTES, |actual| {
            if actual != segment.bytes {
                return Err("cache_segment_hash".into());
            }
            store
                .admit_cache_step(retained, Some(g), actual, g.growth_reserve()?)
                .map(|_| ())
        })?;
        if hash(&b) != segment.sha256 {
            return Err("cache_segment_hash".into());
        }
        let part: Canonicals = serde_json::from_slice(&b).map_err(|_| "cache_canonical_shape")?;
        drop(b);
        if part.schema != "native-file-ids-v1"
            || part.generation != g.id
            || part.ordinal != i
            || part.paths.is_empty()
            || part.paths.len() > 32
        {
            return Err("cache_canonical_shape".into());
        }
        for (path, id) in part.paths {
            if path.is_empty()
                || !crate::graph::compose::enrichment::id_shape(&id)
                || previous.as_ref().is_some_and(|p: &String| p >= &path)
            {
                return Err("cache_canonical_shape".into());
            }
            previous = Some(path.clone());
            if g.canonical_files.contains_key(&path) {
                return Err("cache_canonical_duplicate".into());
            }
            g.put_canonical(path, id)?;
        }
        store.admit_cache_step(retained, Some(g), 0, 0)?;
    }
    Ok(())
}
