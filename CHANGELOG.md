# Changelog

All notable changes to `gracjankubicki/laravel-architecture-kit` will be documented in this file.

## v0.6.1 - 2026-09-23

### Added

- Support `laravel/mcp` 1.x while retaining support for 0.8 and 0.9.

### Fixed

- Adapt MCP protocol and stdio tests to the APIs exposed by Laravel MCP 0.x and 1.x.
- Recognize `Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader` on real MCP routes without hiding unresolved vendor middleware.

## v0.6.0 - 2026-09-22

### Added

- Separate non-blocking `suggestions` and `analysis` channels from enforced audit findings in CLI, `--agent`, `--json`, and MCP output. Suggestions include placement, reason, trace, and route identity; analysis notices identify unresolved code and limits without changing error or warning counts.
- Preserve distinct route contexts and case-insensitive method identity, and improve framework semantics for collections, query callbacks, user guards, dates, API Resources, and connection serialization.

### Changed

- A write reached through GET or HEAD is no longer an error or warning by itself. Existing enabled rules still enforce their boundaries, while incomplete endpoint analysis remains visible and non-blocking in `--strict`.

## v0.5.0 - 2026-09-19

### Added

- Add an optional Fortify 1 architecture profile with first-install detection, explicit selection, fail-closed compatibility diagnostics, generated guidance, MCP output, and contract-aware audit checks. Native `create()`, `reset()`, `update()`, and `toResponse()` extension points remain valid without disabling whole-folder rules; confirmed registration mismatches are errors and dynamic targets remain explicit strict-blocking warnings. Architecture Kit does not install Fortify or change authentication configuration and the profile remains independent from Inertia.
- Add an optional Inertia 3 architecture profile with first-install detection, explicit selection, compatibility diagnostics, generated guidance, MCP output, and two audit checks. Actions and Query Objects cannot depend on Inertia, and page props cannot receive an unfiltered request. Architecture Kit does not install or update Inertia, and the existing endpoint and missing-test analysis keeps its Inertia semantics when the profile is disabled.
- Add the verified `laravel-ai@0.11` profile and the atomic `0.10 -> 0.11` upgrade guide. Install, sync, doctor, planning, MCP, and the real-package CI matrix now cover Laravel AI 0.8 through 0.11, including prompt, stream, structured-output, and version-specific queued fake behavior without contacting a provider.

### Fixed

- Recognize supported Laravel, Inertia 3, and Fortify 1 calls in endpoint and test-reachability analysis. The v0.5.0 implementation reported explicit session writes on GET or HEAD routes and dynamic framework dispatch through strict-blocking warnings. The v0.6.0 policy preserves the effects but moves placement advice and unresolved analysis into `suggestions` and `analysis.notices`.
- Recognize factories used by models extending Laravel’s standard Auth User, including aliases and local intermediate classes.
- Recognize direct Laravel AI agent and file operations through SDK contracts, aliases, typed receivers, assignments, and bounded fluent chains instead of class-name suffixes. Unrelated `prompt()` methods and project-owned `Tool` interfaces stay clean, while unresolved calls on confirmed SDK symbols report `W_LARAVEL_AI_ANALYSIS_INCOMPLETE` and block strict mode.

## v0.4.1 - 2026-09-16

- Resolve Laravel HTTP and factory test relationships for missing-test without crediting unrelated methods. Report incomplete analysis as a warning that blocks strict guard, and clarify that static relationships do not prove execution or assertion quality.

### Fixed

- Clarify that Saloon owns application-written direct HTTP while appropriate official SDKs keep HTTP or gRPC inside provider adapters. Generated guidance now separates Action tests from adapter tests and requires an SDK isolation seam for transport, credentials, authentication, and token refresh. The audit allows integration DTO mapping inside infrastructure adapters, rejects those DTOs in Port signatures, and reports HTTP adapters that bypass an available Port without granting a general `Client` exception.
- Make Thin Controller Service dependency findings route-aware. Read endpoints may use the enabled read boundary, writes retain the Action advisory, and imports/unused injection no longer produce duplicate findings. Constructor dependencies are attributed to endpoint usage.
- Detect supported database writes and side effects through bounded reachable method analysis on GET/HEAD endpoints, with call-chain evidence. Unresolved routes/calls and exhausted limits report a distinct warning rather than claiming a safe read. Fresh route discovery ignores stale route cache only in its child process; changed dependencies recheck affected controllers.
- Align generated read-flow guidance with the Services fallback and document static-analysis limits. Existing direct controller validation/write/transaction/dispatch checks and output schemas are preserved.

