use super::{
    CONTROL_BYTES, FRAME_BYTES, PAGE_RECORDS, PROTOCOL, Result,
    model::{Options, Record},
    snapshot,
    store::{Identity, Limits, Owner, Store},
};
use serde::{Deserialize, Serialize};
use serde_json::json;
use sha2::{Digest, Sha256};
use std::{
    io::{self, Read, Write},
    path::Path,
};
fn audit_row(
    owner: &str,
    entry: &super::model::FileEntry,
    impact: bool,
    diagnostics_only: bool,
) -> Result<super::model::Value> {
    use super::{compose::value::*, model::Value};
    let catalog = entry.field("catalog")?;
    let diagnostics = match catalog {
        Value::Null => empty(),
        _ => catalog.field("diagnostics")?.clone(),
    };
    let syntax_error = entry.field("syntax_error")?.clone();
    if !matches!(syntax_error, Value::Null | Value::String(_)) {
        return Err("audit_shape".into());
    }
    let complete = matches!(syntax_error, Value::Null)
        && diagnostics.vals().all(|d| {
            let code = d.get("code").text();
            !["limit", "parse", "changed_inputs"]
                .iter()
                .any(|part| code.contains(part))
        });
    let mut row = map([
        ("owner", s(owner)),
        ("diagnostics", diagnostics),
        ("syntax_error", syntax_error),
        ("checks_complete", b(complete)),
    ]);
    if !diagnostics_only {
        row.put("symbols", entry.field("symbols")?.clone());
        row.put("edges", entry.field("edges")?.clone());
        row.put("test_invocations", entry.field("test_invocations")?.clone());
        if impact {
            row.put("impact", entry.field("impact")?.clone());
        }
    }
    Ok(row)
}

