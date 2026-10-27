//! Experimental parser-independent store, Basic composition and bounded graph queries.
pub mod model;
pub mod protocol;
pub mod snapshot;
pub mod store;
pub type Result<T> = std::result::Result<T, String>;
pub const PROTOCOL: &str = "graph-store-v1";
pub const RECORD_BYTES: usize = 16 * 1024 * 1024;
pub const FRAME_BYTES: usize = RECORD_BYTES + 64 * 1024;
pub const CONTROL_BYTES: usize = 4 * 1024 * 1024;
pub const PAGE_RECORDS: usize = 32;
pub const SCHEMA: &str = "native-graph-snapshot-v1";

#[cfg(test)]
mod tests;

pub mod compose;
pub mod dependency;
pub mod measure;
pub mod query;
pub mod resolve;
pub mod search;

/// Independent of source/page batching; byte admission remains the primary bound.
pub const BASIC_SNAPSHOT_PARTS: usize = 4096;
