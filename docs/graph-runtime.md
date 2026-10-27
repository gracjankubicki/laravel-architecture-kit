# Native Basic map

Standalone CLI and MCP can use Rust to store, compose and query the Basic map. This is an explicit candidate. PHP remains the default until large-project parity and performance acceptance are verified.

## Select the representation

Add the field to the selected shared settings or local profile, preserving its other settings:

```json
{
  "graph_runtime": {
    "representation": "rust"
  }
}
```

Set `representation` to `php` to select the existing PHP map. Omission also selects PHP. Unknown values are errors. `analysis.engine=php|mago` selects the parser independently. Choosing Rust does not require Mago when the PHP parser is explicitly selected.

Selection changes the map's identity. An old representation's result or cache is not reused as the new representation. Restart loaded MCP processes after upgrading the tool. Existing configuration inheritance and explicit profile selection retain their normal precedence.

The Rust candidate requires native runtime 0.2.0 with the graph capabilities. Install its matching published release explicitly outside the inspected repository:

```sh
architecture-kit runtime install
architecture-kit runtime status
```

Use the same `--tool-home` as MCP. Installation requires the matching published artifacts; an unpublished candidate cannot be fetched by these commands. Users do not need a Rust compiler. Version 0.2.0 occupies a separate installation directory beside watcher-only 0.1.0. An old watcher can support observation, but cannot supply the graph capability. Missing or incompatible graph support returns an explicit advisory error. Normal queries never download a binary or silently select PHP.

## Responsibilities

| Stage | Implementation |
|---|---|
| Settings, repository binding, CLI and MCP | PHP |
| Static source parsing and local facts | Selected PHP parser or separate Mago executable |
| Basic graph storage, relation composition, dependency updates and search | Rust runtime |
| Normal Basic search, context, path, impact and candidate-test queries | Rust with bounded response pages |
| Architecture rules and legacy signature, delete and move assessments | PHP with temporary bounded native projections |
| Deep analysis | Existing separate path, with its normal consent requirements |

The normal native map path does not recreate a resident `CatalogIndex` in PHP. Rules that need structural data and proposed-change assessments can read a temporary projection under the same generation and memory admission. Those costs remain PHP audit or assessment work, rather than being reported as native query work. The projection is released after the request.

## Lifecycle and cache

The existing `map prepare`, `map status JOB_ID`, `map result JOB_ID` and MCP `architecture-map` lifecycle remains available. A pending job lets the agent continue source inspection and collect the result later. Accepting a background job does not satisfy its latency target.

Rust retains one identified generation for each query. New generations do not change the records of a query already in progress. With a usable disk snapshot, a fresh worker can restore or patch a new generation while a query finishes on the old one. With cache disabled or no usable snapshot and a live RAM base, the owner serializes the update after its active query. This serialized path does not claim concurrent publication. Time spent waiting still counts toward update latency.

Pagination is separate from a generation lease. A replaced generation's cursor returns `stale_snapshot`; restart from the first page. Reading changed source bytes against an old generation reports the mismatch instead of attaching those bytes as old evidence. `last_confirmed` is an observation claim, not proof of current contents or complete analysis.

Disabling disk cache does not disable the live RAM map or selective updates. A ready map and a successful cache write remain independent outcomes. Cache refusal, corruption or interrupted writes retain explicit reasons. Restore validates identity, scope and canonical file identities before accepting the candidate. It does not reparse application sources to reconstruct the native map. A rejected snapshot is not presented as ready or silently converted to a PHP map.

The existing 900-second idle retention, shared FIFO queue, LRU retirement, epochs and advisory behavior still apply. Active queries can finish before retirement releases their generation. Neither map selection nor configuration inheritance grants Deep, application execution, repository writes or automatic audits.

## Memory admission and verification limits

Resident and transition ceilings remain global to the selected tool home. Admission accounts for unique observed processes and reserved native capacity, including active leases and temporary projections. Unknown RSS produces an explicit refusal rather than an assumption of free memory. Native work preflight computes its own capacity requirements; PHP does not maintain a second copy of those formulas. The tool does not increase PHP's `memory_limit` after failure.

The PHP transport checks frame bytes before reading the body, then reserves memory for strings and structural tokens before JSON decoding. A long scalar string is not charged as millions of PHP array entries. Dense arrays and objects still require container headroom and can be refused before decoding. A public record that exceeds the response page budget reports its explicit reason instead of creating an empty continuation loop.

Owned-capacity counters, current RSS, lifetime peaks and phase timings describe different measurements. A counter for graph storage is not a complete process RAM measurement. Short phases can fall between RSS samples. Inspect the reported phase, units and measurement basis before comparing results.

Local synthetic parity and lifecycle tests do not establish large-application capacity. Full end-to-end acceptance remains open for cold and major-change results within 20 seconds, disk restore within 2 seconds, single-file updates within 3 seconds and ready queries within 100 milliseconds. The four native targets are macOS ARM64 and x64, and Linux ARM64 and x64. A configured CI matrix or cross-build does not prove actual execution on all four targets. Windows is outside this distribution.

Basic keeps its existing static source scope, vendor boundary and explicit uncertainty. A current map can still contain incomplete analysis. Candidate tests do not prove passing tests, coverage or business correctness. Advisory results allow the agent to keep working and explain remaining uncertainty.