#[cfg(test)]
mod audit_tests {
    use super::audit_row;
    use crate::graph::{
        compose::value::*,
        model::{FileEntry, Value},
    };
    #[test]
    fn per_owner_audit_diagnostics_and_syntax_do_not_use_composed_helpers() {
        let mut entry = FileEntry {
            symbols: empty(),
            edges: empty(),
            test_invocations: empty(),
            catalog: Value::Null,
            syntax_error: Value::Null,
            syntax_error_line: n(1),
            impact: Value::Null,
        };
        let row = audit_row("app/Good.php", &entry, false, false).unwrap();
        assert_eq!(row.get("owner").text(), "app/Good.php");
        assert!(row.get("checks_complete").yes());
        assert!(row.get("diagnostics").vals().next().is_none());
        entry.syntax_error = s("bad source");
        assert!(
            !audit_row("app/Bad.php", &entry, false, false)
                .unwrap()
                .get("checks_complete")
                .yes()
        );
        entry.catalog = map([(
            "diagnostics",
            list([map([
                ("code", s("parse_error")),
                ("message", s("bad source")),
                ("line", n(1)),
                ("subject", Value::Null),
            ])]),
        )]);
        let row = audit_row("app/Bad.php", &entry, true, false).unwrap();
        assert_eq!(
            row.get("diagnostics")
                .vals()
                .next()
                .unwrap()
                .get("code")
                .text(),
            "parse_error"
        );
        assert!(!row.get("checks_complete").yes());
        assert!(matches!(row.get("impact"), Value::Null));
        let diagnostic_only = audit_row("app/Bad.php", &entry, false, true).unwrap();
        assert!(diagnostic_only.find("symbols").is_none());
        entry.syntax_error = n(1);
        assert!(audit_row("app/BadShape.php", &entry, false, false).is_err());
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {
    protocol: String,
    session: String,
    request_id: String,
    operation: Operation,
}
#[derive(Deserialize)]
#[serde(tag = "op", rename_all = "snake_case", deny_unknown_fields)]
enum Operation {
    Begin {
        identity: Identity,
        options: Options,
        scope: Vec<Owner>,
    },
    Append {
        ingest: String,
        record: Record,
    },
    Commit {
        ingest: String,
    },
    Abort {
        ingest: String,
    },
    Pin {
        generation: String,
        identity: Identity,
    },
    Release {
        lease: String,
    },
    ReadBatch {
        generation: String,
        identity: Identity,
        lease: String,
        start: usize,
        limit: usize,
    },
    Snapshot {
        generation: String,
        identity: Identity,
        lease: String,
        directory: String,
    },
    Restore {
        identity: Identity,
        directory: String,
        #[serde(default)]
        validate_canonical: bool,
    },
    AcceptRestore {
        generation: String,
        identity: Identity,
        lease: String,
    },
    RejectRestore {
        generation: String,
        identity: Identity,
        lease: String,
    },
    ScopePage {
        generation: String,
        identity: Identity,
        lease: String,
        start: usize,
        limit: usize,
    },
    CanonicalPage {
        generation: String,
        identity: Identity,
        lease: String,
        start: usize,
        limit: usize,
    },
    Status,
    Summary {
        generation: String,
        identity: Identity,
        lease: String,
    },
    AuditPage {
        generation: String,
        identity: Identity,
        lease: String,
        start: usize,
        limit: usize,
        #[serde(default)]
        impact: bool,
        #[serde(default)]
        diagnostics_only: bool,
        #[serde(default = "response_bytes")]
        max_bytes: usize,
    },
    Limits,
    SetLimits {
        limits: Limits,
    },
    Preflight {
        generation: String,
        identity: Identity,
        lease: String,
        request_bytes: usize,
    },
    Compact {
        generation: String,
        identity: Identity,
    },
    QueryPage {
        #[serde(default)]
        generation: Option<String>,
        #[serde(default)]
        identity: Option<Identity>,
        #[serde(default)]
        lease: Option<String>,
        #[serde(default)]
        query: Option<super::query::Query>,
        #[serde(default)]
        start: usize,
        #[serde(default)]
        limit: usize,
        #[serde(default)]
        diagnostic_start: usize,
        #[serde(default = "response_bytes")]
        max_bytes: usize,
    },
    Compose {
        #[serde(default)]
        generation: Option<String>,
        #[serde(default)]
        identity: Option<Identity>,
        #[serde(default)]
        lease: Option<String>,
    },
    ReadProduct {
        generation: String,
        identity: Identity,
        lease: String,
        field: String,
        start: usize,
        limit: usize,
    },
    AccountingCheck {
        #[serde(default)]
        generation: Option<String>,
        #[serde(default)]
        identity: Option<Identity>,
        #[serde(default)]
        lease: Option<String>,
        #[serde(default)]
        ingest: Option<String>,
    },
    EnrichFiles {
        ingest: String,
        paths: Vec<(String, String)>,
    },
    PatchBegin {
        generation: String,
        identity: Identity,
        lease: String,
        target_identity: Identity,
        scope: Vec<Owner>,
    },
    PatchAppend {
        ingest: String,
        record: Record,
    },
    PatchCommit {
        ingest: String,
    },
    PatchAbort {
        ingest: String,
    },
    DependencyTrace {
        generation: String,
        identity: Identity,
        lease: String,
        start: usize,
        limit: usize,
    },
    Stop,
}
/// Length is checked before allocation, and read_exact rejects truncation.
pub fn read_frame(
    input: &mut impl Read,
    admit: impl FnOnce(usize) -> Result<()>,
) -> Result<Option<Vec<u8>>> {
    let mut h = [0u8; 4];
    match input.read(&mut h[..1]) {
        Ok(0) => return Ok(None),
        Ok(_) => (),
        Err(_) => return Err("transport_read".into()),
    }
    input
        .read_exact(&mut h[1..])
        .map_err(|_| "truncated_header")?;
    let len = u32::from_be_bytes(h) as usize;
    if len == 0 || len > FRAME_BYTES {
        return Err("frame_limit".into());
    }
    admit(len)?;
    let mut body = vec![0; len];
    input.read_exact(&mut body).map_err(|_| "truncated_frame")?;
    Ok(Some(body))
}
struct Counter(usize);
impl Write for Counter {
    fn write(&mut self, b: &[u8]) -> io::Result<usize> {
        self.0 = self
            .0
            .checked_add(b.len())
            .filter(|n| *n <= FRAME_BYTES)
            .ok_or_else(|| io::Error::other("response_limit"))?;
        Ok(b.len())
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}
pub fn encoded_size(value: &impl Serialize) -> Result<usize> {
    let mut counter = Counter(0);
    serde_json::to_writer(&mut counter, value).map_err(|_| "record_limit")?;
    Ok(counter.0)
}
pub fn write_frame(output: &mut impl Write, value: &impl Serialize) -> Result<()> {
    let mut count = Counter(0);
    serde_json::to_writer(&mut count, value).map_err(|_| "response_limit")?;
    output
        .write_all(&(count.0 as u32).to_be_bytes())
        .map_err(|_| "transport_write")?;
    serde_json::to_writer(&mut *output, value).map_err(|_| "transport_write")?;
    output.flush().map_err(|_| "transport_write".to_string())
}
#[derive(Serialize)]
struct Page<'a> {
    protocol: &'static str,
    request_id: &'a str,
    ok: bool,
    generation: &'a str,
    start: usize,
    total: usize,
    next: Option<usize>,
    records: &'a [Record],
}
fn env_limit(key: &str) -> Result<usize> {
    std::env::var(key)
        .ok()
        .and_then(|v| v.parse().ok())
        .filter(|n| *n > 0)
        .ok_or_else(|| format!("admission_required_{key}"))
}
fn typed_digest(value: &super::model::Value) -> Result<String> {
    struct HashWriter(Sha256);
    impl Write for HashWriter {
        fn write(&mut self, buf: &[u8]) -> io::Result<usize> {
            self.0.update(buf);
            Ok(buf.len())
        }
        fn flush(&mut self) -> io::Result<()> {
            Ok(())
        }
    }
    let mut writer = HashWriter(Sha256::new());
    serde_json::to_writer(&mut writer, value).map_err(|_| "diagnostics_digest")?;
    Ok(format!("{:x}", writer.0.finalize()))
}
fn response_bytes() -> usize {
    FRAME_BYTES
}
pub fn serve() -> Result<()> {
    let mut store = Store::new(Limits {
        resident_bytes: env_limit("ARCHITECTURE_KIT_GRAPH_RESIDENT_BYTES")?,
        transition_bytes: env_limit("ARCHITECTURE_KIT_GRAPH_TRANSITION_BYTES")?,
    })?;
    let session = crate::session()?;
    let stdin = io::stdin();
    let stdout = io::stdout();
    let mut input = stdin.lock();
    let mut output = stdout.lock();
    write_frame(
        &mut output,
        &json!({"protocol":PROTOCOL,"capabilities":[PROTOCOL,"basic-graph-v1"],"experimental":true,"schema":super::SCHEMA,"session":session,"max_frame_bytes":FRAME_BYTES,"max_record_bytes":super::RECORD_BYTES,"max_control_bytes":CONTROL_BYTES,"limits":store.limits}),
    )?;
    while let Some(body) = read_frame(&mut input, |len| store.admit_frame(len))? {
        let decode_phase = super::measure::Span::new("decode", Some(store.owned()));
        let mut d = serde_json::Deserializer::from_slice(&body);
        d.disable_recursion_limit();
        let req = Request::deserialize(&mut d).map_err(|_| "request_shape")?;
        d.end().map_err(|_| "request_trailing")?;
        if req.protocol != PROTOCOL
            || req.session != session
            || req.request_id.is_empty()
            || req.request_id.len() > 128
        {
            return Err("request_identity".into());
        }
        if !matches!(
            req.operation,
            Operation::Append { .. } | Operation::PatchAppend { .. }
        ) && body.len() > CONTROL_BYTES
        {
            return Err("control_limit".into());
        }
        drop(body);
        decode_phase.finish(Some(store.owned()));
        let request_id = req.request_id;
        let result = (|| -> Result<serde_json::Value> {
            match req.operation {
                Operation::Begin {
                    identity,
                    options,
                    scope,
                } => Ok(json!({"ingest":store.begin(identity,options,scope)?})),
                Operation::Append { ingest, record } => {
                    ingest_id(&store, &ingest)?;
                    store.append(record)?;
                    Ok(json!({"appended":true}))
                }
                Operation::Commit { ingest } => {
                    ingest_id(&store, &ingest)?;
                    Ok(
                        json!({"generation":store.commit()?,"ready_ram":true,"cache_status":store.cache_status}),
                    )
                }
                Operation::Abort { ingest } => {
                    ingest_id(&store, &ingest)?;
                    store.abort();
                    Ok(json!({"aborted":true}))
                }
                Operation::Pin {
                    generation,
                    identity,
                } => Ok(json!({"lease":store.pin(&generation,&identity)?})),
                Operation::Release { lease } => {
                    store.release(&lease)?;
                    Ok(json!({"released":true}))
                }
                Operation::ReadBatch {
                    generation,
                    identity,
                    lease,
                    start,
                    limit,
                } => {
                    if limit == 0 || limit > PAGE_RECORDS {
                        return Err("page_limit".into());
                    }
                    let g = store.leased(&lease, &generation, &identity)?;
                    if start > g.records.len() {
                        return Err("page_start".into());
                    }
                    let mut end = start;
                    while end < g.records.len() && end - start < limit {
                        let page = Page {
                            protocol: PROTOCOL,
                            request_id: &request_id,
                            ok: true,
                            generation: &g.id,
                            start,
                            total: g.records.len(),
                            next: Some(end + 1),
                            records: &g.records[start..=end],
                        };
                        if serde_json::to_writer(Counter(0), &page).is_err() {
                            break;
                        }
                        end += 1;
                    }
                    if end == start && start < g.records.len() {
                        return Err("record_response_limit".into());
                    }
                    write_frame(
                        &mut output,
                        &Page {
                            protocol: PROTOCOL,
                            request_id: &request_id,
                            ok: true,
                            generation: &g.id,
                            start,
                            total: g.records.len(),
                            next: if end < g.records.len() {
                                Some(end)
                            } else {
                                None
                            },
                            records: &g.records[start..end],
                        },
                    )?;
                    Ok(serde_json::Value::Null)
                }
                Operation::Snapshot {
                    generation,
                    identity,
                    lease,
                    directory,
                } => {
                    let g = store.leased(&lease, &generation, &identity)?;
                    let admission = store
                        .admit_cache_write(g)
                        .and_then(|_| snapshot::basic_plan(g, store.limits.transition_bytes));
                    let plan = match admission {
                        Ok(plan) => plan,
                        Err(error) => {
                            store.cache_status = "error".into();
                            return Err(error);
                        }
                    };
                    let layout=plan.map(|p|json!({"parts":p.parts,"segments":p.segments,"maximum_parts_per_segment":super::BASIC_SNAPSHOT_PARTS,"target_encoded_bytes":p.target,"maximum_encoded_bytes":p.maximum,"hard_maximum_encoded_bytes":16*1024*1024,"record_segments":g.records.len(),"canonical_segments":g.canonical_files.len().div_ceil(32),"file_fsync_calls":p.segments+g.records.len()+g.canonical_files.len().div_ceil(32)+2,"directory_fsync_calls":2}));
                    let result = snapshot::write_snapshot_guarded(Path::new(&directory), g, &store);
                    store.cache_status = if result.is_ok() { "write" } else { "error" }.into();
                    result?;
                    Ok(
                        json!({"cache_status":store.cache_status,"ready_ram":true,"basic_snapshot_layout":layout}),
                    )
                }
                Operation::Restore {
                    identity,
                    directory,
                    validate_canonical,
                } => {
                    let result=snapshot::restore(Path::new(&directory),&identity,&store).and_then(|g|{
                        if g.basic.is_some(){if !validate_canonical{return Err("canonical_validation_required".into())}let(generation,lease)=store.prepare_restore(g)?;Ok(json!({"generation":generation,"validation_lease":lease,"ready_ram":false,"validation_required":true,"cache_status":"read"}))}
                        else {if validate_canonical{return Err("basic_not_ready".into())}let generation=store.install(g)?;Ok(json!({"generation":generation,"ready_ram":true,"cache_status":"read"}))}
                    });
                    store.cache_status = if result.is_ok() { "read" } else { "error" }.into();
                    result
                }
                Operation::ScopePage {
                    generation,
                    identity,
                    lease,
                    start,
                    limit,
                } => {
                    if limit == 0 || limit > PAGE_RECORDS {
                        return Err("page_limit".into());
                    }
                    let g = store.canonical_generation(&lease, &generation, &identity)?;
                    if start > g.scope.len() {
                        return Err("page_start".into());
                    }
                    let end = start.saturating_add(limit).min(g.scope.len());
                    Ok(
                        json!({"generation":generation,"start":start,"total":g.scope.len(),"owners":&g.scope[start..end],"next":if end<g.scope.len(){Some(end)}else{None}}),
                    )
                }
                Operation::CanonicalPage {
                    generation,
                    identity,
                    lease,
                    start,
                    limit,
                } => {
                    if limit == 0 || limit > 32 {
                        return Err("page_limit".into());
                    }
                    let g = store.canonical_generation(&lease, &generation, &identity)?;
                    if start > g.canonical_files.len() {
                        return Err("page_start".into());
                    }
                    let end = start.saturating_add(limit).min(g.canonical_files.len());
                    let paths: Vec<_> = g
                        .canonical_files
                        .iter()
                        .skip(start)
                        .take(end - start)
                        .collect();
                    Ok(
                        json!({"generation":g.id,"start":start,"total":g.canonical_files.len(),"paths":paths,"next":if end<g.canonical_files.len(){Some(end)}else{None}}),
                    )
                }
                Operation::AcceptRestore {
                    generation,
                    identity,
                    lease,
                } => Ok(
                    json!({"generation":store.accept_restore(&generation,&identity,&lease)?,"ready_ram":true,"basic_ready":true,"cache_status":"read"}),
                ),
                Operation::RejectRestore {
                    generation,
                    identity,
                    lease,
                } => {
                    store.reject_restore(&generation, &identity, &lease)?;
                    Ok(json!({"rejected":true,"ready_ram":false}))
                }
                Operation::AuditPage {
                    generation,
                    identity,
                    lease,
                    start,
                    limit,
                    impact,
                    diagnostics_only,
                    max_bytes,
                } => {
                    if limit == 0 || limit > PAGE_RECORDS {
                        return Err("page_limit".into());
                    }
                    let g = store.leased(&lease, &generation, &identity)?;
                    if start > g.records.len() {
                        return Err("page_start".into());
                    }
                    store.admit_basic_work(g, 16)?;
                    if max_bytes < 8192 || max_bytes > FRAME_BYTES {
                        return Err("response_admission".into());
                    }
                    let mut end = start;
                    let mut records = super::compose::value::empty();
                    while end < g.records.len() && end - start < limit {
                        let r = &g.records[end];
                        let row = audit_row(&r.owner, &r.entry, impact, diagnostics_only)?;
                        let mut candidate = records.clone();
                        candidate.push(row);
                        if !encoded_size(&candidate).is_ok_and(|n| n <= max_bytes - 4096) {
                            break;
                        }
                        records = candidate;
                        end += 1;
                    }
                    if end == start && start < g.records.len() {
                        return Err("record_response_limit".into());
                    }
                    Ok(
                        json!({"schema":"native-audit-projection-v1","generation":generation,"start":start,"total":g.records.len(),"next":if end<g.records.len(){Some(end)}else{None},"records":records}),
                    )
                }
                Operation::Preflight {
                    generation,
                    identity,
                    lease,
                    request_bytes,
                } => {
                    let g = store.leased(&lease, &generation, &identity)?;
                    let scratch = Store::basic_work_reservation(g, 16)?
                        .max(Store::frame_reservation(request_bytes)?);
                    let required = store
                        .owned()
                        .checked_add(scratch)
                        .ok_or("budget_overflow")?;
                    Ok(
                        json!({"contract":"native-basic-work-v1","generation":generation,"store_owned_bytes":store.owned(),"generation_owned_bytes":g.owned(),
                        "scratch_bytes":scratch,"required_transition_bytes":required,"request_bytes":request_bytes,"lease_count":store.lease_count()}),
                    )
                }
                Operation::SetLimits { limits } => {
                    if limits.resident_bytes == 0 || limits.transition_bytes == 0 {
                        return Err("limits_shape".into());
                    }
                    store.limits = limits;
                    Ok(json!({"limits":store.limits}))
                }
                Operation::Summary {
                    generation,
                    identity,
                    lease,
                } => {
                    let g = store.leased(&lease, &generation, &identity)?;
                    let product = g.basic.as_ref().ok_or("basic_not_ready")?;
                    let mut summary = std::collections::BTreeMap::<String, usize>::new();
                    for d in product.state.fields[2].vals() {
                        *summary.entry(d.get("code").text().to_string()).or_default() += 1;
                    }
                    Ok(
                        json!({"generation":generation,"diagnostics_by_code":summary,"diagnostic_total":product.state.fields[2].rows().len(),"diagnostics_digest":typed_digest(&product.state.fields[2])?,"checks_complete":product.state.complete()}),
                    )
                }
                Operation::Status => Ok(
                    json!({"generation":store.current.as_ref().map(|g|&g.id),"ingest":store.pending.as_ref().map(|g|&g.id),"owned_capacity_bytes":store.owned(),"peak_owned_capacity_bytes":store.peak_owned,"cache_status":store.cache_status,"measurement":"owned_capacity_estimate_not_rss","process_usage":usage(),"phase_measurements":super::measure::report(),"accounting_full_scans":super::measure::accounting_scans()}),
                ),
                Operation::Limits => Ok(
                    json!({"limits":store.limits,"decode_admission_multiplier":64,"response_reserve_bytes":FRAME_BYTES,"scope_max_bytes":CONTROL_BYTES,"lease_max":64}),
                ),
                Operation::Compact {
                    generation,
                    identity,
                } => Ok(json!({"generation":store.compact(&generation,&identity)?})),
                Operation::Compose {
                    generation,
                    identity,
                    lease,
                } => {
                    let (Some(generation), Some(identity), Some(lease)) =
                        (generation, identity, lease)
                    else {
                        return Err("unsupported".into());
                    };
                    Ok(
                        json!({"generation":store.compose_basic(&generation,&identity,&lease)?,"ready_ram":true,"basic_ready":true}),
                    )
                }
                Operation::ReadProduct {
                    generation,
                    identity,
                    lease,
                    field,
                    start,
                    limit,
                } => {
                    if limit == 0 || limit > PAGE_RECORDS {
                        return Err("page_limit".into());
                    }
                    let g = store.leased(&lease, &generation, &identity)?;
                    let product = g.basic.as_ref().ok_or("basic_not_ready")?;
                    let idx = super::compose::FIELDS
                        .iter()
                        .position(|f| *f == field)
                        .ok_or("product_field")?;
                    let rows = product.state.fields[idx].rows();
                    if start > rows.len() {
                        return Err("page_start".into());
                    }
                    let mut end = start;
                    let mut records = super::compose::value::empty();
                    while end < rows.len() && end - start < limit {
                        let (k, v) = &rows[end];
                        let mut candidate = records.clone();
                        candidate.put_key(k.clone(), v.clone());
                        if encoded_size(&candidate).is_err() {
                            break;
                        }
                        records = candidate;
                        end += 1;
                    }
                    if end == start && start < rows.len() {
                        return Err("record_response_limit".into());
                    }
                    Ok(
                        json!({"generation":g.id,"field":field,"start":start,"total":rows.len(),"next":if end<rows.len(){Some(end)}else{None},"records":records}),
                    )
                }
                Operation::QueryPage {
                    generation,
                    identity,
                    lease,
                    query,
                    start,
                    limit,
                    diagnostic_start,
                    max_bytes,
                } => {
                    let (Some(generation), Some(identity), Some(lease), Some(query)) =
                        (generation, identity, lease, query)
                    else {
                        return Err("unsupported".into());
                    };
                    if limit == 0 || limit > PAGE_RECORDS {
                        return Err("page_limit".into());
                    }
                    if start > 0 && store.current.as_ref().is_none_or(|g| g.id != generation) {
                        return Err("stale_snapshot".into());
                    }
                    let g = store.leased(&lease, &generation, &identity)?;
                    store.admit_basic_work(g, 16)?;
                    let product = g.basic.as_ref().ok_or("basic_not_ready")?;
                    let (mut result, execution, field) = if query.operation == "search" {
                        let (result, _) = product.search.find(
                            &product.state,
                            query.query.as_deref().unwrap_or(""),
                            query.kind.as_deref(),
                            query.path.as_deref(),
                        );
                        let execution = if result.get("status").text() == "invalid_input" {
                            super::compose::value::map([
                                ("mode", super::compose::value::s("invalid_input")),
                                ("reason", super::model::Value::Null),
                            ])
                        } else {
                            product
                                .search
                                .execution(query.query.as_deref().unwrap_or(""), &g.id)
                        };
                        (result, execution, "candidates")
                    } else if query.operation == "discovery" {
                        (
                            super::query::discovery::find(&product.state, &query),
                            super::model::Value::Null,
                            "candidates",
                        )
                    } else if ["context", "path", "impact", "test_candidates"]
                        .contains(&query.operation.as_str())
                    {
                        (
                            super::query::query(&product.state, &query),
                            super::model::Value::Null,
                            "records",
                        )
                    } else {
                        return Err("query_operation".into());
                    };
                    let rows = result.get(field).clone();
                    let total = rows.rows().len();
                    if start > total {
                        return Err("page_start".into());
                    }
                    let mut records = super::compose::value::empty();
                    let mut end = start;
                    while end < total && end - start < limit {
                        let mut candidate = records.clone();
                        candidate.push(rows.rows()[end].1.clone());
                        if encoded_size(&candidate).is_err() {
                            break;
                        }
                        records = candidate;
                        end += 1;
                    }
                    if end == start && start < total {
                        return Err("record_response_limit".into());
                    }
                    result.put(field, super::compose::value::empty());
                    let diagnostics = &product.state.fields[2];
                    let dtotal = diagnostics.rows().len();
                    if diagnostic_start > dtotal {
                        return Err("page_start".into());
                    }
                    let dend = (diagnostic_start + limit).min(dtotal);
                    let ds = super::compose::value::list(
                        diagnostics
                            .vals()
                            .skip(diagnostic_start)
                            .take(dend - diagnostic_start)
                            .cloned(),
                    );
                    let reply = json!({"generation":g.id,"start":start,"total":total,"next":if end<total{Some(end)}else{None},"record_field":field,"records":records,"metadata":result,"execution":execution,"diagnostics":ds,"diagnostic_total":dtotal,"diagnostic_next":if dend<dtotal{Some(dend)}else{None}});
                    if max_bytes < 8192
                        || max_bytes > FRAME_BYTES
                        || !encoded_size(&reply).is_ok_and(|n| n <= max_bytes)
                    {
                        return Err("query_response_limit".into());
                    }
                    Ok(reply)
                }
                Operation::AccountingCheck {
                    generation,
                    identity,
                    lease,
                    ingest,
                } => {
                    let g = if let Some(ingest) = ingest {
                        store
                            .pending
                            .as_ref()
                            .filter(|g| g.id == ingest)
                            .ok_or("invalid_ingest")?
                    } else {
                        let (Some(generation), Some(identity), Some(lease)) =
                            (generation, identity, lease)
                        else {
                            return Err("request_identity".into());
                        };
                        store.leased(&lease, &generation, &identity)?
                    };
                    let cached = g.owned();
                    let full = g.owned_full();
                    Ok(
                        json!({"cached":cached,"full":full,"equal":cached==full,"full_scan_counter":super::measure::accounting_scans()}),
                    )
                }
                Operation::EnrichFiles { ingest, paths } => {
                    store.enrich_files(&ingest, paths)?;
                    Ok(json!({"enriched":true}))
                }
                Operation::PatchBegin {
                    generation,
                    identity,
                    lease,
                    target_identity,
                    scope,
                } => Ok(
                    json!({"ingest":store.patch_begin(&generation,&identity,&lease,target_identity,scope)?}),
                ),
                Operation::PatchAppend { ingest, record } => {
                    store.patch_append(&ingest, record)?;
                    Ok(json!({"appended":true}))
                }
                Operation::PatchCommit { ingest } => Ok(
                    json!({"generation":store.patch_commit(&ingest)?,"ready_ram":true,"basic_ready":true}),
                ),
                Operation::PatchAbort { ingest } => {
                    store.patch_abort(&ingest)?;
                    Ok(json!({"aborted":true}))
                }
                Operation::DependencyTrace {
                    generation,
                    identity,
                    lease,
                    start,
                    limit,
                } => {
                    if limit == 0 || limit > PAGE_RECORDS {
                        return Err("page_limit".into());
                    }
                    let g = store.leased(&lease, &generation, &identity)?;
                    let p = g.basic.as_ref().ok_or("basic_not_ready")?;
                    if start > p.trace.len() {
                        return Err("page_start".into());
                    }
                    let end = start.saturating_add(limit).min(p.trace.len());
                    Ok(
                        json!({"generation":g.id,"records":super::compose::value::list(p.trace[start..end].iter().cloned()),"total":p.trace.len(),"next":if end<p.trace.len(){Some(end)}else{None}}),
                    )
                }
                Operation::Stop => Ok(json!({"stopping":true})),
            }
        })();
        let stop = result
            .as_ref()
            .is_ok_and(|v| v.get("stopping") == Some(&serde_json::Value::Bool(true)));
        match result {
            Ok(value) if value.is_null() => (),
            Ok(value) => write_frame(
                &mut output,
                &json!({"protocol":PROTOCOL,"request_id":request_id,"ok":true,"result":value}),
            )?,
            Err(reason) => {
                store.abort_validation();
                write_frame(
                    &mut output,
                    &json!({"protocol":PROTOCOL,"request_id":request_id,"ok":false,"reason":reason}),
                )?
            }
        }
        if stop {
            break;
        }
    }
    Ok(())
}
fn ingest_id(store: &Store, id: &str) -> Result<()> {
    if store.pending.as_ref().is_some_and(|g| g.id == id) {
        Ok(())
    } else {
        Err("invalid_ingest".into())
    }
}

/// OS lifetime counters for this exact process. Not a sampled or per-phase peak.
fn usage() -> serde_json::Value {
    let mut ru = std::mem::MaybeUninit::<libc::rusage>::zeroed();
    if unsafe { libc::getrusage(libc::RUSAGE_SELF, ru.as_mut_ptr()) } != 0 {
        return json!({"status":"not_available","reason":"getrusage_failed"});
    }
    let ru = unsafe { ru.assume_init() };
    let rss = ru.ru_maxrss as u64 * if cfg!(target_os = "macos") { 1 } else { 1024 };
    json!({"status":"available","pid":std::process::id(),"cpu_user_us":ru.ru_utime.tv_sec as u64*1000000+ru.ru_utime.tv_usec as u64,"cpu_system_us":ru.ru_stime.tv_sec as u64*1000000+ru.ru_stime.tv_usec as u64,"lifetime_peak_rss_bytes":rss,"rss_kind":"getrusage_process_lifetime_peak","per_phase_rss":"not_available"})
}