## v0.4.0 - 2026-09-12

This release is about the moment before an agent writes code and the moment after it does. It answers which rules govern a file that does not exist yet, scaffolds that file so it passes the audit, reports what a change to a symbol would break and which tests cover it, and explains a finding against the symbol at fault rather than restating the rule. The audit can now read outside `app/`, where an agent could previously hide logic and leave the gate green. The project graph is kept between runs, which takes `guard --changed` from 6.44s to 0.47s on a large application.

A project that changes no configuration gets exactly the audit it had before: the new scope and the `missing-test` rule are both opt-in. The graph cache is on by default because it changes how long an answer takes, not what it says. See [UPGRADE.md](UPGRADE.md) before updating.

### Added

- Added `architecture-kit:file-rules` and the read-only `file-rules` MCP tool, which return only the rules that govern one path instead of the full guideline. The path does not have to exist yet, which is the point: the question is asked before the file is written. Each architecture is reported as `enforced` or `advisory`, so a green guard is no longer mistaken for full compliance, and as `governs` or `shared`, so it is clear which guideline actually describes the file. Custom project rules are reported in a separate `project` list, and project-wide rules such as `layer-dependency` and `namespace-cycle` are reported as always active.
- Added `architecture-kit:make` and the read-only `scaffold` MCP tool, which emit the files, folder, namespace, naming, and base classes a new element of an enabled architecture needs. The interactive command writes the files and refuses to overwrite an existing one; `--agent` and the MCP tool write nothing and leave that decision to the agent. Skeletons are built to pass this package's own audit, and a test verifies that for every supported architecture. A name is rejected before anything is planned when it is not a plain PHP identifier, when it would escape the architecture folder, when it is a reserved PHP word, or when its suffix marks a different kind of class. The rejected suffixes are read from the audit rules themselves, so the generator cannot drift from what the audit accepts.
- Added a configurable audit scope. `audit.paths` in `config/architectures.php` lets a project have the audit read directories outside `app/`. Until now every file outside `app/` was invisible, so business logic closed inside a route file left the gate green for no reason other than where the file was saved. The new `route-logic` rule reports inline validation, a direct model write, a transaction, and a dispatch inside a route definition, using the signals the package already applies to controllers rather than a second definition of the same idea.
- Added the `missing-test` rule, which reports an architecture element that no test depends on. It is controlled by `audit.missing_test` in `config/architectures.php`, one of `off`, `warn`, or `error`, and defaults to `off`, so a project that does not change its configuration gets exactly the audit it had before upgrading. The rule reads the dependency graph instead of a naming or folder convention, so it covers every architecture wherever its elements live, and a test that reaches an element through another class still counts. Interfaces and traits are exempt, and an enum is reported only when it declares methods. Enabling the rule also brings `tests/` into the audited scope and keeps test files in the graph even when an exclusion pattern matches them, because without them the rule would report every class as untested. Rules written for application code never fire inside test files.
- Added the tests covering a symbol to `architecture-kit:context` and the `architecture-context` MCP tool, including coverage reached through another class, with a ready `run_tests:` hint in `next`. An agent can run a narrow relevant set before the full suite instead of learning the effect from a red run. Coverage is resolved by filtering files on the class's short name and parsing only the matches, so the answer costs seconds rather than a full graph build.
- Added an optional occurrence to `architecture-kit:explain` and the `explain-finding` MCP tool through `--path` and `--line`. The explanation then names the symbol at fault, resolved from the project graph rather than from rule messages, and states the remedy against that element. Where the rule names a destination, the answer carries a `proposal` describing what to move and where; it is text to apply or reject, and the package still edits nothing. Calls without a path keep the previous output.
- Added a project graph cache, on by default, so a run parses only the files that moved. `guard --changed` used to pay a full graph build on every run even though its rules check a handful of files, because a changed edge can reveal a cycle through an untouched one. Measured on an application with 11566 files: `guard --changed` 6.44s to 0.47s and `context` 19.63s to 0.75s, with the answer unchanged. A full audit is unaffected, since its rules need the syntax tree of every file they check. An entry is discarded when the enabled architectures, custom rules or audited scope change, and when the package sources change, which is hashed rather than tracked by a constant. A file is parsed again when its modification time or size moves; the run follows the files the project has, so a deletion leaves the graph and a contribution missing from an entry is rebuilt rather than assumed present. File state is stat rather than a content hash, which costs 0.02s against 1.58s on every run. An entry that would not fit in the remaining memory budget is skipped rather than allocated, in both directions: restoring peaks at 5.7x the stored file and writing adds to that, and the write is sized from the graph itself so a first write under a new fingerprint is checked too. `audit.cache` in `config/architectures.php` turns it off or moves it, the default location is inside `storage/`, and `architecture-kit:cache-clear`, with `--path=` for a location the configuration no longer names, removes it. The stored file carries a hash of its own contents, because a damaged entry can still be well formed: one stripped of its symbols is indistinguishable from a file that declares none. A corrupt or oversized entry rebuilds instead of failing and is reported by `audit`, `guard` and `context`: `--agent` output carries a `cache` field, declared in the published schema, and human output prints a note.

