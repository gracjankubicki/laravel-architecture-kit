use super::*;
fn fixture() -> (Store, Generation) {
    let store = Store::new(crate::graph::store::Limits {
        resident_bytes: 64 * 1024 * 1024,
        transition_bytes: 512 * 1024 * 1024,
    })
    .unwrap();
    let identity = Identity {
        repository: "r03".into(),
        profile: "basic".into(),
        product: "basic-v1".into(),
        parser: "synthetic".into(),
        tool: "fixture".into(),
        configuration: "fixture".into(),
        capture: "fixture".into(),
    };
    let options = Options {
        impact: false,
        catalog: true,
        preserve_occurrences: true,
        classification_fingerprint: "fixture".into(),
    };
    let mut g = Store::restoring(identity, options, vec![], crate::session().unwrap()).unwrap();
    g.basic = Some(crate::graph::dependency::compose(&mut g, None, 256 * 1024 * 1024).unwrap());
    (store, g)
}
#[test]
fn ready_basic_fault_boundaries_keep_previous_complete_product_until_pointer_publication() {
    for point in ["segments", "manifest", "pointer", "published"] {
        let root = std::env::temp_dir()
            .canonicalize()
            .unwrap()
            .join(format!("ak-r03-atomic-{}", crate::session().unwrap()));
        fs::create_dir(&root).unwrap();
        fs::set_permissions(&root, fs::Permissions::from_mode(0o700)).unwrap();
        let (store, old) = fixture();
        write_snapshot_guarded(&root, &old, &store).unwrap();
        let (_, next) = fixture();
        let layout = basic_plan(&next, store.limits.transition_bytes).unwrap();
        assert_eq!(
            write_guarded(&root, &next, Some(point), layout).unwrap_err(),
            "injected_cache_crash"
        );
        let restored = restore(&root, &old.identity, &store).unwrap();
        assert_eq!(
            restored.id,
            if point == "published" {
                next.id
            } else {
                old.id
            }
        );
        assert!(
            restored
                .basic
                .as_ref()
                .unwrap()
                .equivalent(old.basic.as_ref().unwrap())
        );
        fs::remove_dir_all(root).unwrap();
    }
}
#[test]
fn basic_budget_rejection_preserves_current_and_immutable_lease() {
    let (mut store, g) = fixture();
    let id = store.install(g).unwrap();
    let identity = store.current.as_ref().unwrap().identity.clone();
    let lease = store.pin(&id, &identity).unwrap();
    let before = store.owned();
    store.limits.transition_bytes = before + 1024;
    assert_eq!(
        store.compose_basic(&id, &identity, &lease).unwrap_err(),
        "transition_budget"
    );
    assert_eq!(store.current.as_ref().unwrap().id, id);
    assert_eq!(store.owned(), before);
    assert!(
        store
            .leased(&lease, &id, &identity)
            .unwrap()
            .basic
            .is_some()
    );
    assert_eq!(
        store
            .admit_cache_write(store.current.as_ref().unwrap())
            .unwrap_err(),
        "cache_basic_admission"
    );
    assert_eq!(store.owned(), before);
}

#[test]
#[ignore = "only executed by ready_basic_process_exit_preserves_atomic_product_and_releases_lock"]
fn ready_basic_crash_child() {
    let root = std::path::PathBuf::from(std::env::var("AK_R03_BASIC_CACHE").unwrap());
    let point = std::env::var("AK_R03_BASIC_AT").unwrap();
    let (store, next) = fixture();
    let layout = basic_plan(&next, store.limits.transition_bytes).unwrap();
    write_guarded(&root, &next, Some(&point), layout).unwrap();
}

#[test]
fn ready_basic_process_exit_preserves_atomic_product_and_releases_lock() {
    let root = std::env::temp_dir()
        .canonicalize()
        .unwrap()
        .join(format!("ak-r03-basic-crash-{}", crate::session().unwrap()));
    fs::create_dir(&root).unwrap();
    fs::set_permissions(&root, fs::Permissions::from_mode(0o700)).unwrap();
    let (store, old) = fixture();
    for point in ["segments", "manifest", "pointer", "published"] {
        write_snapshot_guarded(&root, &old, &store).unwrap();
        let output = std::process::Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "graph::snapshot::tests_r3::ready_basic_crash_child",
                "--ignored",
            ])
            .env("AK_R02_CRASH_CHILD", "1")
            .env("AK_R03_BASIC_CACHE", &root)
            .env("AK_R03_BASIC_AT", point)
            .output()
            .unwrap();
        assert_eq!(
            output.status.code(),
            Some(86),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let restored = restore(&root, &old.identity, &store).unwrap();
        if point == "published" {
            assert_ne!(restored.id, old.id);
        } else {
            assert_eq!(restored.id, old.id);
        }
        assert!(
            restored
                .basic
                .as_ref()
                .unwrap()
                .equivalent(old.basic.as_ref().unwrap())
        );
        // Must acquire the writer lock after process::exit, which bypasses Drop.
        write_snapshot_guarded(&root, &old, &store).unwrap();
    }
    fs::remove_dir_all(root).unwrap();
}
