//! Canonical file IDs are calculated by the bounded PHP adapter, never reimplemented.
use super::super::{Result, store::Generation};
use std::collections::BTreeSet;
pub fn required(g: &Generation) -> Result<BTreeSet<String>> {
    let mut out = BTreeSet::new();
    for r in &g.records {
        for edge in r.entry.catalog.get("relations").vals() {
            let target = edge.get("to").text();
            if target.starts_with("include:") {
                for path in edge.meta("candidate_paths").vals() {
                    out.insert(path.string()?.to_owned());
                }
            }
            if let Some(path) = target.strip_prefix("file:") {
                out.insert(path.to_owned());
            }
        }
    }
    Ok(out)
}
pub fn id_shape(id: &str) -> bool {
    id.strip_prefix("element:").is_some_and(|v| {
        v.len() == 32
            && v.bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
    })
}
pub fn validate(g: &Generation) -> Result<()> {
    let required = required(g)?;
    if required.len() != g.canonical_files.len() {
        return Err("canonical_files_incomplete".into());
    }
    for path in required {
        if !g.canonical_files.get(&path).is_some_and(|id| id_shape(id)) {
            return Err("canonical_files_incomplete".into());
        }
    }
    Ok(())
}
