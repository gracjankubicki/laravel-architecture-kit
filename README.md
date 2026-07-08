<p align="center">
    <img src="https://raw.githubusercontent.com/gracjankubicki/laravel-architecture-kit/main/art/banner.png" alt="Laravel Architecture Kit">
</p>

<p align="center">
    <a href="https://github.com/gracjankubicki/laravel-architecture-kit/actions/workflows/tests.yml">
        <img src="https://github.com/gracjankubicki/laravel-architecture-kit/actions/workflows/tests.yml/badge.svg?branch=main" alt="Tests">
    </a>
    <a href="https://codecov.io/gh/gracjankubicki/laravel-architecture-kit">
        <img src="https://codecov.io/gh/gracjankubicki/laravel-architecture-kit/branch/main/graph/badge.svg" alt="Coverage">
    </a>
    <a href="https://packagist.org/packages/gracjankubicki/laravel-architecture-kit">
        <img src="https://img.shields.io/packagist/v/gracjankubicki/laravel-architecture-kit.svg?style=flat-square" alt="Latest Version on Packagist">
    </a>
    <a href="https://packagist.org/packages/gracjankubicki/laravel-architecture-kit">
        <img src="https://img.shields.io/packagist/dt/gracjankubicki/laravel-architecture-kit.svg?style=flat-square" alt="Total Downloads">
    </a>
    <a href="https://packagist.org/packages/gracjankubicki/laravel-architecture-kit">
        <img src="https://img.shields.io/packagist/l/gracjankubicki/laravel-architecture-kit.svg?style=flat-square" alt="License">
    </a>
    <img src="https://img.shields.io/packagist/php-v/gracjankubicki/laravel-architecture-kit.svg?style=flat-square" alt="PHP Version">
    <img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12 and 13">
    <img src="https://img.shields.io/badge/MCP-enabled-6f42c1?style=flat-square" alt="MCP enabled">
</p>

# Laravel Architecture Kit

Opinionated Laravel architecture guidance and Boost resources for AI coding agents.

This package is meant to be installed as a development dependency. It lets a project choose the architecture patterns it uses, then generates commit-ready Laravel Boost guidelines and skills so AI agents code closer to the project's conventions.

## Installation

```bash
composer require --dev gracjankubicki/laravel-architecture-kit
php artisan architecture-kit:install
```

The install command is interactive. It asks which architecture patterns the project uses, writes `config/architectures.php`, and generates:

```text
.ai/guidelines/architecture-kit.md
.ai/skills/architecture-kit-{architecture}/SKILL.md
```

It can also install agent integration without manual file editing:

```text
.codex/config.toml
.mcp.json
.codex/hooks.json
.claude/settings.json
.architecture-kit/hooks/guard.sh
```

If Laravel Boost is installed, the command can also run:

```bash
php artisan boost:update --discover
```

Boost then syncs the generated `.ai` resources into agent files such as `AGENTS.md`, `CLAUDE.md`, and other configured AI instructions.

The generated `.ai/guidelines/architecture-kit.md` file is a compact index, not the full rulebook. It lists enabled architectures, folders, hard rules, global rules, and the guard command. Agents should expand details only when needed through:

```bash
php artisan architecture-kit:guidelines actions --agent
```

The same full rules are also available through the generated `architecture-kit-{architecture}` skills and MCP `architecture-rules` / `architecture-kit://guideline`.

## Commands

```bash
php artisan architecture-kit:install
php artisan architecture-kit:install-agents
php artisan architecture-kit:install-hooks
php artisan architecture-kit:mcp
php artisan architecture-kit:doctor
php artisan architecture-kit:guidelines
php artisan architecture-kit:guidelines actions --agent
php artisan architecture-kit:guard --changed --strict
php artisan architecture-kit:guard --changed --base=origin/main --strict
php artisan architecture-kit:audit --changed --strict
php artisan architecture-kit:audit --changed --base=origin/main --strict
php artisan architecture-kit:audit --update-baseline
php artisan architecture-kit:audit --agent
php artisan architecture-kit:guard --agent
php artisan architecture-kit:doctor --agent
php artisan architecture-kit:explain E_THIN_CONTROLLER_MODEL_WRITE --agent
```

