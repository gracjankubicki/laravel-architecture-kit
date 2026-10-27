use super::*;
use model::{Options, Record, Value};
use serde_json::json;
use std::{fs, io::Cursor, os::unix::fs::PermissionsExt, path::PathBuf};
use store::{Identity, Limits, Owner, Store};
fn opts() -> Options {
    Options {
        impact: false,
        catalog: false,
        preserve_occurrences: true,
        classification_fingerprint: "fixture".into(),
    }
}
fn identity() -> Identity {
    Identity {
        repository: "repo".into(),
        profile: "profile".into(),
        product: "basic-local".into(),
        parser: "php".into(),
        tool: "fixture".into(),
        configuration: "config".into(),
        capture: "capture".into(),
    }
}
fn record() -> Record {
    Record::decode(&serde_json::to_vec(&json!({"ordinal":0,"owner":"empty.php","kind":"php","sha256":snapshot::hash(b""),"php_version":"8.3","options":opts(),"witness":{"original":{"length":0,"sha256":snapshot::hash(b""),"newlines":[]},"prepared":{"length":0,"sha256":snapshot::hash(b""),"newlines":[]},"mapping_version":"","segments":[],"mapping_fingerprint":""},"entry":["map",[["string","symbols",["list",[]]],["string","edges",["list",[]]],["string","test_invocations",["list",[]]],["string","impact",["null",null]],["string","catalog",["null",null]],["string","syntax_error",["null",null]],["string","syntax_error_line",["int",1]]]]})).unwrap()).unwrap()
}
fn scope(r: &Record) -> Vec<Owner> {
    vec![Owner {
        owner: r.owner.clone(),
        sha256: r.sha256.clone(),
        kind: r.kind.clone(),
        php_version: r.php_version.clone(),
    }]
}
fn store() -> Store {
    Store::new(Limits {
        resident_bytes: 64 * 1024 * 1024,
        transition_bytes: 512 * 1024 * 1024,
    })
    .unwrap()
}
fn load(s: &mut Store) -> String {
    let r = record();
    s.begin(identity(), opts(), scope(&r)).unwrap();
    s.append(r).unwrap();
    s.commit().unwrap()
}
fn dir() -> PathBuf {
    let d = std::env::temp_dir()
        .canonicalize()
        .unwrap()
        .join(format!("ak-r02-rust-{}", crate::session().unwrap()));
    fs::create_dir(&d).unwrap();
    fs::set_permissions(&d, fs::Permissions::from_mode(0o700)).unwrap();
    d
}
#[test]
fn graph_sha256_standard_vectors() {
    assert_eq!(
        snapshot::hash(b""),
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    );
    assert_eq!(
        snapshot::hash(b"abc"),
        "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
    );
}
#[test]
fn graph_typed_php_roundtrip_and_negative_keys() {
    let b = r#"["map",[["string","01",["float",0.0]],["int",0,["bool",false]],["string","null",["null",null]],["string","empty",["list",[]]],["string","UTF8",["string","Zażółć"]]]]"#;
    let v: Value = serde_json::from_slice(b.as_bytes()).unwrap();
    assert_eq!(
        serde_json::from_slice::<Value>(&serde_json::to_vec(&v).unwrap()).unwrap(),
        v
    );
    for b in [
        r#"["map",[["string","0",["null",null]]]]"#,
        r#"["map",[["string","a",["int",1]],["string","a",["int",2]]]]"#,
        r#"["list",[["int",1,["int",2]]]]"#,
        r#"["float",0]"#,
        r#"["map",[]]"#,
        r#"["int",false]"#,
    ] {
        assert!(serde_json::from_str::<Value>(b).is_err(), "{b}")
    }
}
#[test]
fn graph_interner_case_unicode_and_similar_bytes() {
    let mut p = std::collections::HashSet::new();
    let mut v: Value = serde_json::from_value(json!([
        "list",
        [
            ["int", 0, ["string", "01"]],
            ["int", 1, ["string", "01"]],
            ["int", 2, ["string", "1"]],
            ["int", 3, ["string", "A"]],
            ["int", 4, ["string", "a"]],
            ["int", 5, ["string", "ą"]]
        ]
    ]))
    .unwrap();
    v.intern(&mut p);
    assert_eq!(p.len(), 5);
    let a = v.list().unwrap();
    if let (Value::String(a), Value::String(b)) = (a[0], a[1]) {
        assert!(std::sync::Arc::ptr_eq(a, b))
    } else {
        panic!()
    }
}
#[test]
fn graph_strict_record_duplicates_and_unknown() {
    let b = serde_json::to_string(&record()).unwrap();
    let b = b.replacen("\"ordinal\":0", "\"ordinal\":0,\"ordinal\":0", 1);
    assert!(Record::decode(b.as_bytes()).is_err());
    let b = serde_json::to_string(&record()).unwrap().replacen(
        "\"ordinal\":0",
        "\"ordinal\":0,\"extra\":0",
        1,
    );
    assert!(Record::decode(b.as_bytes()).is_err());
}
#[test]
fn graph_coverage_order_count_abort_and_writer() {
    let mut s = store();
    let r = record();
    let id = s.begin(identity(), opts(), scope(&r)).unwrap();
    assert_eq!(
        s.begin(identity(), opts(), scope(&r)).unwrap_err(),
        "writer_busy"
    );
    assert_eq!(s.commit().unwrap_err(), "coverage_incomplete");
    let mut r = record();
    r.ordinal = 1;
    assert_eq!(s.append(r).unwrap_err(), "coverage_mismatch");
    assert!(s.pending.is_none());
    s.begin(identity(), opts(), scope(&record())).unwrap();
    s.abort();
    assert!(s.current.is_none());
    assert!(s.pending.is_none());
    assert!(!id.is_empty());
}
#[test]
fn graph_witness_and_product_validation() {
    let mut r = record();
    r.witness.original.newlines.push(0);
    assert!(r.validate().is_err());
    let mut r = record();
    r.options.catalog = true;
    assert_eq!(r.validate().unwrap_err(), "option_product");
    let mut r = record();
    r.sha256 = "a".repeat(64);
    assert!(r.validate().is_err());
}
#[test]
fn graph_lease_generation_is_immutable_and_freed() {
    let mut s = store();
    let one = load(&mut s);
    let l = s.pin(&one, &identity()).unwrap();
    let old = s.leased(&l, &one, &identity()).unwrap().records.clone();
    let two = load(&mut s);
    assert_ne!(one, two);
    assert_eq!(s.leased(&l, &one, &identity()).unwrap().records, old);
    let n = s.owned();
    s.release(&l).unwrap();
    assert!(s.owned() < n);
    assert!(s.pin(&one, &identity()).is_err());
    assert!(s.leased(&l, &two, &identity()).is_err());
}
#[test]
fn graph_budget_before_decode_and_publication() {
    let mut s = store();
    assert!(s.admit_frame(FRAME_BYTES + 1).is_err());
    s.limits.transition_bytes = 32;
    assert_eq!(s.admit_frame(1).unwrap_err(), "transition_budget");
    assert!(s.begin(identity(), opts(), scope(&record())).is_err());
    assert!(s.pending.is_none());
    let mut s = store();
    s.limits.resident_bytes = 1;
    let r = record();
    s.begin(identity(), opts(), scope(&r)).unwrap();
    s.append(r).unwrap();
    assert_eq!(s.commit().unwrap_err(), "resident_budget");
    assert!(s.current.is_none());
}
#[test]
fn graph_frame_boundaries_and_truncation() {
    for n in [FRAME_BYTES - 1, FRAME_BYTES] {
        let mut b = (n as u32).to_be_bytes().to_vec();
        b.resize(n + 4, b'x');
        let mut called = false;
        let out = protocol::read_frame(&mut Cursor::new(b), |len| {
            called = true;
            assert_eq!(len, n);
            Ok(())
        })
        .unwrap()
        .unwrap();
        assert!(called);
        assert_eq!(out.len(), n);
    }
    let b = ((FRAME_BYTES + 1) as u32).to_be_bytes();
    let mut called = false;
    assert!(
        protocol::read_frame(&mut Cursor::new(b), |_| {
            called = true;
            Ok(())
        })
        .is_err()
    );
    assert!(!called);
    for b in [vec![0], vec![0, 0, 0, 2, b'a']] {
        assert!(protocol::read_frame(&mut Cursor::new(b), |_| Ok(())).is_err())
    }
}
#[test]
fn graph_cache_roundtrip_and_no_source_dependencies() {
    let mut s = store();
    let id = load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    let restored = snapshot::restore(&d, &identity(), &store()).unwrap();
    assert_eq!(restored.id, id);
    assert_eq!(restored.records, s.current.as_ref().unwrap().records);
    assert_eq!(restored.names, s.current.as_ref().unwrap().names);
    fs::remove_dir_all(d).unwrap();
}
#[test]
fn graph_atomic_failures_keep_old_snapshot() {
    let mut s = store();
    let old = load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    load(&mut s);
    for at in ["segments", "manifest", "pointer"] {
        assert!(snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), Some(at)).is_err());
        assert_eq!(
            snapshot::restore(&d, &identity(), &store()).unwrap().id,
            old
        );
    }
    assert!(snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), Some("published")).is_err());
    assert_eq!(
        snapshot::restore(&d, &identity(), &store()).unwrap().id,
        s.current.as_ref().unwrap().id
    );
    fs::remove_dir_all(d).unwrap();
}
fn current_dir(d: &std::path::Path) -> PathBuf {
    let p: serde_json::Value =
        serde_json::from_slice(&fs::read(d.join("CURRENT")).unwrap()).unwrap();
    d.join(p["generation"].as_str().unwrap())
}
fn rewrite_manifest(d: &std::path::Path, change: impl FnOnce(&mut serde_json::Value)) {
    let g = current_dir(d);
    let mut m: serde_json::Value =
        serde_json::from_slice(&fs::read(g.join("manifest.json")).unwrap()).unwrap();
    change(&mut m);
    let b = serde_json::to_vec(&m).unwrap();
    fs::write(g.join("manifest.json"), &b).unwrap();
    let mut p: serde_json::Value =
        serde_json::from_slice(&fs::read(d.join("CURRENT")).unwrap()).unwrap();
    p["manifest_sha256"] = json!(snapshot::hash(&b));
    fs::write(d.join("CURRENT"), serde_json::to_vec(&p).unwrap()).unwrap();
}
#[test]
fn graph_corruption_rehashed_structure_is_rejected() {
    for what in ["hash", "ordinal", "codec", "decoded", "scope", "identity"] {
        let mut s = store();
        load(&mut s);
        let d = dir();
        snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
        rewrite_manifest(&d, |m| match what {
            "hash" => m["segments"][0]["sha256"] = json!("0".repeat(64)),
            "ordinal" => m["segments"][0]["ordinal"] = json!(1),
            "codec" => m["codec"] = json!("gzip"),
            "decoded" => m["decoded_bytes"] = json!(1),
            "scope" => m["scope"] = json!([]),
            _ => m["identity"]["profile"] = json!("foreign"),
        });
        assert!(
            snapshot::restore(&d, &identity(), &store()).is_err(),
            "{what}"
        );
        fs::remove_dir_all(d).unwrap();
    }
}
#[test]
fn graph_cache_path_symlink_and_foreign_identity() {
    let mut s = store();
    load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    let mut foreign = identity();
    foreign.parser = "mago".into();
    assert!(snapshot::restore(&d, &foreign, &store()).is_err());
    let link = d.join("link");
    std::os::unix::fs::symlink(&d, &link).unwrap();
    assert!(snapshot::restore(&link, &identity(), &store()).is_err());
    fs::remove_dir_all(d).unwrap();
}
#[test]
fn graph_cache_decode_admission_uses_actual_sizes() {
    let mut s = store();
    load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    let mut bounded = store();
    bounded.limits.transition_bytes = 1024;
    assert_eq!(
        snapshot::restore(&d, &identity(), &bounded).unwrap_err(),
        "transition_budget"
    );
    fs::remove_dir_all(d).unwrap();
}
#[test]
fn graph_native_snapshot_mixed_generation_even_rehashed_is_rejected() {
    let mut s = store();
    load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    let old = current_dir(&d);
    load(&mut s);
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    let new = current_dir(&d);
    let b = fs::read(old.join("record-00000000.json")).unwrap();
    fs::write(new.join("record-00000000.json"), &b).unwrap();
    rewrite_manifest(&d, |m| {
        m["segments"][0]["sha256"] = json!(snapshot::hash(&b));
        m["segments"][0]["bytes"] = json!(b.len());
    });
    assert_eq!(
        snapshot::restore(&d, &identity(), &store()).unwrap_err(),
        "cache_record_generation"
    );
    fs::remove_dir_all(d).unwrap();
}
#[test]
fn graph_negative_comparator_same_counts_detects_type_position_owner_and_metadata() {
    let r = record();
    let original = serde_json::to_value(&r).unwrap();
    for key in ["owner", "entry", "witness", "options"] {
        let mut changed = original.clone();
        match key {
            "owner" => changed["owner"] = json!("other.php"),
            "entry" => changed["entry"][1][6][2] = json!(["float", 1.0]),
            "witness" => changed["witness"]["original"]["length"] = json!(1),
            _ => changed["options"]["preserve_occurrences"] = json!(false),
        }
        assert_ne!(original, changed);
    }
}
#[test]
#[ignore = "only executed by graph_real_crash_releases_writer_lock"]
fn graph_snapshot_crash_child() {
    let path = std::env::var("AK_R02_CACHE").unwrap();
    let at = std::env::var("AK_R02_AT").unwrap();
    let mut s = store();
    load(&mut s);
    snapshot::write_snapshot(
        std::path::Path::new(&path),
        s.current.as_ref().unwrap(),
        Some(&at),
    )
    .unwrap();
}
#[test]
fn graph_real_crash_releases_writer_lock() {
    let mut s = store();
    let old = load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    for at in ["segments", "manifest", "pointer", "published"] {
        let child = std::process::Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "graph::tests::graph_snapshot_crash_child",
                "--ignored",
            ])
            .env("AK_R02_CRASH_CHILD", "1")
            .env("AK_R02_CACHE", &d)
            .env("AK_R02_AT", at)
            .output()
            .unwrap();
        assert_eq!(
            child.status.code(),
            Some(86),
            "{}",
            String::from_utf8_lossy(&child.stderr)
        );
        let got = snapshot::restore(&d, &identity(), &store()).unwrap();
        if at == "published" {
            assert_ne!(got.id, old)
        } else {
            assert_eq!(got.id, old)
        } // A new writer must obtain the lock after the child exits without Drop.
        snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    }
    fs::remove_dir_all(d).unwrap();
}
#[test]
fn graph_two_process_writers_are_serialized() {
    let mut s = store();
    load(&mut s);
    let d = dir();
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    use std::os::unix::io::AsRawFd;
    let f = fs::OpenOptions::new()
        .read(true)
        .write(true)
        .open(d.join("writer.lock"))
        .unwrap();
    assert_eq!(
        unsafe { libc::flock(f.as_raw_fd(), libc::LOCK_EX | libc::LOCK_NB) },
        0
    );
    let child = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--exact",
            "graph::tests::graph_snapshot_crash_child",
            "--ignored",
        ])
        .env("AK_R02_CACHE", &d)
        .env("AK_R02_AT", "none")
        .output()
        .unwrap();
    assert!(!child.status.success());
    assert!(String::from_utf8_lossy(&child.stdout).contains("cache_writer_busy"));
    unsafe {
        libc::flock(f.as_raw_fd(), libc::LOCK_UN);
    };
    snapshot::write_snapshot(&d, s.current.as_ref().unwrap(), None).unwrap();
    fs::remove_dir_all(d).unwrap();
}
#[test]
fn graph_compaction_with_old_lease_and_refusal_is_atomic() {
    let mut s = store();
    let old = load(&mut s);
    let lease = s.pin(&old, &identity()).unwrap();
    let n = s.owned();
    s.limits.transition_bytes = n + 1;
    assert_eq!(
        s.compact(&old, &identity()).unwrap_err(),
        "transition_budget"
    );
    assert_eq!(s.current.as_ref().unwrap().id, old);
    s.limits.transition_bytes = 512 * 1024 * 1024;
    let new = s.compact(&old, &identity()).unwrap();
    assert_ne!(old, new);
    assert_eq!(
        s.leased(&lease, &old, &identity()).unwrap().records,
        s.current.as_ref().unwrap().records
    );
    let n = s.owned();
    s.release(&lease).unwrap();
    assert!(s.owned() < n);
}
#[test]
fn graph_batch_boundaries_preserve_65_logical_ordinals() {
    let mut records = Vec::new();
    for ordinal in 0..65 {
        let mut r = record();
        r.ordinal = ordinal;
        r.owner = format!("{ordinal:03}.php").into();
        records.push(r)
    }
    let scope = records
        .iter()
        .map(|r| scope(r).remove(0))
        .collect::<Vec<_>>();
    let mut reference = None;
    for batch in [1, 3, 32] {
        let mut s = store();
        s.begin(identity(), opts(), scope.clone()).unwrap();
        for part in records.chunks(batch) {
            for r in part {
                s.append(r.clone()).unwrap();
            }
        }
        s.commit().unwrap();
        let actual = &s.current.as_ref().unwrap().records;
        if let Some(r) = &reference {
            assert_eq!(actual, r)
        } else {
            reference = Some(actual.clone())
        }
    }
}
#[test]
fn graph_catalog_structural_refs_and_compact_indexes() {
    use model::Key;
    use std::sync::Arc;
    fn string(s: &str) -> Value {
        Value::String(Arc::from(s))
    }
    fn map(fields: Vec<(&str, Value)>) -> Value {
        Value::Array {
            list: false,
            entries: fields
                .into_iter()
                .map(|(k, v)| (Key::String(Arc::from(k)), v))
                .collect(),
        }
    }
    fn list(items: Vec<Value>) -> Value {
        Value::Array {
            list: true,
            entries: items
                .into_iter()
                .enumerate()
                .map(|(i, v)| (Key::Int(i as i64), v))
                .collect(),
        }
    }
    fn elem(id: &str, parent: Value) -> Value {
        map(vec![
            ("id", string(id)),
            ("name", string("01")),
            ("kind", string("file")),
            ("line", Value::Int(1)),
            ("end_line", Value::Int(1)),
            ("offset", Value::Int(0)),
            ("parent", parent),
            ("roles", list(vec![])),
            ("metadata", list(vec![])),
        ])
    }
    let mut r = record();
    r.options.catalog = true;
    r.entry.catalog = map(vec![
        ("v", Value::Int(3)),
        (
            "elements",
            list(vec![elem("01", string("0")), elem("0", Value::Null)]),
        ),
        (
            "relations",
            list(vec![map(vec![
                ("from", string("0")),
                ("to", string("external:X")),
                ("kind", string("uses")),
                ("line", Value::Int(1)),
                ("end_line", Value::Int(1)),
                ("resolution", string("resolved")),
                ("metadata", list(vec![])),
            ])]),
        ),
        ("diagnostics", list(vec![])),
        ("descriptor_receivers", list(vec![])),
    ]);
    r.validate().unwrap();
    let mut s = store();
    s.begin(identity(), r.options.clone(), scope(&r)).unwrap();
    s.append(r.clone()).unwrap();
    s.commit().unwrap();
    let g = s.current.as_ref().unwrap();
    assert_eq!(g.names["01"], vec![(0, 0), (0, 1)]);
    assert_eq!(g.out["0"], vec![(0, 0)]);
    assert_eq!(g.incoming["external:X"], vec![(0, 0)]);
    let d = dir();
    snapshot::write_snapshot(&d, g, None).unwrap();
    let got = snapshot::restore(&d, &identity(), &store()).unwrap();
    assert_eq!(g.names, got.names);
    assert_eq!(g.out, got.out);
    assert_eq!(g.incoming, got.incoming);
    fs::remove_dir_all(d).unwrap();
    let Value::Array { entries, .. } = &mut r.entry.catalog else {
        panic!()
    };
    let (
        _,
        Value::Array {
            entries: relations, ..
        },
    ) = &mut entries[2]
    else {
        panic!()
    };
    let (
        _,
        Value::Array {
            entries: fields, ..
        },
    ) = &mut relations[0]
    else {
        panic!()
    };
    fields[1].1 = string("element:missing");
    assert_eq!(r.validate().unwrap_err(), "to_ref");
}

#[test]
fn graph_r04_authoritative_work_reservation_includes_all_live_leases_and_exact_boundary() {
    let mut s = store();
    let generation = load(&mut s);
    let first = s.pin(&generation, &identity()).unwrap();
    let owned_one = s.owned();
    let second = s.pin(&generation, &identity()).unwrap();
    assert!(s.owned() > owned_one);
    let g = s.current.as_ref().unwrap().clone();
    let required = s.owned() + Store::basic_work_reservation(&g, 16).unwrap();
    s.limits.transition_bytes = required - 1;
    assert_eq!(s.admit_basic_work(&g, 16), Err("transition_budget".into()));
    s.limits.transition_bytes = required;
    assert!(s.admit_basic_work(&g, 16).is_ok());
    assert!(Store::frame_reservation(FRAME_BYTES + 1).is_err());
    let frame_required = s.owned() + Store::frame_reservation(4096).unwrap();
    s.limits.transition_bytes = frame_required - 1;
    assert_eq!(s.admit_frame(4096), Err("transition_budget".into()));
    s.limits.transition_bytes = frame_required;
    assert!(s.admit_frame(4096).is_ok());
    s.release(&second).unwrap();
    assert_eq!(s.owned(), owned_one);
}
