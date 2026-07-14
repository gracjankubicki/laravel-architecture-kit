# Upgrade Guide

## Upgrading to v0.2.0 from v0.1.x

v0.2.0 changes the installation and Laravel AI compatibility contracts.

### Move Architecture Kit to runtime `require`

`config/architectures.php` contains `Architecture` enum cases and is loaded during normal application boot and config caching. Move the package out of `require-dev`:

```bash
composer remove --dev gracjankubicki/laravel-architecture-kit
composer require gracjankubicki/laravel-architecture-kit:^0.2
```

Install, doctor, and sync block before generated config/resources are changed when Architecture Kit remains dev-only.

### Use a supported Laravel AI runtime constraint

When `Architecture::LaravelAi` is enabled, `laravel/ai` must be a direct root runtime dependency. Supported profiles are:

```text
laravel-ai@0.8  >=0.8.0 <0.9.0
laravel-ai@0.9  >=0.9.0 <0.10.0
```

Move a dev-only dependency and select a supported line, for example:

```bash
composer remove --dev laravel/ai
composer require laravel/ai:^0.9
```

The declared constraint must be fully contained in the supported union. A broad constraint that also permits `0.10`, `1.x`, or a development branch is rejected even when the currently installed version happens to be `0.9.x`.

### Regenerate the selected profile

After Composer finishes:

```bash
php artisan architecture-kit:doctor
php artisan architecture-kit:sync --no-interaction
php artisan boost:update --no-interaction
```

Use `architecture-kit:sync --dry-run --agent` before writing in CI. Sync keeps architecture selection unchanged, preserves unmanaged files, and aborts before writes for unsupported, missing, dev-only, stale-lock, or missing-capability states.

Laravel AI 0.8 and 0.9 use separate generated profiles. An upgrade from 0.8 to 0.9 makes the previous generated resources outdated. Structured response examples now use `toArray()` or ArrayAccess; 0.9 provider-option guidance uses `withProviderOptions()`.

### Laravel Boost flows

- Fresh Boost setup: generate Architecture Kit resources, then run `php artisan boost:install`.
- Architecture Kit newly added to an existing Boost project: run one-time discovery when prompted.
- Recurring dependency/profile update: run Architecture Kit sync, then normal `boost:update --no-interaction` without discovery.

Without Boost, generated `.ai/**`, CLI, and MCP guidance remain available.
