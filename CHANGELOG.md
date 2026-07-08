# Changelog

All notable changes to `gracjankubicki/laravel-architecture-kit` will be documented in this file.

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