### Changed

- Changed the project graph to record a stand-in symbol for a file that declares no class of its own, so its dependencies become visible. Classless Pest tests and route files previously contributed nothing to the graph. Application files under `app/` are unaffected, so layer and namespace-cycle findings are unchanged.
- Changed `architecture-kit:context` to read the audited project scope instead of always reading `app/`. A project that widened `audit.paths` was getting findings for a route file from the audit while the context reported nothing depending on the symbol used there.
- Changed relationship ordering in `architecture-kit:context` from alphabetical to an `impact` level derived from the recorded edge: `breaking` for inheritance and contracts, `signature` for types in a signature, `usage` for executable references, `context` for weak ones. `--limit` now cuts the least dangerous relationship first; previously a subclass that stops loading could fall out of the answer while a passing type reference stayed in it. Each relationship carries its level in the payload.
- Changed graph ordering to sort on precomputed keys instead of comparing arrays in a callback, which takes the edge sort on a large project from 0.61s to 0.09s. The order is unchanged: numbers are zero-padded so they still compare numerically, and a test asserts the result against the comparison it replaced. Edge deduplication moved to the file that produces the edges, where it always belonged, since the key includes the path and two files could never collide.

### Fixed

- Fixed project-relative path resolution so `.` and `..` segments are collapsed before a path is compared with the project directory. `app/../routes/api.php` is no longer treated as an application file by file-scoped guidance.
- Fixed `FolderPurityRule::supports()` reporting architecture-scoped folders regardless of configuration. It now mirrors the gating already applied in the rule's checks, so file-scoped guidance no longer promises a folder purity finding for `app/Services`, a Value Object folder, or `app/Models/Builders` while the matching architecture is disabled. Audit output is unchanged: the skipped check returned nothing in that configuration, and unparseable files are reported before rule support is consulted.

## v0.3.0 - 2026-09-11

This release contains no new features. It fixes correctness and reliability problems in the audit, the guard, and the generated agent hook. Three fixes can surface as new failures in a project that changed nothing on its side: a previously silent audit may start reporting findings, a stale inline suppression is now reported as a warning that fails `guard --strict`, and `audit --changed --update-baseline` is rejected. See [UPGRADE.md](UPGRADE.md) before updating.

### Fixed

