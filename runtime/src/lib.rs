use base64::{Engine, engine::general_purpose::STANDARD};
use serde::{Deserialize, Serialize};
#[cfg(unix)]
use std::os::unix::ffi::{OsStrExt, OsStringExt};
use std::{
    collections::VecDeque,
    ffi::OsString,
    io::BufRead,
    path::{Component, Path, PathBuf},
};

pub const PROTOCOL: &str = "architecture-kit-watch-v1";
pub const VERSION: &str = "0.1.0";
pub const MAX_FRAME: usize = 65_536;
pub const MAX_PATH: usize = 16_384;
pub const MAX_SEQUENCE: u64 = i64::MAX as u64;
pub const CAPABILITIES: [&str; 2] = ["events-v1", "continuity-loss-v1"];
pub fn platform() -> &'static str {
    match (std::env::consts::OS, std::env::consts::ARCH) {
        ("macos", "aarch64") => "macos-arm64",
        ("macos", "x86_64") => "macos-x64",
        ("linux", "aarch64") => "linux-arm64",
        ("linux", "x86_64") => "linux-x64",
        _ => "unsupported",
    }
}
pub fn target() -> &'static str {
    env!("KIT_TARGET")
}
pub fn linkage() -> &'static str {
    if cfg!(target_os = "linux") {
        if cfg!(target_env = "musl") {
            "static-musl"
        } else {
            "dynamic"
        }
    } else {
        "system"
    }
}
pub fn session() -> Result<String, String> {
    let mut bytes = [0u8; 32];
    getrandom::fill(&mut bytes).map_err(|_| "session_entropy_unavailable")?;
    Ok(bytes.iter().map(|b| format!("{b:02x}")).collect())
}
pub fn read_frame(reader: &mut impl BufRead) -> Result<Option<Vec<u8>>, String> {
    let mut frame = Vec::new();
    loop {
        let available = reader.fill_buf().map_err(|_| "transport_error")?;
        if available.is_empty() {
            return if frame.is_empty() {
                Ok(None)
            } else {
                Err("truncated_frame".into())
            };
        }
        let count = available
            .iter()
            .position(|b| *b == b'\n')
            .map_or(available.len(), |n| n + 1);
        if frame.len() + count > MAX_FRAME {
            return Err("frame_limit".into());
        }
        frame.extend_from_slice(&available[..count]);
        reader.consume(count);
        if frame.last() == Some(&b'\n') {
            return Ok(Some(frame));
        }
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Command {
    pub protocol: String,
    pub session: String,
    pub request_id: String,
    pub kind: String,
    #[serde(default)]
    pub paths_encoding: Option<String>,
    #[serde(default)]
    pub paths: Option<Vec<String>>,
}
impl Command {
    pub fn validate(&self, session: &str) -> Result<(), String> {
        if self.protocol != PROTOCOL
            || self.session != session
            || self.request_id.is_empty()
            || self.request_id.len() > 128
        {
            return Err("protocol_error".into());
        }
        match self.kind.as_str() {
            "watch"
                if self.paths_encoding.as_deref() == Some("base64-os-bytes")
                    && self.paths.as_ref().is_some_and(|p| !p.is_empty()) =>
            {
                Ok(())
            }
            "barrier" | "stop" | "simulate-loss"
                if self.paths.is_none() && self.paths_encoding.is_none() =>
            {
                Ok(())
            }
            _ => Err("protocol_error".into()),
        }
    }
}
pub fn decode_path(encoded: &str) -> Result<PathBuf, String> {
    let bytes = STANDARD.decode(encoded).map_err(|_| "path_encoding")?;
    if bytes.is_empty()
        || bytes.len() > MAX_PATH
        || bytes.contains(&0)
        || STANDARD.encode(&bytes) != encoded
    {
        return Err("unsafe_path".into());
    }
    #[cfg(unix)]
    {
        let path = PathBuf::from(OsString::from_vec(bytes));
        validate_path(&path)?;
        Ok(path)
    }
    #[cfg(not(unix))]
    {
        let _ = bytes;
        Err("unsupported_encoding".into())
    }
}
pub fn validate_path(path: &Path) -> Result<(), String> {
    if !path.is_absolute()
        || path
            .components()
            .any(|c| matches!(c, Component::ParentDir | Component::CurDir))
    {
        return Err("unsafe_path".into());
    }
    // Reject lexical aliases too: components() normalizes repeated slash and dot.
    let normalized: PathBuf = path.components().collect();
    if normalized.as_os_str() != path.as_os_str() {
        return Err("unsafe_path".into());
    }
    Ok(())
}
pub fn watch_parent(path: &Path) -> Result<PathBuf, String> {
    validate_path(path)?;
    let mut prefix = PathBuf::new();
    for part in path.components() {
        prefix.push(part);
        if std::fs::symlink_metadata(&prefix).is_ok_and(|m| m.file_type().is_symlink()) {
            return Err("unsafe_symlink".into());
        }
    }
    // Watch the parent even for existing roots so removal/replacement is observed.
    let mut ancestor = path.parent().ok_or("unsafe_root")?.to_path_buf();
    while !ancestor.is_dir() {
        if !ancestor.pop() {
            return Err("root_unavailable".into());
        }
    }
    Ok(ancestor)
}
/// Recursive subscriptions cover only explicit existing directory roots.
/// Missing segments and atomic replacements are detected by non-recursive ancestors.
pub fn subscription_plan(
    roots: &[PathBuf],
) -> Result<std::collections::BTreeMap<PathBuf, bool>, String> {
    let mut plan = std::collections::BTreeMap::new();
    for root in roots {
        let parent = watch_parent(root)?;
        plan.entry(parent).or_insert(false);
        if root.is_dir() {
            plan.insert(root.clone(), true);
        }
    }
    // Avoid redundant overlapping subscriptions, including non-recursive children
    // already covered by a permitted recursive root.
    let recursive: Vec<_> = plan
        .iter()
        .filter(|(_, mode)| **mode)
        .map(|(p, _)| p.clone())
        .collect();
    plan.retain(|path, _| {
        !recursive
            .iter()
            .any(|parent| path != parent && path.starts_with(parent))
    });
    Ok(plan)
}
pub fn relevant(path: &Path, roots: &[PathBuf]) -> bool {
    roots
        .iter()
        .any(|root| path.starts_with(root) || root.starts_with(path))
}
#[derive(Clone, Serialize, Debug)]
pub struct Event {
    pub protocol: &'static str,
    pub session: String,
    pub sequence: String,
    pub kind: String,
    pub paths_encoding: &'static str,
    pub paths: Vec<String>,
    pub health: &'static str,
    pub reason: Option<String>,
    pub rescan_required: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub synthetic: Option<bool>,
}
impl Event {
    pub fn new(kind: &str, paths: Vec<String>, reason: Option<&str>) -> Self {
        Self {
            protocol: PROTOCOL,
            session: String::new(),
            sequence: String::new(),
            kind: kind.into(),
            paths_encoding: "base64-os-bytes",
            paths,
            health: if reason.is_some() {
                "unverified"
            } else {
                "observing"
            },
            reason: reason.map(str::to_owned),
            rescan_required: reason.is_some(),
            synthetic: None,
        }
    }
    pub fn from_paths(paths: &[PathBuf]) -> Self {
        let mut encoded = Vec::new();
        let mut bytes = 0;
        for path in paths {
            #[cfg(unix)]
            let raw = path.as_os_str().as_bytes();
            #[cfg(not(unix))]
            let raw: &[u8] = &[];
            if raw.is_empty()
                || raw.len() > MAX_PATH
                || raw.contains(&0)
                || validate_path(path).is_err()
            {
                return Self::new("loss", vec![], Some("unsafe_path"));
            }
            bytes += raw.len();
            if bytes > MAX_FRAME / 2 {
                return Self::new("loss", vec![], Some("frame_limit"));
            }
            encoded.push(STANDARD.encode(raw));
        }
        Self::new("changes", encoded, None)
    }
    pub fn wire(&mut self, session: &str, sequence: &mut u64) -> Result<Vec<u8>, String> {
        if *sequence == MAX_SEQUENCE {
            return Err("sequence_exhausted".into());
        }
        *sequence += 1;
        self.session = session.into();
        self.sequence = sequence.to_string();
        let mut body = serde_json::to_vec(self).map_err(|_| "protocol_error")?;
        body.push(b'\n');
        if body.len() > MAX_FRAME {
            *self = Self::new("loss", vec![], Some("frame_limit"));
            self.session = session.into();
            self.sequence = sequence.to_string();
            body = serde_json::to_vec(self).unwrap();
            body.push(b'\n');
        }
        Ok(body)
    }
}
/// Convert the actual notify backend result without interpreting file contents.
pub fn native_event(result: notify::Result<notify::Event>, roots: &[PathBuf]) -> Option<Event> {
    match result {
        Err(_) => Some(Event::new("loss", vec![], Some("native_error"))),
        Ok(event) if event.need_rescan() => Some(Event::new("loss", vec![], Some("need_rescan"))),
        Ok(event) if matches!(event.kind, notify::EventKind::Access(_)) => None,
        Ok(event) if event.paths.is_empty() => Some(Event::new(
            "loss",
            vec![],
            Some("native_event_without_paths"),
        )),
        Ok(event) => {
            let paths: Vec<_> = event
                .paths
                .into_iter()
                .filter(|p| relevant(p, roots))
                .collect();
            if paths.is_empty() {
                return None;
            }
            if roots
                .iter()
                .chain(paths.iter())
                .any(|p| watch_parent(p).is_err())
            {
                Some(Event::new("loss", vec![], Some("unsafe_symlink")))
            } else {
                Some(Event::from_paths(&paths))
            }
        }
    }
}
/// A pending loss is out of band; it cannot be evicted by the queue it diagnoses.
/// Retained-byte accounting includes encoded paths and all event payload bytes.
pub struct Queue {
    events: VecDeque<(Event, usize)>,
    bytes: usize,
    paths: usize,
    max_events: usize,
    max_bytes: usize,
    max_paths: usize,
    loss: Option<Event>,
    pub closed: bool,
}
impl Queue {
    pub fn new(max_events: usize, max_bytes: usize) -> Self {
        Self {
            events: VecDeque::new(),
            bytes: 0,
            paths: 0,
            max_events,
            max_bytes,
            max_paths: max_events,
            loss: None,
            closed: false,
        }
    }
    pub fn push(&mut self, event: Event) {
        if event.kind == "loss" {
            self.loss = Some(event);
            return;
        }
        let size = serde_json::to_vec(&event).unwrap().len() + 128;
        if self.events.len() >= self.max_events
            || self.paths + event.paths.len() > self.max_paths
            || self.bytes + size > self.max_bytes
        {
            self.lose(if self.bytes + size > self.max_bytes {
                "queue_bytes_overflow"
            } else {
                "queue_paths_overflow"
            });
            return;
        }
        self.bytes += size;
        self.paths += event.paths.len();
        self.events.push_back((event, size));
    }
    pub fn lose(&mut self, reason: &str) {
        self.loss = Some(Event::new("loss", vec![], Some(reason)));
    }
    pub fn pop(&mut self) -> Option<Event> {
        if let Some(event) = self.loss.take() {
            return Some(event);
        }
        self.events.pop_front().map(|(event, size)| {
            self.bytes -= size;
            self.paths -= event.paths.len();
            event
        })
    }
    pub fn retained(&self) -> (usize, usize, usize) {
        (self.events.len(), self.paths, self.bytes)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io;
    #[test]
    fn bounded_frames() {
        assert!(read_frame(&mut io::Cursor::new(vec![b'x'; MAX_FRAME + 1])).is_err());
        let mut frame = vec![b'x'; MAX_FRAME - 1];
        frame.push(b'\n');
        assert_eq!(
            read_frame(&mut io::Cursor::new(frame))
                .unwrap()
                .unwrap()
                .len(),
            MAX_FRAME
        );
        assert!(read_frame(&mut io::Cursor::new(b"{}")).is_err());
    }
    #[test]
    fn production_queue_actual_overflow() {
        let mut queue = Queue::new(64, 1024 * 1024);
        for _ in 0..64 {
            queue.push(Event::new("changes", vec!["L3RtcC9h".into()], None));
        }
        assert_eq!(queue.retained().0, 64);
        queue.push(Event::new("changes", vec!["L3RtcC9i".into()], None));
        assert_eq!(
            queue.pop().unwrap().reason.as_deref(),
            Some("queue_paths_overflow")
        );
        assert_eq!(queue.retained().0, 64);
        let mut queue = Queue::new(64, 1024 * 1024);
        let event = Event::from_paths(&[PathBuf::from(format!("/{}", "a".repeat(16_000)))]);
        for _ in 0..50 {
            queue.push(event.clone());
        }
        assert!(queue.retained().2 <= 1024 * 1024);
        assert_eq!(
            queue.pop().unwrap().reason.as_deref(),
            Some("queue_bytes_overflow")
        );
        let mut queue = Queue::new(64, 1024);
        queue.push(Event::new("changes", vec!["A".repeat(2000)], None));
        assert_eq!(
            queue.pop().unwrap().reason.as_deref(),
            Some("queue_bytes_overflow")
        );
        assert_eq!(queue.retained().2, 0);
    }
    #[test]
    fn subscription_scope_is_not_recursive_parent() {
        let root = std::env::temp_dir()
            .canonicalize()
            .unwrap()
            .join(format!("watch-plan-{}", session().unwrap()));
        std::fs::create_dir_all(root.join("repo/app")).unwrap();
        let roots = vec![
            root.join("repo/app"),
            root.join("repo/missing/deep"),
            root.join("config.json"),
        ];
        let plan = subscription_plan(&roots).unwrap();
        assert_eq!(plan.get(&root.join("repo/app")), Some(&true));
        assert_eq!(plan.get(&root.join("repo")), Some(&false));
        assert_eq!(plan.get(&root), Some(&false));
        assert_eq!(plan.len(), 3);
        std::fs::remove_dir_all(root).unwrap();
    }
    #[test]
    fn synthetic_notify_loss_and_native_error_use_production_conversion() {
        let mut signal = notify::Event::new(notify::EventKind::Other);
        signal.attrs.set_flag(notify::event::Flag::Rescan);
        assert_eq!(
            native_event(Ok(signal), &[]).unwrap().reason.as_deref(),
            Some("need_rescan")
        );
        assert_eq!(
            native_event(Err(notify::Error::generic("test-only injected error")), &[])
                .unwrap()
                .reason
                .as_deref(),
            Some("native_error")
        );
    }
    #[test]
    fn byte_paths_and_sequence() {
        let path = decode_path(&STANDARD.encode(b"/tmp/a\xff\n")).unwrap();
        assert_eq!(path.as_os_str().as_bytes(), b"/tmp/a\xff\n");
        for bad in [
            STANDARD.encode(b"/a\0b"),
            STANDARD.encode(b"/a/../b"),
            "@@".into(),
        ] {
            assert!(decode_path(&bad).is_err());
        }
        let mut event = Event::new("changes", vec![], None);
        let mut seq = MAX_SEQUENCE - 1;
        event.wire("token", &mut seq).unwrap();
        assert_eq!(event.sequence, MAX_SEQUENCE.to_string());
        assert!(event.wire("token", &mut seq).is_err());
        assert_ne!(session().unwrap(), session().unwrap());
    }
}
