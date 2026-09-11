# Upgrade Guide

## Upgrading to v0.3.0 from v0.2.x

v0.3.0 contains no new features. It fixes correctness and reliability problems in the audit, the guard, and the generated agent hook. Three of those fixes can surface as new failures in a project that did not change any of its own code, so review them before updating.

### A previously silent audit may now report findings

Relative paths were normalized by stripping the project directory anywhere it appeared in a file path, instead of only at the beginning. A project whose base path ends with a segment that also occurs inside file paths, most commonly a container `WORKDIR` of `/app`, lost the leading `app/` from every path. Because every rule matches on that prefix, the audit silently matched nothing and reported success.

If the project runs Architecture Kit in such a container, the first audit after this upgrade is effectively the first real audit. Expect findings that were always present but never reported, and treat the result as a new baseline rather than a regression:

```bash
php artisan architecture-kit:audit
php artisan architecture-kit:audit --update-baseline
```

### A stale inline suppression is now reported

An inline suppression naming a known rule that matches no finding is reported as an `invalid-suppression` warning. Because `architecture-kit:guard --strict` treats warnings as failures, a project carrying an obsolete `@architecture-kit-ignore` comment can see its gate fail without any local change.

Remove the comment once the underlying finding is gone. Suppression in a multi-line docblock and several rules in one comment now work as documented, so a directive that never took effect may also start applying:

```php
/**
 * @architecture-kit-ignore thin-controller
 * @architecture-kit-ignore actions
 */
```

### Narrowed scope and baseline rewrite are mutually exclusive

`architecture-kit:audit --changed --update-baseline` rewrote the baseline from the narrowed scope and silently dropped suppressions for every file outside it. The combination is now rejected. Update the baseline over the full project instead:

```bash
php artisan architecture-kit:audit --update-baseline
```

### Custom rule sets constructing `SaloonRule` directly

`SaloonRule` never used its injected filesystem and base path, and its constructor now takes no arguments. This only affects code that constructed the class directly; the documented extension point for project-specific rules is unchanged.

```php
new SaloonRule($files, base_path()); // before
new SaloonRule;                      // after
```

### Agent hooks generated before v0.3.0

The generated `guard.sh` resolved the project directory from the repository root, so an application living in a subdirectory of a monorepo pointed the hook at a guard script that does not exist. Existing hook files stay developer-owned and are never rewritten, so a project that wants the fix has to remove the old file and regenerate it:

```bash
rm .architecture-kit/hooks/guard.sh
php artisan architecture-kit:install-agents --hooks
```

Review the regenerated script if it was customized locally.

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
laravel-ai@0.10 >=0.10.0 <0.11.0
```

Move a dev-only dependency and select a supported line, for example:

```bash
composer remove --dev laravel/ai
composer require laravel/ai:^0.10
```

The declared constraint must be fully contained in the supported union. A broad constraint that also permits `0.11`, `1.x`, or a development branch is rejected even when the currently installed version happens to be `0.10.x`.

### Regenerate the selected profile

After Composer finishes:

```bash
php artisan architecture-kit:doctor
php artisan architecture-kit:sync --no-interaction
php artisan boost:update --no-interaction
```

Use `architecture-kit:sync --dry-run --agent` before writing in CI. Sync keeps architecture selection unchanged, preserves unmanaged files, and aborts before writes for unsupported, missing, dev-only, stale-lock, or missing-capability states.

Laravel AI 0.8, 0.9, and 0.10 use separate generated profiles. A version change makes the previous generated resources outdated. Structured response examples use `toArray()` or ArrayAccess; 0.9+ provider-option guidance uses `withProviderOptions()`, while 0.10 adds participant authorization and approval-resumption rules.

When Laravel AI architecture is enabled, Architecture Kit also generates atomic AI upgrade skills:

```text
architecture-kit-upgrade-laravel-ai-0-8-to-0-9
architecture-kit-upgrade-laravel-ai-0-9-to-0-10
```

These skills instruct an AI agent to inspect the consuming application, classify each upstream change by applicability, follow the project's implementation gates, update only the accepted scope, and attach concrete verification evidence. They are not codemods and Architecture Kit does not mutate application code or data automatically. A `0.8 -> 0.10` upgrade must apply and verify both skills in order.

### Laravel Boost flows

- Fresh Boost setup: generate Architecture Kit resources, then run `php artisan boost:install`.
- Architecture Kit newly added to an existing Boost project: run one-time discovery when prompted.
- Recurring dependency/profile update: run Architecture Kit sync, then normal `boost:update --no-interaction` without discovery.

Without Boost, generated `.ai/**`, CLI, and MCP guidance remain available.
