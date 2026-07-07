# Changelog

All notable changes to `gracjankubicki/laravel-architecture-kit` will be documented in this file.

## v0.1.1 - 2026-07-06

### Fixed

- Composer package name is now `gracjankubicki/laravel-architecture-kit` to match the publishing account.
- PHP namespaces now use `GracjanKubicki\ArchitectureKit` to match the package vendor.
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
