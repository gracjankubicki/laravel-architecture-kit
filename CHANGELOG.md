# Changelog

All notable changes to `gracjankubicki/laravel-architecture-kit` will be documented in this file.

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
