//! Bounded phase aggregates. Inclusive nested measurements must never be summed.
use serde::Serialize;
use std::{
    cell::RefCell,
    collections::BTreeMap,
    time::{Instant, SystemTime, UNIX_EPOCH},
};
#[derive(Default, Serialize)]
struct Aggregate {
    samples: u64,
    wall_ns: u64,
    cpu_user_us: Option<u64>,
    cpu_system_us: Option<u64>,
    cpu_unavailable_samples: u64,
    owned_capacity_before_last: Option<usize>,
    owned_capacity_after_last: Option<usize>,
    max_owned_capacity_endpoint: Option<usize>,
}
thread_local! {static AGGREGATES:RefCell<BTreeMap<&'static str,Aggregate>>=RefCell::new(BTreeMap::new());static ORIGIN:Instant=Instant::now();}
fn cpu() -> Option<(u64, u64)> {
    let mut ru = std::mem::MaybeUninit::<libc::rusage>::zeroed();
    if unsafe { libc::getrusage(libc::RUSAGE_SELF, ru.as_mut_ptr()) } != 0 {
        return None;
    }
    let ru = unsafe { ru.assume_init() };
    Some((
        ru.ru_utime.tv_sec as u64 * 1_000_000 + ru.ru_utime.tv_usec as u64,
        ru.ru_stime.tv_sec as u64 * 1_000_000 + ru.ru_stime.tv_usec as u64,
    ))
}
pub struct Span {
    phase: &'static str,
    start: Instant,
    cpu: Option<(u64, u64)>,
    before: Option<usize>,
    after: Option<usize>,
}
impl Span {
    pub fn unchanged(phase: &'static str, bytes: Option<usize>) -> Self {
        let mut span = Self::new(phase, bytes);
        span.after = bytes;
        span
    }

    pub fn new(phase: &'static str, before: Option<usize>) -> Self {
        ORIGIN.with(|_| ());
        Self {
            phase,
            start: Instant::now(),
            cpu: cpu(),
            before,
            after: None,
        }
    }
    pub fn finish(mut self, after: Option<usize>) {
        self.after = after
    }
}
impl Drop for Span {
    fn drop(&mut self) {
        let end = cpu();
        let wall = self.start.elapsed().as_nanos().min(u64::MAX as u128) as u64;
        AGGREGATES.with(|a| {
            let mut a = a.borrow_mut();
            let entry = a.entry(self.phase).or_default();
            entry.samples = entry.samples.saturating_add(1);
            entry.wall_ns = entry.wall_ns.saturating_add(wall);
            match (self.cpu, end) {
                (Some((bu, bs)), Some((au, as_))) => {
                    entry.cpu_user_us = Some(
                        entry
                            .cpu_user_us
                            .unwrap_or(0)
                            .saturating_add(au.saturating_sub(bu)),
                    );
                    entry.cpu_system_us = Some(
                        entry
                            .cpu_system_us
                            .unwrap_or(0)
                            .saturating_add(as_.saturating_sub(bs)),
                    )
                }
                _ => entry.cpu_unavailable_samples += 1,
            }
            entry.owned_capacity_before_last = self.before;
            entry.owned_capacity_after_last = self.after;
            for v in [self.before, self.after].into_iter().flatten() {
                entry.max_owned_capacity_endpoint =
                    Some(entry.max_owned_capacity_endpoint.unwrap_or(0).max(v))
            }
        })
    }
}
pub fn report() -> serde_json::Value {
    AGGREGATES.with(|a|serde_json::json!({"clock":"process_monotonic_instant","anchor_process_elapsed_ns":ORIGIN.with(|o|o.elapsed().as_nanos().min(u64::MAX as u128)as u64),"anchor_unix_ns":SystemTime::now().duration_since(UNIX_EPOCH).ok().map(|d|d.as_nanos().min(u64::MAX as u128)as u64),"pid":std::process::id(),"cpu_source":"getrusage_self_delta","cpu_units":"microseconds","wall_units":"nanoseconds","overlap":"inclusive_nested_do_not_sum","capacity_kind":"owned_structure_capacity_endpoints_not_rss_or_phase_peak","unavailable":"null with cpu_unavailable_samples; absent capacities NOT_AVAILABLE at this boundary","phases":&*a.borrow()}))
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn bounded_phase_deltas_have_units_and_endpoint_capacity() {
        let p = Span::new("measurement_test", Some(17));
        p.finish(Some(29));
        let r = report();
        let p = &r["phases"]["measurement_test"];
        assert!(p["wall_ns"].as_u64().unwrap() > 0);
        assert_eq!(p["owned_capacity_after_last"], 29);
        assert_eq!(p["samples"], 1);
        assert_eq!(r["cpu_units"], "microseconds");
        assert_eq!(r["overlap"], "inclusive_nested_do_not_sum");
    }
}

static FULL_SCANS: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);
pub fn accounting_scan() {
    FULL_SCANS.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
}
pub fn accounting_scans() -> u64 {
    FULL_SCANS.load(std::sync::atomic::Ordering::Relaxed)
}
