use architecture_kit_runtime::*;
use notify::{RecommendedWatcher, RecursiveMode, Watcher};
use serde_json::json;
#[cfg(unix)]
use std::os::unix::fs::MetadataExt;
use std::{
    collections::BTreeMap,
    io::{self, Write},
    path::PathBuf,
    sync::mpsc,
    sync::{Arc, Condvar, Mutex},
    thread,
    time::Duration,
};

fn descriptor(session: &str) -> serde_json::Value {
    json!({"protocol":PROTOCOL,"runtime_version":VERSION,"platform":platform(),"target":target(),"capabilities":CAPABILITIES,"session":session,"sequence":"1","health":"unverified","reason":"bootstrap_required"})
}
fn limit(name: &str, default: usize, max: usize) -> Result<usize, String> {
    match std::env::var(name) {
        Ok(value) => value
            .parse::<usize>()
            .ok()
            .filter(|n| *n > 0 && *n <= max)
            .ok_or(format!("invalid_{name}")),
        Err(_) => Ok(default),
    }
}
fn identities(plan: &BTreeMap<PathBuf, bool>) -> BTreeMap<PathBuf, (u64, u64)> {
    plan.keys()
        .filter_map(|path| {
            std::fs::metadata(path)
                .ok()
                .map(|m| (path.clone(), (m.dev(), m.ino())))
        })
        .collect()
}
fn run() -> Result<(), String> {
    let args: Vec<_> = std::env::args().skip(1).collect();
    if args == ["--licenses"] {
        print!("{}", graph::compose::sort::NOTICE);
        return Ok(());
    }
    if args == ["--graph-store-v1"] {
        return graph::protocol::serve();
    }
    if args == ["--version"] {
        println!(
            "{}",
            json!({"runtime_version":VERSION,"protocol":PROTOCOL,"notify_version":"8.2.1","platform":platform(),"target":target(),"linkage":linkage(),"capabilities":["events-v1","continuity-loss-v1","graph-store-v1","basic-graph-v1"]})
        );
        return Ok(());
    }
    if !args.is_empty() || platform() == "unsupported" {
        return Err("unsupported_runtime".into());
    }
    let session = session()?;
    let queue = Arc::new((
        Mutex::new(Queue::new(
            limit("ARCHITECTURE_KIT_WATCH_QUEUE_EVENTS", 64, 64)?,
            limit(
                "ARCHITECTURE_KIT_WATCH_QUEUE_BYTES",
                1024 * 1024,
                1024 * 1024,
            )?,
        )),
        Condvar::new(),
    ));
    let mut output = io::stdout().lock();
    writeln!(output, "{}", descriptor(&session)).map_err(|_| "transport_error")?;
    output.flush().map_err(|_| "transport_error")?;
    drop(output);
    // No roots, cwd inspection or filesystem watcher exists before this handshake.
    let writer_queue = Arc::clone(&queue);
    let writer_session = session.clone();
    let writer = thread::spawn(move || -> Result<(), String> {
        let mut sequence = 1;
        let mut output = io::stdout().lock();
        loop {
            let (lock, wake) = &*writer_queue;
            let mut queue = lock.lock().unwrap();
            let mut event = loop {
                if let Some(event) = queue.pop() {
                    break event;
                }
                if queue.closed {
                    return Ok(());
                }
                queue = wake.wait(queue).unwrap();
            };
            drop(queue);
            output
                .write_all(&event.wire(&writer_session, &mut sequence)?)
                .map_err(|_| "transport_error")?;
            output.flush().map_err(|_| "transport_error")?;
        }
    });
    let mut watcher: Option<RecommendedWatcher> = None;
    let (input_tx, input_rx) = mpsc::sync_channel(1);
    thread::spawn(move || {
        let input = io::stdin();
        let mut input = input.lock();
        loop {
            let frame = read_frame(&mut input);
            let done = !matches!(frame, Ok(Some(_)));
            if input_tx.send(frame).is_err() || done {
                break;
            }
        }
    });
    let mut scope_fault: Option<String> = None;
    let mut active_roots: Vec<PathBuf> = vec![];
    let mut active_plan: BTreeMap<PathBuf, bool> = BTreeMap::new();
    let mut active_identity = BTreeMap::new();
    let result = (|| -> Result<(), String> {
        loop {
            if writer.is_finished() {
                return Err("transport_error".into());
            }
            if let Some(native) = watcher.as_mut() {
                match subscription_plan(&active_roots) {
                    Ok(plan) if plan != active_plan || identities(&plan) != active_identity => {
                        let (lock, wake) = &*queue;
                        lock.lock().unwrap().lose("watch_scope_changed");
                        wake.notify_one();
                        // A root may have been replaced at the same path. Re-register after
                        // marking loss; do not rely on a reliable rename pair or old inode.
                        // notify may remove descendant subscriptions with an ancestor, so
                        // install the complete compact plan after removing the old plan.
                        for path in active_plan.keys() {
                            let _ = native.unwatch(path);
                        }
                        for (path, recursive) in &plan {
                            native
                                .watch(
                                    path,
                                    if *recursive {
                                        RecursiveMode::Recursive
                                    } else {
                                        RecursiveMode::NonRecursive
                                    },
                                )
                                .map_err(|_| "watch_registration_failed")?;
                        }
                        active_identity = identities(&plan);
                        active_plan = plan;
                        scope_fault = None;
                    }
                    Err(reason) if scope_fault.as_ref() != Some(&reason) => {
                        let (lock, wake) = &*queue;
                        lock.lock().unwrap().lose(&reason);
                        wake.notify_one();
                        scope_fault = Some(reason);
                    }
                    Ok(_) => {
                        scope_fault = None;
                    }
                    _ => (),
                }
            }
            let body = match input_rx.recv_timeout(Duration::from_millis(25)) {
                Ok(Ok(Some(body))) => body,
                Ok(Ok(None)) | Err(mpsc::RecvTimeoutError::Disconnected) => break,
                Ok(Err(reason)) => return Err(reason),
                Err(mpsc::RecvTimeoutError::Timeout) => continue,
            };
            let command: Command = serde_json::from_slice(&body).map_err(|_| "protocol_error")?;
            command.validate(&session)?;
            let object: serde_json::Value =
                serde_json::from_slice(&body).map_err(|_| "protocol_error")?;
            let expected_fields = if command.kind == "watch" { 6 } else { 4 };
            if object.as_object().map_or(0, |o| o.len()) != expected_fields {
                return Err("protocol_error".into());
            }
            match command.kind.as_str() {
                "watch" => {
                    if watcher.is_some() {
                        return Err("protocol_error".into());
                    }
                    let roots = command
                        .paths
                        .unwrap()
                        .iter()
                        .map(|p| decode_path(p))
                        .collect::<Result<Vec<_>, _>>()?;
                    let plan = subscription_plan(&roots)?;
                    let callback_queue = Arc::clone(&queue);
                    let callback_roots = roots.clone();
                    let mut native = RecommendedWatcher::new(
                        move |result: notify::Result<notify::Event>| {
                            let (lock, wake) = &*callback_queue;
                            let mut queue = lock.lock().unwrap();
                            if let Some(event) = native_event(result, &callback_roots) {
                                queue.push(event);
                            }
                            wake.notify_one();
                        },
                        notify::Config::default().with_follow_symlinks(false),
                    )
                    .map_err(|_| "native_unavailable")?;
                    for (path, recursive) in &plan {
                        native
                            .watch(
                                path,
                                if *recursive {
                                    RecursiveMode::Recursive
                                } else {
                                    RecursiveMode::NonRecursive
                                },
                            )
                            .map_err(|_| "watch_registration_failed")?;
                    }
                    active_roots = roots;
                    active_identity = identities(&plan);
                    active_plan = plan;
                    watcher = Some(native);
                    let (lock, wake) = &*queue;
                    // Ready follows successful registration; changes already queued are retained.
                    lock.lock().unwrap().push(Event::new(
                        "watch-ready",
                        vec![],
                        Some("bootstrap_required"),
                    ));
                    wake.notify_one();
                }
                "barrier" => {
                    if watcher.is_none() {
                        return Err("protocol_error".into());
                    }
                    let (lock, wake) = &*queue;
                    lock.lock()
                        .unwrap()
                        .push(Event::new("barrier", vec![], None));
                    wake.notify_one();
                }
                "simulate-loss"
                    if std::env::var("ARCHITECTURE_KIT_WATCH_TEST_MODE").as_deref() == Ok("1") =>
                {
                    if watcher.is_none() {
                        return Err("protocol_error".into());
                    }
                    let (lock, wake) = &*queue;
                    let mut signal = notify::Event::new(notify::EventKind::Other);
                    signal.attrs.set_flag(notify::event::Flag::Rescan);
                    let mut event = native_event(Ok(signal), &active_roots).unwrap();
                    event.synthetic = Some(true);
                    lock.lock().unwrap().push(event);
                    wake.notify_one();
                }
                "stop" => break,
                _ => return Err("protocol_error".into()),
            }
        }
        Ok(())
    })();
    drop(watcher);
    let (lock, wake) = &*queue;
    let mut pending = lock.lock().unwrap();
    if let Err(ref reason) = result {
        pending.lose(reason);
    }
    pending.closed = true;
    drop(pending);
    wake.notify_one();
    writer.join().map_err(|_| "writer_failed")??;
    result
}
fn main() {
    if let Err(reason) = run() {
        eprintln!("{reason}");
        std::process::exit(1);
    }
}