`architecture-kit:install` is idempotent. Re-run it to change the selected architectures, the PHP runtime, or regenerate outdated `.ai` resources.

`architecture-kit:install-agents` repairs MCP and hook configuration for selected AI agents. It writes Codex MCP config to `.codex/config.toml` and Claude Code MCP config to `.mcp.json`, using the runtime from `config/architectures.php` as the stable wrapper command.

`architecture-kit:doctor` is read-only. It reports missing, outdated, stale, or blocked generated resources and, when agents were installed, verifies the selected agent MCP and hook state.

`architecture-kit:guidelines` is read-only. Without an argument it lists known architectures with a one-line summary. With a slug it returns the full guideline for one architecture, even when that architecture is available but not enabled.

`architecture-kit:audit` is read-only. It scans application code against the enabled architecture rules. Use `--changed --strict` before finishing AI-generated code so warnings and errors block the final handoff. In CI or after committing, pass `--base=origin/main` or another base ref to audit the committed diff.

Use `architecture-kit:audit --update-baseline` when adopting Architecture Kit in a legacy project. It writes the current findings to `.architecture-kit/baseline.json`; future audits suppress only the matching existing findings and still report new violations. Use `--no-baseline` to ignore the baseline for one run.

`architecture-kit:guard` is read-only. It runs `doctor`-equivalent generated-resource checks and the application audit as one deterministic gate. Use `--json` for hooks and MCP tools.

### Agent Output

Human-facing command output stays descriptive. Existing `--json` output stays compatible for hooks and integrations. AI agents can use `--agent` for compact, single-line JSON:

```bash
php artisan architecture-kit:audit --agent --limit=20
```

Example:

```json
{"v":1,"ok":false,"cmd":"audit","scope":"changed","err":1,"warn":0,"sup":{"inline":0,"baseline":0},"trunc":false,"find":[{"r":"thin-controller","s":"err","p":"app/Http/Controllers/InvoiceController.php","l":27,"m":"E_THIN_CONTROLLER_MODEL_WRITE","n":1}],"next":["fix_findings","rerun:audit --agent"]}
```

By default, `--agent` returns finding codes instead of full messages to reduce token usage. Use `--full` when the agent needs full text, or ask for one code:

```bash
php artisan architecture-kit:explain E_THIN_CONTROLLER_MODEL_WRITE --agent
```

`--limit=0` returns only the summary. When findings or doctor issues are truncated, the payload contains `trunc`, `total`, and `shown`.

Agents can inspect the contract without running the audit:

```bash
php artisan architecture-kit:audit --agent --schema
```

For cheap on-demand rule expansion:

```bash
php artisan architecture-kit:guidelines --agent
php artisan architecture-kit:guidelines actions --agent
php artisan architecture-kit:guidelines --schema
```

`architecture-kit:install-hooks` is a compatibility shortcut for installing only hook integration through the same merge-aware agent installer:

```text
.architecture-kit/hooks/guard.sh
.codex/hooks.json
.claude/settings.json
```

The generated hooks run:

```bash
php artisan architecture-kit:guard --changed --strict --json
```

Existing valid agent config is merged and unrelated MCP servers or hooks are preserved. Invalid JSON/TOML, or incompatible unmanaged `architecture-kit` entries, block installation with a clear error.

## Docker, Sail, and Custom PHP Runtimes

`config/architectures.php` stores how the project runs PHP. Missing `runtime` means local PHP for backwards compatibility.

```php
return [
    'enabled' => [
        Architecture::Actions,
    ],
    'runtime' => [
        'driver' => 'docker', // local | sail | docker | custom
        'service' => 'app',
        'php' => 'php',
        'command' => null,
    ],
];
```

For Docker Compose and Sail, generated hooks and MCP configs use raw non-TTY compose commands:

```bash
docker compose exec -T app php artisan architecture-kit:guard --changed --strict --json
docker compose exec -T app php artisan architecture-kit:mcp
```

Sail is treated as detection convenience only. Architecture Kit reads `APP_SERVICE` from `.env` and defaults to `laravel.test`, but still generates `docker compose exec -T ...` so hooks and MCP stdio stay deterministic.

For `--changed` audits inside Docker or Sail, the PHP runtime must have:

