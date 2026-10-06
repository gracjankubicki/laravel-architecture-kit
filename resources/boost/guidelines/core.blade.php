## Architecture Kit

This package can generate project-specific Architecture Kit guidance for AI coding agents.

If this project contains `.ai/guidelines/architecture-kit.md`, you MUST follow it before adding or changing application architecture.

Before coding, your first Architecture Kit MCP call MUST be `enabled-architectures`. Use it to identify enabled patterns and relevant `architecture-kit-*` skills. Do not implement architecture-sensitive code before this preflight.

If MCP is unavailable, read `.ai/guidelines/architecture-kit.md` or run `php artisan architecture-kit:guidelines --agent` before coding.

For full details, expand one architecture with `php artisan architecture-kit:guidelines {slug} --agent`, call the Architecture Kit MCP tool `architecture-rules`, or read the MCP resource `architecture-kit://guideline`.

Use classification from file-rules, architecture-context, search and impact as the shared source for declared roles, application kinds and module ownership. PHP kind is separate. Read provenance; declarations do not prove compliance or enable profiles. Declared roles and kinds override default placement conventions below. Respect existing directories. During a related change, you may propose a named module with Actions and Queries subdirectories, but list the affected files, reasons, uncertainties, references and registration changes, and test consequences. Keep this proposal separate from accepted project declarations. Require user approval before moving files or editing declarations. Keep models in app/Models by default; shared models may remain unassigned. Respect models already inside modules. Never scaffold or reorganize modules as an unrelated cleanup.

Guard success means that no enforced rule blocks the change. Review architectural suggestions separately. Suggestions may propose an architecture that is not enabled; do not enable it or refactor outside the agreed scope without the user's decision. Incomplete analysis identifies unresolved code, not a violation or proof of correctness. A write reached through GET or HEAD does not by itself violate an Architecture Kit rule. Keep enforcing the project's selected architecture boundaries.

When Laravel AI is enabled, load exactly one generated `architecture-kit-laravel-ai` skill for project architecture policy and the official `ai-sdk-development` skill shipped by the installed `laravel/ai` package for SDK details. Do not duplicate either skill's rules in this bootstrap guideline.

Before upgrading a direct Composer package, call the MCP tool `plan-upgrade` or run `php artisan architecture-kit:upgrade-plan {package} --to={major.minor} --agent`. Load only the active atomic upgrade skill, complete and verify that edge, then rerun the planner from the new installed state.

Architecture Kit includes a package-first rule. Before implementing custom infrastructure, you MUST check existing Laravel features, maintained Laravel ecosystem packages, and maintained third-party PHP packages, then use the existing option when it fits the project constraints.

If `.ai/guidelines/architecture-kit.md` does not exist, ask the user to configure Architecture Kit or run:

```bash
php artisan architecture-kit:install
```

After Composer updates, regenerate managed resources explicitly with `php artisan architecture-kit:sync --no-interaction`, then run normal `php artisan boost:update --no-interaction`.

For package contract changes, use MCP public-api or architecture-kit:public-api with a Git before revision. Read partial/freshness/check rows and explicit 0.x policy; this does not approve or publish a release.