- Fixed relative path normalization so audit, graph, doctor, and planner strip only the project directory prefix. A project whose base path ends with a segment that repeats inside file paths, such as `base_path()` equal to `/app`, no longer loses the `app/` prefix and no longer reports a silently green audit.
- Fixed audit memory growth by releasing each file's AST immediately after its rules run and its symbols and dependencies are accumulated into the project graph. The graph is now built in a single streaming pass, and the audit aborts with an explicit message when the remaining memory budget is insufficient instead of ending in a fatal error.
- Fixed the syntax-tree memory estimate that guards a single oversized file. It is now based on token count, which tracks node count closely, instead of source size alone, whose cost per byte varies by a factor of 448 between a long string literal and a dense array. A byte-based fast path still skips the extra work for files that fit under any measured density.
- Fixed `architecture-kit:audit` accepting `--changed` together with `--update-baseline`, which rewrote the baseline from a narrowed scope and silently dropped suppressions for files outside it. The combination is now rejected in both human and agent output.
- Fixed external process execution in audit and doctor by replacing `exec()` with `Symfony\Component\Process\Process`, so an unavailable binary or a runtime that blocks process execution degrades to the documented fallback instead of raising an unhandled error.
- Fixed unhandled audit exceptions on the guard path: `architecture-kit:guard` and the `guard` and `audit-changed` MCP tools now return a structured, readable failure instead of propagating the exception to the agent hook.
- Fixed the generated agent hook for applications located in a repository subdirectory. The `guard.sh` script resolves the project directory from its own location, and the Codex hook command prefers the current project directory before falling back to the repository root.
- Fixed inline suppression matching so a directive written in a multi-line docblock applies to the findings it covers, and so one comment can suppress several rules. A known suppression that matches no finding is now reported as an `invalid-suppression` warning instead of being silently ignored.
- Fixed the missing `Architecture` import in `ArchitectureResourceManifest`, so its `array<int, Architecture|string>` parameter contract resolves to the real enum instead of a class that does not exist in that namespace.
- Fixed dead code and imprecise contracts reported by static analysis: `SaloonRule` no longer takes a filesystem and base path it never used, two unused methods and several always-true guards are gone, optional project state is now expressed as an explicit null check, node-set searches no longer build a throwaway namespace node, and every array contract in the package declares its element type.

### Added

- Added PHPStan static analysis for the package source, wired into the `lint` CI job and available as `composer stan`. The analysis passes with no baseline and no disabled rules, so any new finding fails the build.

### Changed

- Changed the `SaloonRule` constructor to take no arguments. The rule never used the injected filesystem or base path. Code that constructed it directly must drop both arguments; the documented extension point for project-specific rules is unaffected.

## v0.2.6 - 2026-07-25

### Fixed

- Fixed project graph role classification for domain-first folders by using the first recognized architecture segment, so nested Actions, Services, domain types, HTTP adapters, infrastructure, and providers participate in layer and port-bypass findings without an inner folder overriding its outer layer.
- Fixed missing strong dependency edges for class constants and enum cases while keeping `::class` and Eloquent relations weak and context-only.
- Fixed architecture context truncation metadata when the combined inspect-path list or a zero limit hides relevant files.
- Fixed Architecture Doctor incorrectly reporting generated atomic Laravel AI upgrade guides as stale while an enabled but unsupported Laravel AI profile blocks dependent resource generation.
- Fixed Architecture Planner treating enum-like text in comments, docblocks, strings, or invalid PHP as evidence of real enum declarations.
- Fixed agent installation accepting unrelated MCP servers under reserved Architecture Kit keys; valid existing JSON/TOML integrations remain byte-for-byte, while incompatible collisions now block without writes.

## v0.2.5 - 2026-07-25

### Added

- Added a deterministic static project architecture graph with evidenced strong and weak PHP dependencies across non-excluded `app/**/*.php` files.
- Added `architecture-kit:context` and the read-only MCP `architecture-context` tool so AI agents can inspect one exact symbol's role, direct dependencies, dependents, violations, files, and next guard before changing code.
- Added graph-aware `E_PORT_BYPASS`, `E_LAYER_DEPENDENCY`, and `W_NAMESPACE_CYCLE` findings with finding explanations, changed-scope support, inline suppression, baseline suppression, and agent output.

### Changed

- Changed-only audit now builds the full application graph for cross-file correctness while keeping file rules and reported graph evidence focused on changed source files.
- Ports and Adapters audit and graph rules now share one conservative role and port classifier.

