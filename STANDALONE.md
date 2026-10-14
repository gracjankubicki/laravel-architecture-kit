# Use Architecture Kit without installing it in the application

The standalone executable reads a Laravel repository using its own dependencies. Configuration, graph cache, saved reports, task beginnings and generated integration files stay outside that repository. Installing the executable does not enable AI hooks or change any agent settings.

## Install an isolated global tool

The tool process requires PHP 8.3 or newer and the extensions required by Laravel Framework and Composer. Its PHP version is separate from the application's PHP version. Static parsing of a project does not certify compatibility with an older Laravel release.

Composer's global directory can contain other tools with conflicting dependencies. Use `bamarni/composer-bin-plugin` to give Architecture Kit a separate dependency namespace:

```sh
composer global config allow-plugins.bamarni/composer-bin-plugin true
composer global require bamarni/composer-bin-plugin
composer global bin architecture-kit require gracjankubicki/laravel-architecture-kit
```

Find the Composer home with `composer global config home --absolute`. The executable is at `<composer-home>/vendor-bin/architecture-kit/vendor/bin/architecture-kit`. Add that directory to your shell's `PATH`, or use the absolute executable path. This does not depend on the plugin's optional binary links.

Update with `composer global bin architecture-kit update gracjankubicki/laravel-architecture-kit`. Updating the tool invalidates task comparisons made with a different tool source fingerprint. Finish or explicitly skip the previous task before starting a new task under the new version.

## Register a repository and choose rules

```sh
architecture-kit --project /absolute/path/crm-backend init actions thin-controllers data-objects
architecture-kit --project /absolute/path/crm-backend audit --agent
```

The first command creates a local profile under `~/.architecture-kit/profiles/<repository-id>/default.json`. It does not modify the application's Composer files or create an `AGENTS.md` in the application. Supply architecture slugs to `init`; edit the generated JSON to set additional audit and graph policies. `init` preserves existing settings.

In the terminal, `--project` is optional inside a repository or its subdirectory. Discovery prefers a containing Git root with `composer.json`, otherwise the closest Composer project. Outside a project, supply its path. `--tool-home /absolute/external/path` or `ARCHITECTURE_KIT_HOME` changes the external state location.

Configuration selection follows this order:

1. An explicitly selected local `--profile NAME`.
2. `.architecture-kit/settings.json` in the repository.
3. `config/architectures.php` in the repository, decoded as static data.
4. The registered external default profile.

Creating another local profile does not make it the default:

```sh
architecture-kit --project /absolute/path/crm-backend --profile personal init services
architecture-kit --project /absolute/path/crm-backend --profile personal audit --agent
```

To share settings with a team, explicitly write repository settings with `init actions --shared --allow-write`. Source-only PHP configuration accepts literal arrays and the package's architecture/missing-test enum cases. Dynamic expressions or executable statements are rejected without running them.

## Export Codex or Claude Code integration

```sh
architecture-kit --project /absolute/path/crm-backend integration export codex
architecture-kit --project /absolute/path/crm-backend integration export claude
```

Each export writes `hooks.json`, `mcp.json` and `status.json` under the external integration directory printed by the command. It does not install them into the agent. The exported commands use absolute paths and pin the selected project and optional profile. Hooks do nothing for a different or unregistered project. MCP stays pinned to its canonical project for the entire process; tool arguments cannot switch it.

Merge the generated hooks into the client's **user** settings, preserving other hooks:

- Codex reads user hooks from `~/.codex/hooks.json`. Configure an MCP server in the user's Codex settings using the generated command and argument list. Review and trust the exact hook definitions through `/hooks`; changed hook definitions need renewed trust.
- Claude Code reads user hooks from `~/.claude/settings.json`. Add the generated MCP command through Claude Code's user-scoped MCP configuration. Preserve existing settings and restart the session after installation.

The export has `active: false`. `integration status codex` or `integration status claude` reports historical hook observations, including the last event and check status. An observation is evidence that a hook ran; it does not prove the current settings are enabled or trusted. Verify a fresh real session in the client. Without running trusted hooks, MCP instructions alone cannot enforce a completion check.

## Keep one beginning throughout a task

At a fresh `SessionStart` with source `startup`, the hook captures the existing audit findings before edits and injects the selected guidance. Existing uncommitted changes are included. Subsequent prompts, corrections and resumed sessions retain this beginning. A resumed or compacted session without a known beginning is blocked; it cannot invent a baseline after edits.

The agent can also use the standalone MCP `task-control` tool. CLI equivalents are:

```sh
architecture-kit task begin invoice-task
architecture-kit task bind invoice-task --client codex --session CLIENT_SESSION_ID
architecture-kit task check invoice-task
architecture-kit task status invoice-task
```

Beginning the same task again returns its original record. To start another task in the same chat, first complete the previous task or explicitly skip it, then begin a new named task and bind that task to the client session. Binding cannot replace an unfinished task. New task names represent human-approved task boundaries, not a way to hide a failing result.

The completion check compares the current multiset of enforced errors with the initial multiset. Moving lines does not create a new error; another occurrence of the same error does. Earlier errors are counted separately and warnings do not block. All current changes participate, including parallel edits by other people; the result does not infer authorship. Do not automatically repair other people's changes.

Incomplete analysis, missing beginnings, changed configuration/tool policy, unreadable inputs and audit failures never produce PASS. The Stop hook asks for correction once in a turn, and limits failed checks across attempts. When another continuation would loop, it releases the turn with an explicit failure message; the task remains unverified. Report the blocker rather than treating released completion as a passed audit.

A human can explicitly skip a known task:

```sh
architecture-kit task skip invoice-task --confirm
```

A skipped task remains `ok: false`, `status: skipped`. It does not certify code or tests. A passed record becomes stale after a source or configuration change. Architecture PASS only means no new recognized enforced errors; application tests still need separate verification.

## Execution and repository writes

Default analysis does not load the application's PHP autoloader, configuration or bootstrap. Runtime-only route facts remain unavailable and required unresolved analysis blocks task verification. Static search, relationships and guidance remain available.

`--allow-execution` opts into runtime route discovery in a child process. It does not install the package into the application or override unsupported runtime requirements. Custom PHP rules require this flag and must be available to the tool's autoloader; unavailable rules produce an error rather than being silently omitted. The tool does not mix an arbitrary application's autoloader into its host process.

Scaffolding proposals through MCP or `make --agent` write nothing. `make` without `--agent`, baseline updates and `sync` require `--allow-write` in standalone mode. `sync --dry-run` previews its repository files. The graph cache and paginated reports remain in external storage even when execution or writes are allowed.

## Reference

CLI supports audit, guard, doctor, context, search, impact, path, reach, public API, revision comparisons, target architecture, file rules, guidelines, explanation, upgrade planning, scaffold proposals, sync and cache clearing. Use `COMMAND --help` for the reused command's options. Laravel's existing `php artisan architecture-kit:*` commands retain their normal behavior.

Official integration contracts:

- [Composer global tools](https://getcomposer.org/doc/03-cli.md#global)
- [Composer vendor binaries](https://getcomposer.org/doc/articles/vendor-binaries.md)
- [composer-bin-plugin](https://github.com/bamarni/composer-bin-plugin)
- [Codex hooks](https://learn.chatgpt.com/docs/hooks)
- [Claude Code hooks](https://code.claude.com/docs/en/hooks)
- [Claude Code settings](https://code.claude.com/docs/en/settings)
