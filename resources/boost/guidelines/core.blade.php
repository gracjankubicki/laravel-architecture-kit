## Architecture Kit

This package can generate project-specific Architecture Kit guidance for AI coding agents.

If this project contains `.ai/guidelines/architecture-kit.md`, you MUST follow it before adding or changing application architecture.

For full details, expand one architecture with `php artisan architecture-kit:guidelines {slug} --agent` or use the Architecture Kit MCP `architecture-rules` resource.

Architecture Kit includes a package-first rule. Before implementing custom infrastructure, you MUST check existing Laravel features, maintained Laravel ecosystem packages, and maintained third-party PHP packages, then use the existing option when it fits the project constraints.

If `.ai/guidelines/architecture-kit.md` does not exist, ask the user to configure Architecture Kit or run:

```bash
php artisan architecture-kit:install
```