## v0.2.4 - 2026-07-23

### Added

- Added read-only package upgrade planning across local atomic guides, with Composer state validation, unique multi-step route resolution, one active AI step, human CLI output, versioned agent JSON Schema, and an MCP tool.

## v0.2.3 - 2026-07-23

### Added

- Added versioned, evidence-first AI upgrade skills for `laravel/ai 0.8 -> 0.9` and `0.9 -> 0.10`, including sequential routing for `0.8 -> 0.10`, applicability checks, verification requirements, and requirement-evidence handoffs.
- Added a content-first upgrade-guide resource module that validates package/version metadata and distributes future package transitions through the existing marker-owned skill lifecycle.
- Added the verified `laravel-ai@0.10` architecture profile with participant authorization, approval resumption, and human-in-the-loop Tool guidance.

### Changed

- Laravel AI compatibility now supports `>=0.8.0 <0.11.0`; `0.11`, `1.x`, and development branches remain fail-closed.
- Install, plan, sync, doctor, Boost composition, and CI contract coverage now include versioned upgrade skills and real Laravel AI `0.10.0`/`^0.10` packages.
- CI now fails when Composer reports a security advisory for the resolved dependency set.

## v0.2.2 - 2026-07-16

### Added

- Added read-only `architecture-kit:plan` with evidence-backed architecture recommendations, requirement diagnostics, predicted managed-resource changes, human output, and a versioned `--agent` JSON schema.
- Added black-box Testbench smoke coverage for the package's real `doctor`, `audit`, and `plan` commands.

### Fixed

- Corrected the README installation contract to consistently describe Architecture Kit as a runtime dependency.
- Registered the Architecture Kit and Laravel MCP providers in the package workbench so public commands are available from the repository checkout.

## v0.2.1 - 2026-07-14

### Fixed

- Agent-mode sync now reports success only after managed resource writes complete and returns a deterministic `E_SYNC_APPLY` payload when filesystem mutation fails.
- Runtime dependency validation now rejects Architecture Kit or Laravel AI when an existing Composer lockfile places the package only in `packages-dev` or omits it from runtime `packages`.

## v0.2.0 - 2026-07-14

### Added

- Explicit, independently tested Laravel AI `0.8` and `0.9` compatibility profiles with stable generated paths and profile/version provenance.
- `architecture-kit:sync` for deterministic non-interactive regeneration, dry-run planning, agent JSON/schema output, managed-file cleanup, and preflight-before-write behavior.
- Composer inventory diagnostics covering root dependency placement, declared constraints, installed metadata, lock consistency, unsupported versions, and referenced capabilities.
- CI contract jobs for real Laravel AI boundaries, a consuming Laravel application installed with `--no-dev`, and Boost exact-once skill composition.

### Changed

- Architecture Kit must be installed in the consuming application's root runtime `require`; dev-only placement now blocks install, doctor, and sync with migration instructions.
- Laravel AI must be a direct runtime dependency whose complete constraint fits `>=0.8.0 <0.10.0`. Unknown or future lines fail closed.
- Structured response guidance uses `toArray()` or ArrayAccess. The Laravel AI 0.9 profile uses the renamed `withProviderOptions()` API where provider options are shown.
- Recurring Boost synchronization now uses normal `boost:update --no-interaction`; third-party discovery is reserved for one-time enrolment.

### Upgrade

- See `UPGRADE.md` for the runtime dependency migration, Laravel AI support matrix, profile regeneration, and Boost flows.

## v0.1.10 - 2026-07-10

### Changed

- Ports And Adapters now requires a meaningful boundary reason in PHPDoc without imposing an EN/PL format. Projects that require bilingual documentation can enforce it with an architecture-scoped custom audit rule.
- README and Composer metadata now distinguish generated guidance, deterministic audit rules, and the optional guard gate.
- CI now uses read-only permissions by default, tests both latest and lowest supported dependencies, and grants write access only to the badge update job after the full test and coverage chain succeeds.
- MCP configs, agent hook configs, the guard script, and its README are now bootstrapped once and remain developer-owned. Reinstallation and doctor preserve valid customizations instead of repairing them back to package defaults.

