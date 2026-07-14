## Architecture Kit

This package can generate project-specific Architecture Kit guidance for AI coding agents.

If this project contains `.ai/guidelines/architecture-kit.md`, you MUST follow it before adding or changing application architecture.

Before coding, your first Architecture Kit MCP call MUST be `enabled-architectures`. Use it to identify enabled patterns and relevant `architecture-kit-*` skills. Do not implement architecture-sensitive code before this preflight.

If MCP is unavailable, read `.ai/guidelines/architecture-kit.md` or run `php artisan architecture-kit:guidelines --agent` before coding.

For full details, expand one architecture with `php artisan architecture-kit:guidelines {slug} --agent`, call the Architecture Kit MCP tool `architecture-rules`, or read the MCP resource `architecture-kit://guideline`.

When Laravel AI is enabled, load exactly one generated `architecture-kit-laravel-ai` skill for project architecture policy and the official `ai-sdk-development` skill shipped by the installed `laravel/ai` package for SDK details. Do not duplicate either skill's rules in this bootstrap guideline.

Architecture Kit includes a package-first rule. Before implementing custom infrastructure, you MUST check existing Laravel features, maintained Laravel ecosystem packages, and maintained third-party PHP packages, then use the existing option when it fits the project constraints.

If `.ai/guidelines/architecture-kit.md` does not exist, ask the user to configure Architecture Kit or run:

```bash
php artisan architecture-kit:install
```

After Composer updates, regenerate managed resources explicitly with `php artisan architecture-kit:sync --no-interaction`, then run normal `php artisan boost:update --no-interaction`.
