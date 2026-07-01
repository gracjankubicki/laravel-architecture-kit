# Laravel Architecture Kit

Opinionated Laravel architecture guidance and Boost resources for AI coding agents.

This package is meant to be installed as a development dependency. It lets a project choose the architecture patterns it uses, then generates commit-ready Laravel Boost guidelines and skills so AI agents code closer to the project's conventions.

## Installation

```bash
composer require --dev taqie/laravel-architecture-kit
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

## Commands

```bash
php artisan architecture-kit:install
php artisan architecture-kit:install-agents
php artisan architecture-kit:install-hooks
php artisan architecture-kit:mcp
php artisan architecture-kit:doctor
php artisan architecture-kit:guard --changed --strict
php artisan architecture-kit:guard --changed --base=origin/main --strict
php artisan architecture-kit:audit --changed --strict
php artisan architecture-kit:audit --changed --base=origin/main --strict
```

`architecture-kit:install` is idempotent. Re-run it to change the selected architectures or regenerate outdated `.ai` resources.

`architecture-kit:install-agents` repairs MCP and hook configuration for selected AI agents. It writes Codex MCP config to `.codex/config.toml` and Claude Code MCP config to `.mcp.json`, using `php artisan architecture-kit:mcp` as the stable wrapper command.

`architecture-kit:doctor` is read-only. It reports missing, outdated, stale, or blocked generated resources and, when agents were installed, verifies the selected agent MCP and hook state.

`architecture-kit:audit` is read-only. It scans application code against the enabled architecture rules. Use `--changed --strict` before finishing AI-generated code so warnings and errors block the final handoff. In CI or after committing, pass `--base=origin/main` or another base ref to audit the committed diff.

`architecture-kit:guard` is read-only. It runs `doctor`-equivalent generated-resource checks and the application audit as one deterministic gate. Use `--json` for hooks and MCP tools.

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

## Architectures

The MVP architecture catalog includes:

- Thin Controllers
- Form Requests
- Actions
- Query Objects
- Custom Eloquent Builders
- Data Objects
- Value Objects
- Enums
- API Resources
- Modern PHP 8.5

`Modern PHP 8.5` is a strict runtime contract. If it is enabled, the consuming
project must require PHP 8.5 or newer in `composer.json`; otherwise
`architecture-kit:install` and `architecture-kit:doctor` report the configuration
as invalid.

The project source of truth is `config/architectures.php`:

```php
<?php

use Taqie\ArchitectureKit\Architecture;

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

## Laravel Boost Integration

This package ships a small package-level Boost guideline at:

```text
resources/boost/guidelines/core.blade.php
```

That guideline only points agents to the generated project-specific file:

```text
.ai/guidelines/architecture-kit.md
```

The detailed architecture rules and skills are generated into the consuming project after `architecture-kit:install`.

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

Codex receives:

```toml
[mcp_servers.architecture-kit]
command = "php"
args = ["artisan", "architecture-kit:mcp"]
required = true
```

Claude Code receives the same server under `.mcp.json` / `mcpServers`.

The MCP server exposes read-only tools for enabled architectures, generated rules, doctor state, changed-file audit, guard state, and finding explanations. It does not regenerate files, install hooks, run migrations, write code, or mutate application data.

## Development

```bash
composer install
composer test
```
