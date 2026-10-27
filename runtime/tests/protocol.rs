use architecture_kit_runtime::{Command, PROTOCOL, read_frame};
use serde_json::{Value, json};
use std::io::{BufReader, Write};
use std::process::{Command as Process, Stdio};

#[test]
fn helper_handshake_precedes_roots_and_rejects_old_protocol_without_ready() {
    let mut process = Process::new(env!("CARGO_BIN_EXE_architecture-kit-runtime"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut output = BufReader::new(process.stdout.take().unwrap());
    let handshake: Value =
        serde_json::from_slice(&read_frame(&mut output).unwrap().unwrap()).unwrap();
    assert_eq!(handshake.as_object().unwrap().len(), 9);
    assert!(handshake.get("paths").is_none());
    assert_eq!(handshake["sequence"], "1");
    let invalid = json!({"protocol":"old-protocol", "session":handshake["session"], "request_id":"test", "kind":"watch", "paths_encoding":"base64-os-bytes", "paths":["L2Rldi9udWxs"]});
    writeln!(process.stdin.as_mut().unwrap(), "{invalid}").unwrap();
    let loss: Value = serde_json::from_slice(&read_frame(&mut output).unwrap().unwrap()).unwrap();
    assert_eq!(loss["kind"], "loss");
    assert_eq!(loss["health"], "unverified");
    assert_eq!(loss["reason"], "protocol_error");
    drop(process.stdin.take());
    assert!(!process.wait().unwrap().success());
}

#[test]
fn command_types_unknown_fields_and_duplicate_fields_are_rejected() {
    let body = json!({"protocol": PROTOCOL, "session":"s", "request_id":"r", "kind":"watch", "paths_encoding":"base64-os-bytes", "paths":["L2E="]});
    assert!(
        serde_json::from_value::<Command>(body.clone())
            .unwrap()
            .validate("s")
            .is_ok()
    );
    for (key, value) in [
        ("protocol", json!(1)),
        ("unknown", json!(true)),
        ("paths", json!("a")),
        ("request_id", json!(2)),
    ] {
        let mut invalid = body.clone();
        invalid[key] = value;
        assert!(serde_json::from_value::<Command>(invalid).is_err());
    }
    assert!(
        serde_json::from_str::<Command>(
            r#"{"protocol":"a","protocol":"b","session":"s","kind":"stop","request_id":"r"}"#
        )
        .is_err()
    );
}

#[cfg(unix)]
#[test]
fn real_helper_rejects_symlink_root_with_explicit_loss_before_watch_ready() {
    use base64::{Engine, engine::general_purpose::STANDARD};
    let directory = std::env::temp_dir().canonicalize().unwrap().join(format!(
        "watch-unsafe-{}",
        architecture_kit_runtime::session().unwrap()
    ));
    std::fs::create_dir_all(directory.join("outside")).unwrap();
    std::os::unix::fs::symlink(directory.join("outside"), directory.join("alias")).unwrap();
    let mut process = Process::new(env!("CARGO_BIN_EXE_architecture-kit-runtime"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut output = BufReader::new(process.stdout.take().unwrap());
    let handshake: Value =
        serde_json::from_slice(&read_frame(&mut output).unwrap().unwrap()).unwrap();
    use std::os::unix::ffi::OsStrExt;
    let request = json!({"protocol": PROTOCOL,"session":handshake["session"],"request_id":"root-test","kind":"watch","paths_encoding":"base64-os-bytes","paths":[STANDARD.encode(directory.join("alias").as_os_str().as_bytes())]});
    writeln!(process.stdin.as_mut().unwrap(), "{request}").unwrap();
    let event: Value = serde_json::from_slice(&read_frame(&mut output).unwrap().unwrap()).unwrap();
    assert_eq!(event["kind"], "loss");
    assert_eq!(event["reason"], "unsafe_symlink");
    assert_eq!(event["rescan_required"], true);
    drop(process.stdin.take());
    assert!(!process.wait().unwrap().success());
    std::fs::remove_dir_all(directory).unwrap();
}

#[cfg(target_os = "linux")]
#[test]
fn linux_native_registration_denial_reports_rescan_instead_of_ready() {
    use base64::{Engine, engine::general_purpose::STANDARD};
    use std::os::unix::{ffi::OsStrExt, fs::PermissionsExt};
    // CI uses an unprivileged native user. Privileged hosts cannot prove EACCES this way.
    if std::fs::read_to_string("/proc/self/status")
        .unwrap()
        .lines()
        .any(|l| l.starts_with("Uid:\t0\t"))
    {
        panic!("OPEN: native registration denial requires an unprivileged Linux user");
    }
    let directory = std::env::temp_dir().canonicalize().unwrap().join(format!(
        "watch-denied-{}",
        architecture_kit_runtime::session().unwrap()
    ));
    std::fs::create_dir_all(directory.join("denied")).unwrap();
    std::fs::set_permissions(
        directory.join("denied"),
        std::fs::Permissions::from_mode(0o000),
    )
    .unwrap();
    let mut process = Process::new(env!("CARGO_BIN_EXE_architecture-kit-runtime"))
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut output = BufReader::new(process.stdout.take().unwrap());
    let handshake: Value =
        serde_json::from_slice(&read_frame(&mut output).unwrap().unwrap()).unwrap();
    let request = json!({"protocol": PROTOCOL,"session":handshake["session"],"request_id":"denied","kind":"watch","paths_encoding":"base64-os-bytes","paths":[STANDARD.encode(directory.join("denied").as_os_str().as_bytes())]});
    writeln!(process.stdin.as_mut().unwrap(), "{request}").unwrap();
    let event: Value = serde_json::from_slice(&read_frame(&mut output).unwrap().unwrap()).unwrap();
    drop(process.stdin.take());
    let status = process.wait().unwrap();
    std::fs::set_permissions(
        directory.join("denied"),
        std::fs::Permissions::from_mode(0o700),
    )
    .unwrap();
    std::fs::remove_dir_all(directory).unwrap();
    assert_eq!(event["kind"], "loss");
    assert_eq!(event["reason"], "watch_registration_failed");
    assert!(!status.success());
}
