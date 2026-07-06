# Changelog

All notable changes to `taqie/laravel-architecture-kit` will be documented in this file.

## v0.1.0 - Unreleased

### Added

- GitHub Actions test matrix for supported Laravel and PHP versions.
- Architecture audit suppression support through inline ignores, baselines, and path excludes.
- Custom project architecture and custom audit rule extension points.
- Architecture Kit guard JSON suppression counters.

### Changed

- Dropped Laravel 11 support. Supported Laravel versions are Laravel 12 and Laravel 13.
- `laravel/mcp` remains a required dependency because MCP is a core Architecture Kit integration.
- Reorganized internal `Support` classes into domain namespaces and split Eloquent Lifecycle and Saloon audit rules into focused file checks.