- `git` installed in the container,
- the repository mounted with `.git`,
- the same project files available where artisan runs.

If those requirements are missing, `architecture-kit:doctor` reports runtime warnings. Hooks are fail-closed for every runtime: if the runtime is unavailable and no Architecture Kit JSON payload is returned, the agent turn is blocked with a runtime message.

Mixed teams can commit a small wrapper and use `driver: custom`:

```bash
#!/usr/bin/env bash
set -euo pipefail

if command -v docker >/dev/null 2>&1 && [ -f compose.yaml ]; then
    exec docker compose exec -T app php "$@"
fi

exec php "$@"
```

```php
'runtime' => [
    'driver' => 'custom',
    'service' => null,
    'php' => 'php',
    'command' => ['bin/php-runner'],
],
```

## Architectures

The architecture catalog:

| Pattern | Default placement | Focus |
| --- | --- | --- |
| Thin Controllers | `app/Http/Controllers` | Controllers as HTTP adapters only |
| Form Requests | `app/Http/Requests` | Request validation and authorization |
| Actions | `app/Actions` | Application use cases with a single public `handle()` |
| Services | `app/Services` | Aggregated application APIs over several Actions |
| Query Objects | `app/Queries` | Encapsulated read use cases |
| Custom Eloquent Builders | `app/Models/Builders` | Reusable model-level query scopes |
| Data Objects | `app/Data` | Typed immutable input and result carriers |
| Value Objects | `app/ValueObjects` | Validated immutable domain values |
| Enums | domain-first | Closed sets with exhaustive `match` |
| API Resources | `app/Http/Resources` | Read output shaping |
| Eloquent Lifecycle | `app/Observers`, `app/Lifecycle` | Model lifecycle boundaries: thin observers, handlers, after-commit events |
| Saloon | `app/Http/Integrations` | External HTTP integrations through Saloon connectors |
| Ports And Adapters | near the owning boundary | Explicit outbound seams for providers and infrastructure |
| Modern PHP 8.5 | cross-cutting | Strict modern PHP runtime contract |
| Laravel AI | `app/Ai` | Typed `laravel/ai` agents, tools, and prompts |
| Laravel Best Practices | cross-cutting | Laravel-native defaults composed with the other enabled patterns |

Some patterns have hard requirements, validated by `architecture-kit:install` and `architecture-kit:doctor`:

- `Modern PHP 8.5` is a strict runtime contract. The consuming project must require PHP 8.5 or newer in `composer.json`; otherwise the configuration is reported as invalid.
- `Saloon` requires `saloonphp/saloon` `^4.0`, `saloonphp/laravel-plugin`, and `saloonphp/rate-limit-plugin`. The install command offers to `composer require` the missing packages. Constraints that still allow Saloon 3 are reported as invalid because Saloon 4 fixes security issues in v3.
- `Laravel AI` requires `laravel/ai` in `composer.json`.

On the first install (before `config/architectures.php` exists), `Services` is preselected when the project already has an `app/Services` folder, and `Laravel AI` is preselected when `composer.json` already requires `laravel/ai`.

The install command also warns about weak pattern combinations, for example Thin Controllers, Eloquent Lifecycle, or Saloon enabled without Actions as the application boundary.

The project source of truth is `config/architectures.php`:

```php
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::ThinControllers,
        Architecture::FormRequests,
        Architecture::Actions,
        Architecture::DataObjects,
        Architecture::ApiResources,
    ],
];
```

Optional audit configuration lives in the same file:

```php
return [
    'enabled' => [
        Architecture::Actions,
        'billing-workflows',
    ],
    'audit' => [
        'exclude' => ['app/Legacy/*'],
    ],
    'rules' => [
        App\Architecture\Rules\NoForbiddenBillingState::class,
    ],
];
```

### Suppression

Inline suppression is for reviewed false positives only. Always name the rule and include a reason:

```php
// @architecture-kit-ignore thin-controller -- legacy endpoint accepted in PR #123
$invoice->update($payload);
```

File-level suppression is also rule-specific:

```php
// @architecture-kit-ignore-file query-objects -- generated legacy query object
```

Unknown suppression rules are reported as `invalid-suppression` warnings and do not hide the original finding.

### Composer Update Check

Use a read-only Composer hook to detect stale generated resources after package updates:

```json
{
    "scripts": {
        "post-update-cmd": [
            "@php artisan architecture-kit:doctor --agent"
        ]
    }
}
```

Do not run `architecture-kit:install` from Composer hooks. It is interactive; run it manually when `doctor` reports outdated resources.

### Custom Architectures

Project-owned architectures live under:

```text
.architecture-kit/architectures/{slug}/guideline.md
.architecture-kit/architectures/{slug}/summary.md
.architecture-kit/architectures/{slug}/SKILL.md
```

`guideline.md` is required. `summary.md` is optional; when it is missing, Architecture Kit uses the first non-empty guideline line as the compact index summary. `SKILL.md` is optional; when it is missing, Architecture Kit generates a skill from the guideline. Custom architecture slugs must be kebab-case and can be enabled as strings in `config/architectures.php`.

This is a guidance contract for agents. It generates `.ai/guidelines`, `.ai/skills`, and MCP rule output, but Architecture Kit does not infer deterministic AST checks from prose in `guideline.md`.

```php
return [
    'enabled' => [
        Architecture::Actions,
        'billing-workflows',
    ],
];
```

### Custom Audit Rules

Custom rules are PHP classes registered in `config/architectures.php` under `rules`. Each rule must implement `GracjanKubicki\ArchitectureKit\Audit\AuditRule`. Custom rule findings participate in inline suppression, baseline suppression, `audit`, `guard`, hooks, and MCP output.

Prefer architecture-scoped rules when the rule enforces one architecture:

```php
return [
    'enabled' => [
        Architecture::Actions,
        'billing-workflows',
    ],
    'rules' => [
        'billing-workflows' => [
            App\Architecture\BillingWorkflows\Rules\NoInvoiceStateChangeOutsideBillingAction::class,
            App\Architecture\BillingWorkflows\Rules\NoInvoiceTransitionInController::class,
        ],
    ],
];
```

Scoped rules run only when their architecture is enabled. The rule class does not need to check `in_array('billing-workflows', $enabled, true)` just to bind itself to that architecture.

Flat rules remain supported for global project checks that are not owned by one architecture. Programmatic callers should use `ArchitectureConfig::customRuleSet()` for all custom audit rule access; it exposes `globalRules()`, `scopedRules()`, `rulesFor($enabled)`, and `knownRuleClasses()`.

```php
return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        App\Architecture\Rules\NoForbiddenProjectPattern::class,
    ],
];
```

## Laravel Boost Integration

This package ships a small package-level Boost guideline at:

```text
resources/boost/guidelines/core.blade.php
```

That guideline only points agents to the generated project-specific file:

```text
.ai/guidelines/architecture-kit.md
```

The compact architecture index, detailed architecture rules, and skills are generated into the consuming project after `architecture-kit:install`.

Generated Architecture Kit guidance includes a Package-First Architecture Rule. AI agents must search existing Laravel features, maintained Laravel ecosystem packages, and maintained third-party PHP packages before writing custom infrastructure. Custom code is allowed only when no suitable maintained package fits the project constraints or can safely provide the required behavior.

Generated guidance also includes a Testability Architecture Rule. AI agents must keep dependencies explicit and must not replace `app(SomeClass::class)` with private static factories that call `new SomeClass()`. Use constructor or method injection, or move behavior behind an enabled architecture boundary.

## Laravel MCP Integration

Architecture Kit requires `laravel/mcp` and registers a local MCP server named:

```text
architecture-kit
```

Agent config is generated by:

```bash
php artisan architecture-kit:install
php artisan architecture-kit:install-agents
```

For the local runtime, Codex receives:

```toml
[mcp_servers.architecture-kit]
command = "php"
args = ["artisan", "architecture-kit:mcp"]
required = true
```

For Docker and Sail runtimes, `command` becomes `docker` and `args` include `compose exec -T {service} php artisan architecture-kit:mcp`. Claude Code receives the same server under `.mcp.json` / `mcpServers`.

The MCP server exposes read-only tools for enabled architectures, generated rules, doctor state, changed-file audit, guard state, and finding explanations. It does not regenerate files, install hooks, run migrations, write code, or mutate application data.

## Development

```bash
composer install
composer test
composer lint
```
