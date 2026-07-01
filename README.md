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

If Laravel Boost is installed, the command can also run:

```bash
php artisan boost:update --discover
```

Boost then syncs the generated `.ai` resources into agent files such as `AGENTS.md`, `CLAUDE.md`, and other configured AI instructions.

## Commands

```bash
php artisan architecture-kit:install
php artisan architecture-kit:doctor
php artisan architecture-kit:audit --changed --strict
```

`architecture-kit:install` is idempotent. Re-run it to change the selected architectures or regenerate outdated `.ai` resources.

`architecture-kit:doctor` is read-only. It reports missing, outdated, stale, or blocked generated resources and exits with a non-zero code when the Architecture Kit resources are not current.

`architecture-kit:audit` is read-only. It scans application code against the enabled architecture rules. Use `--changed --strict` before finishing AI-generated code so warnings and errors block the final handoff.

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

## Development

```bash
composer install
composer test
```
