# Architecture Kit analysis engine

Engine 0.2.0 uses Mago 1.57.0 to parse PHP without loading the analyzed project's autoloader or executing its code. Basic graph extraction can return file-local facts directly through `basic-file-facts-v1`. Other consumers retain the neutral syntax contract supported by both `PhpAnalysisEngine` and `MagoAnalysisEngine`.

## Install the matching engine after a package upgrade

Package v0.11.0 requires engine 0.2.0. After the matching `engine-v0.2.0` artifacts are published, use the upgraded tool to install them:

```sh
architecture-kit engine install
architecture-kit engine status
architecture-kit --project /absolute/path/project engine describe
```

Use the selected external tool home consistently and restart existing MCP sessions after upgrading. Composer does not install the binary, and engine 0.1.0 lacks the new Basic capability. The adapter rejects a missing, incompatible or failed engine without silently switching to PHP. Explicit `analysis.engine=php` remains available.

The tool process requires PHP 8.3 or newer. Published binary targets cover macOS and Linux on ARM64 and x64; Linux binaries use static musl linking. Rust is required only to build from source. The standalone background map runtime additionally requires Unix-domain sockets and PHP process-launch support, as described in [STANDALONE.md](../STANDALONE.md#prepare-and-collect-a-basic-map).

## Build and test locally

```sh
cargo test --manifest-path engine/Cargo.toml --locked
cargo build --release --manifest-path engine/Cargo.toml --locked
ARCHITECTURE_KIT_TEST_ENGINE="$PWD/engine/target/release/architecture-kit-engine" \
  php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Analysis
ARCHITECTURE_KIT_TEST_ENGINE="$PWD/engine/target/release/architecture-kit-engine" \
  ARCHITECTURE_KIT_SUITE_ENGINE=mago \
  php -d memory_limit=1G vendor/bin/phpunit
```

The tool runtime still requires PHP. Rust is a build dependency for contributors, not an installation requirement for users. A running analysis pins the binary hash. Rebuilding or replacing the executable during a test or MCP session invalidates that session.

## Protocol reference

`--version` writes one JSON object containing the engine, binary version, Mago version, schema and capabilities. Normal operation reads JSON Lines from stdin and writes one response per request. Stderr is reserved for process diagnostics.

Engine 0.2.0 advertises `basic-file-facts-v1` alongside its syntax capabilities. Basic requests carry source, engine, configuration and prepared-source mapping identities. Responses contain file-local declarations, edges, candidate test invocations, optional catalog/impact facts and diagnostics. The PHP consumer validates identities and source locations before composing project-level relationships. Internal syntax does not leave Rust for this product; it is not a replacement syntax document for portable audit rules.

`basic-file-facts-batch-v1` carries up to 32 file requests in a batch bounded to 4 MiB. A single larger source retains the existing 32 MiB request limit through the single-file protocol. The adapter accounts stdout and stderr against one 64 MiB response budget. Partial output is drained without adding a sleep after every successful read; an exceeded budget or invalid frame poisons the session and closes its process without an implicit retry.

Requests contain `schema`, `path`, the complete `source` snapshot, its `source_hash` and the concrete `php_version`. Responses repeat that identity and contain facts, diagnostics and syntax. The PHP adapter rejects malformed output, mismatched identities, out-of-range locations, unsupported schemas and unexpected extra output. A failed session does not retry with another engine.

Schema 1 supports the `syntax-flat-v1` encoding. `syntax.nodes` contains postorder node records and `syntax.roots` contains their references. Each child reference is an object with a `$node` index pointing to an earlier record. The PHP codec reconstructs only neutral `Analysis\Syntax\Node` objects. It rejects forward references and enforces node and depth budgets. Flat transport avoids JSON nesting limits for long receiver chains and repeated copies of whole subtrees. Responses are limited to 64 MiB.

Neutral nodes use the shared schema's field order and reuse its kind and field-name strings. JSON key order cannot change traversal, and repeated wire strings do not increase retained syntax memory for every node. Bounded analysis can still stop at the memory or source budget. Such notices remain incomplete results, with the specific reason and source location.

`syntax_error` contains a project syntax or compilation failure with available byte offsets. `unsupported_syntax` identifies valid grammar that the neutral adapter cannot represent. Both withhold recovered facts. Consumers preserve this distinction. Dynamic calls and unavailable dependency bodies continue to use the existing unresolved-analysis reports; they are not automatically engine failures.

Mago 1.57's semantic checker also reports `$this` in static closures as an error, although PHP accepts the file and fails only if that code executes. It also incorrectly rejects `final` trait aliases introduced in PHP 8.3. The adapter excludes only those specific misclassified messages for the appropriate language target. Contract tests preserve valid analysis and rejection on older targets.

## Extend the neutral syntax model

Add an expected-result scenario to the portable corpus, update the shared schema and both adapters, then update the consumer. Do not expose PhpParser nodes or Mago CST to consumers. Test namespace imports, trait adaptations, locations, comments and source identity where the change affects them. A new engine capability requires a descriptor change and compatibility checks.

Legacy custom rules keep their PhpParser interface only under explicit PHP. New portable rules declare `PortableAuditRule` and read `FileContext::syntax()`. They must not call the legacy AST interface.

## Prepare binary artifacts

`.github/workflows/engine.yml` builds and tests macOS ARM64, macOS x64, Linux ARM64 and Linux x64 on matching runners. Linux executables use static musl linking, with a check that no dynamic loader is required. The workflow prepares a combined candidate artifact and manifest; it does not publish a release.

`release-manifest.py` requires every platform artifact and its version metadata, verifies the shared version/schema/capabilities and calculates sizes and SHA-256 hashes. Users install the matching published `engine-v<version>` artifacts through `architecture-kit engine install`. Publishing those binaries and the Composer release requires separate authorization. All matching binaries must be available before a Composer release that defaults to Mago.

Native Basic facts and resident graph generations do not establish end-to-end latency guarantees. The 20-second cold, 2-second restore, 3-second incremental and 100 ms ready-query targets remain open for v0.11.0. Source verification and PHP composition remain part of the work even when native facts or prepared state are reused.
