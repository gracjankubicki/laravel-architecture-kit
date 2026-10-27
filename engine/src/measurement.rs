//! Opt-in process-self accounting. Clocks from separate processes have separate anchors.
use serde_json::{Value, json};
use std::sync::OnceLock;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

static CLOCK: OnceLock<(Instant, u128)> = OnceLock::new();
fn sample() -> Value {
    let clock = CLOCK.get_or_init(|| {
        (
            Instant::now(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap_or_default()
                .as_nanos(),
        )
    });
    #[cfg(unix)]
    let cpu = {
        let mut usage = std::mem::MaybeUninit::<libc::rusage>::uninit();
        // RUSAGE_SELF excludes children. Initialized only when the syscall succeeds.
        if unsafe { libc::getrusage(libc::RUSAGE_SELF, usage.as_mut_ptr()) } == 0 {
            let usage = unsafe { usage.assume_init() };
            json!({"user_us": usage.ru_utime.tv_sec as i64 * 1_000_000 + usage.ru_utime.tv_usec as i64,
                "system_us": usage.ru_stime.tv_sec as i64 * 1_000_000 + usage.ru_stime.tv_usec as i64,
                "scope":"self", "unit":"us"})
        } else {
            json!({"status":"NOT_AVAILABLE","reason":"getrusage_failed"})
        }
    };
    #[cfg(not(unix))]
    let cpu = json!({"status":"NOT_AVAILABLE","reason":"unsupported_platform"});
    json!({"monotonic_ns": clock.0.elapsed().as_nanos() as u64, "anchor_unix_ns":clock.1.to_string(),
        "pid":std::process::id(), "instance":format!("{}:{}",std::process::id(),clock.1),"role":"mago-engine","cpu":cpu})
}

pub struct Recorder {
    enabled: bool,
    pub events: Vec<Value>,
    batch: Value,
    request: Value,
}
impl Recorder {
    pub fn new(request: &Value) -> Self {
        Self {
            enabled: request["measurement"].is_object(),
            events: Vec::new(),
            batch: request["measurement"]["batch"].clone(),
            request: request["measurement"]["request"].clone(),
        }
    }
    pub fn point(&mut self, phase: &str, event: &str) {
        if self.enabled {
            self.events.push(json!({"phase":phase,"event":event,"batch":self.batch,"request":self.request,"sample":sample()}));
        }
    }
    pub fn attach(mut self, result: &mut Value) {
        if self.enabled {
            for phase in ["parse", "names", "semantics", "ir", "facts"] {
                if !self.events.iter().any(|event| event["phase"] == phase) {
                    self.events.push(json!({"phase":phase,"event":"unavailable","status":"NOT_AVAILABLE","reason":"not_reached_for_source","batch":self.batch,"request":self.request,"sample":sample()}));
                }
            }
            result["trace"]["measurement"] = json!(self.events);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn counters_have_clock_instance_and_self_cpu() {
        let a = sample();
        let b = sample();
        assert_eq!(a["instance"], b["instance"]);
        assert!(b["monotonic_ns"].as_u64().unwrap() >= a["monotonic_ns"].as_u64().unwrap());
        #[cfg(unix)]
        assert_eq!(b["cpu"]["scope"], "self");
    }
}