### Distribution

- Composer archives exclude repository artwork, the coverage badge generator, the package workbench, tests, plans, review artifacts, dependency installs, IDE metadata, and other development-only files.

### Removed

- Removed the pass-through `architecture-kit:install-hooks` compatibility command. Use `architecture-kit:install-agents --hooks` as the single hook installation interface.

## v0.1.9 - 2026-07-08

### Changed

- Replaced the Codecov README badge with a repository-local coverage badge generated from PHPUnit Clover output.
- GitHub Actions now updates only `art/coverage.svg` when coverage changes on `main`.

## v0.1.8 - 2026-07-08

### Changed

- Codecov upload no longer fails the GitHub Actions run before the repository is activated in Codecov.

## v0.1.7 - 2026-07-08

### Fixed

- Fixed Codecov upload authentication in GitHub Actions by using OIDC for the dedicated coverage job.

## v0.1.6 - 2026-07-08

### Added

- README now includes a generated project banner and package status badges for tests, coverage, Packagist, downloads, license, PHP, Laravel, and MCP.
- GitHub Actions now includes a dedicated coverage job that uploads Clover coverage to Codecov.
- Generated AI guidance now tells agents to inspect enabled Architecture Kit rules through MCP before coding.

### Changed

- MCP resource summaries now reinforce the enabled-architecture preflight requirement for agents.

## v0.1.5 - 2026-07-08

### Removed

- Removed the `ArchitectureConfig::customRules()` compatibility adapter. Use `ArchitectureConfig::customRuleSet()` for all custom audit rule access.

## v0.1.4 - 2026-07-08

### Added

- Architecture-scoped custom audit rules can now be registered under `rules.{architecture-slug}` and run only when that architecture is enabled.
- MCP enabled architecture summaries now include scoped custom audit rule basenames.
- Compact `guard --agent` output now includes suppression counters under `sup`, matching `audit --agent`.

### Changed

- PHP support metadata now requires PHP `^8.3`, matching the CI matrix.
- MCP server metadata now reports the package release version instead of `1.0.0`.
- `ArchitectureConfig::customRules()` is documented as a backward-compatible global custom rules accessor; scoped semantics live in `customRuleSet()`.

## v0.1.3 - 2026-07-07

### Fixed

- Published the namespace-correct package under a fresh immutable patch version for Packagist.

## v0.1.2 - 2026-07-07

### Fixed

- PHP namespaces now use `GracjanKubicki\ArchitectureKit` to match the package vendor.
- Generated `config/architectures.php` files now import `GracjanKubicki\ArchitectureKit\Architecture`.
- License copyright now uses `Gracjan Kubicki`.

## v0.1.1 - 2026-07-06

### Fixed

- Composer package name is now `gracjankubicki/laravel-architecture-kit` to match the publishing account.
- Saloon install no longer requires `saloonphp/rate-limit-plugin:^4.0`, which does not exist on Packagist; the constraint is now `^2.5`, the first release line compatible with Saloon 4.

## v0.1.0 - 2026-07-06

### Added

- GitHub Actions test matrix for supported Laravel and PHP versions.
- Architecture audit suppression support through inline ignores, baselines, and path excludes.
- Custom project architecture and custom audit rule extension points.
- Architecture Kit guard JSON suppression counters.
- Compact generated architecture guideline index with per-architecture summary resources.
- `architecture-kit:guidelines` command for listing summaries or expanding full rules without MCP.

### Changed

- Dropped Laravel 11 support. Supported Laravel versions are Laravel 12 and Laravel 13.
- `laravel/mcp` remains a required dependency because MCP is a core Architecture Kit integration.
- Reorganized internal `Support` classes into domain namespaces and split Eloquent Lifecycle and Saloon audit rules into focused file checks.
- `.ai/guidelines/architecture-kit.md` now renders a compact index; full generated guidelines remain available through skills, MCP, and `architecture-kit:guidelines`.
- MCP `architecture-rules` and `architecture-kit://guideline` return the full generated guideline.

### Fixed

- Compact guideline index `Folder` column now shows project default placement instead of package resource paths.
