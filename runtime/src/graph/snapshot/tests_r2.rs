use super::*;
use std::{cell::Cell, sync::Arc};

fn directory() -> std::path::PathBuf {
    let dir = std::env::temp_dir()
        .canonicalize()
        .unwrap()
        .join(format!("ak-r02-r2-{}", crate::session().unwrap()));
    fs::create_dir(&dir).unwrap();
    fs::set_permissions(&dir, fs::Permissions::from_mode(0o700)).unwrap();
    dir
}
fn store(limit: usize) -> Store {
    Store::new(super::super::store::Limits {
        resident_bytes: limit,
        transition_bytes: limit,
    })
    .unwrap()
}
fn identity() -> Identity {
    Identity {
        repository: "r2".into(),
        profile: "fixture".into(),
        product: "basic-local".into(),
        parser: "synthetic".into(),
        tool: "fixture".into(),
        configuration: "fixture".into(),
        capture: "fixture".into(),
    }
}

#[test]
fn graph_r2_stat_and_read_use_the_same_descriptor_when_path_is_replaced() {
    let dir = directory();
    let path = dir.join("manifest.json");
    write(&path, b"original").unwrap();
    let larger = dir.join("larger.json");
    write(&larger, &vec![b'x'; 8192]).unwrap();
    let bytes = read_admitted(&path, 1024, |size| {
        assert_eq!(size, 8);
        fs::rename(&larger, &path).unwrap();
        Ok(())
    })
    .unwrap();
    assert_eq!(bytes, b"original");
    fs::remove_dir_all(dir).unwrap();
}
#[test]
fn graph_r2_length_changes_on_the_open_file_are_rejected() {
    for truncate in [false, true] {
        let dir = directory();
        let path = dir.join("manifest.json");
        write(&path, b"original").unwrap();
        let result = read_admitted(&path, 1024, |size| {
            assert_eq!(size, 8);
            let mut f = OpenOptions::new()
                .write(true)
                .truncate(truncate)
                .append(!truncate)
                .open(&path)
                .unwrap();
            f.write_all(b"x").unwrap();
            Ok(())
        });
        assert_eq!(result.unwrap_err(), "cache_file_changed");
        fs::remove_dir_all(dir).unwrap();
    }
}
#[test]
fn graph_r2_stat_limits_precede_admission_and_allocation() {
    let dir = directory();
    let path = dir.join("segment.json");
    write(&path, &[b'x'; 2048]).unwrap();
    let called = Cell::new(false);
    assert_eq!(
        read_admitted(&path, 1024, |_| {
            called.set(true);
            Ok(())
        })
        .unwrap_err(),
        "cache_file_bounds"
    );
    assert!(!called.get());
    assert_eq!(
        store(32 * 1024 * 1024)
            .admit_cache_step(usize::MAX, None, 1, 0)
            .unwrap_err(),
        "budget_overflow"
    );
    fs::remove_dir_all(dir).unwrap();
}
#[test]
fn graph_r2_manifest_reservation_tracks_small_data_instead_of_four_mib_cap() {
    let mut s = store(32 * 1024 * 1024);
    let mut g = Store::restoring(
        identity(),
        Options {
            impact: false,
            catalog: false,
            preserve_occurrences: false,
            classification_fingerprint: "fixture".into(),
        },
        vec![],
        crate::session().unwrap(),
    )
    .unwrap();
    g.intern_value(&mut crate::graph::model::Value::String(Arc::from(
        "p".repeat(1024 * 1024),
    )));
    assert!(g.owned() > 1024 * 1024);
    assert!(
        g.growth_reserve().unwrap() < 64 * 1024,
        "payload bytes must not be multiplied for table growth"
    );
    s.current = Some(Arc::new(g));
    let g = s.current.as_ref().unwrap();
    s.admit_cache_write(g).unwrap();
    assert!(s.admit_cache_step(0, None, 1024, 0).unwrap() < 24 * 1024 * 1024);
    assert_eq!(
        s.admit_cache_step(0, None, 4 * 1024 * 1024, 0).unwrap_err(),
        "transition_budget"
    );
}
