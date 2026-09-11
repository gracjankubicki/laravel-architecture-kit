# Changelog

All notable changes to `gracjankubicki/laravel-architecture-kit` will be documented in this file.

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
