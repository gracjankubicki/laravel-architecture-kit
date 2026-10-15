# Architecture Kit analysis engine

This Rust executable uses Mago 1.57 to parse PHP without loading the analyzed project's autoloader or executing its code. PHP consumers receive the same neutral document from `PhpAnalysisEngine` or `MagoAnalysisEngine`.

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
