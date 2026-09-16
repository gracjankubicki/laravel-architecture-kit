<p align="center">
    <img src="https://raw.githubusercontent.com/gracjankubicki/laravel-architecture-kit/main/art/banner.png" alt="Laravel Architecture Kit">
</p>

<p align="center">
    <a href="https://github.com/gracjankubicki/laravel-architecture-kit/actions/workflows/tests.yml">
        <img src="https://github.com/gracjankubicki/laravel-architecture-kit/actions/workflows/tests.yml/badge.svg?branch=main" alt="Tests">
    </a>
    <img src="https://raw.githubusercontent.com/gracjankubicki/laravel-architecture-kit/main/art/coverage.svg" alt="Coverage">
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

Laravel tooling for four explicit capabilities: generated architecture guidance, bounded project context for AI agents, AST-backed audit, and an optional guard for selected enforceable rules.

This package is installed as a runtime dependency because the committed architecture configuration references its enum classes while the application boots. It lets a project choose the architecture patterns it uses, then generates commit-ready Laravel Boost guidelines and skills so AI agents code closer to the project's conventions.

## Capabilities and enforcement

| Capability | What it provides | Enforced by guard? |
| --- | --- | --- |
| Guidance | Generated guidelines, skills, and MCP resources | No — prose is advice for agents and reviewers. |
| File rules | The rules that govern one path, each marked enforced or advisory | No — it tells the agent what will be checked before it writes. |
| Scaffolding | The files and skeletons a new element of an enabled architecture needs | No — the skeleton is built to pass the audit that follows. |
| Project context | Static dependencies, dependents, roles, violations, and inspect paths for one PHP symbol | No — it informs the change before coding. |
| Audit | Deterministic AST and filesystem findings | Yes, for implemented audit rules. |
| Guard | Doctor plus audit as a CI/hook gate | Yes, according to selected architectures and strict mode. |

Guidance is intentionally broader than the rules that can be verified deterministically. Add project-specific custom audit rules when a local policy, such as bilingual documentation, must be enforced.

Because that gap is invisible in a green guard, `architecture-kit:file-rules` reports it per file. Each architecture is marked `enforced` when a rule can detect a violation, or `advisory` when it ships guidance only. `laravel-best-practices` is advisory today, and it is part of the default selection, so most projects carry guidance no rule verifies:

```bash
php artisan architecture-kit:file-rules app/Actions/CreateInvoice.php
```

```text
enforced  governs   actions                    actions, folder-purity
enforced  shared    form-requests              form-request
advisory  shared    laravel-best-practices     guidance only
```

`governs` marks the architecture that describes the file. `shared` marks an architecture whose rule also runs here without the file belonging to it, which matters when deciding which guideline to read.

Custom audit rules registered under `rules` in `config/architectures.php` are reported too, in a separate `project` list, because they fail the same gate as the built-in ones. Rules that run over the whole project rather than per file, such as `layer-dependency` and `namespace-cycle`, are reported as always active.

### Scaffolding a new element

`architecture-kit:make` emits the folder, namespace, naming, and base-class conventions the project already declares, so an agent does not rebuild them from prose:

```bash
php artisan architecture-kit:make actions CreateInvoice
php artisan architecture-kit:make actions Billing/CreateInvoice
```

The interactive command writes the files and never overwrites an existing one. With `--agent`, and through the `scaffold` MCP tool, nothing is written: the plan and the skeleton are returned and the agent decides what to create. Skeletons are built to pass this package's own audit, and a test verifies that for every supported architecture.

A name must be a plain PHP identifier, optionally prefixed with `/`-separated sub-namespaces. A name that would escape the architecture folder is rejected, as is a name whose suffix marks a different kind of class, such as `CreateInvoiceData` inside `app/Actions`.

### Route-aware read checks

With Thin Controllers and Actions enabled, the audit reads a fresh Laravel route collection and associates each controller method with its HTTP verbs. GET/HEAD may call a cohesive read Service when Services are enabled; when Query Objects are enabled, use a Query Object for reusable read composition. POST/PUT/PATCH/DELETE Service calls retain the Action advisory. Imports and unused injected parameters are not dependency findings. Constructor dependencies are attributed to the methods that call them.

The audit follows reachable project method bodies without executing endpoints. Recognized model, builder, relation and DB mutations, jobs, mail and notifications produce `E_THIN_CONTROLLER_READ_SIDE_EFFECT` on a GET/HEAD endpoint, with a call chain and the operation's file/line. It examines called methods, not unrelated write methods in the same Service. A transaction alone is not a write; its callback is analysed.

`W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE` means the audit could not resolve a route/call/type, raw SQL, or a bounded traversal. It is a warning, and fails `--strict`. Absence of a finding means no effect was detected in the supported static subset, not proof that runtime hooks, model events, macros, magic dispatch, vendor SDKs or every possible branch are harmless. Dynamic SQL is never treated as a proved read. `W_THIN_CONTROLLER_READ_SERVICE` separately advises using enabled Query Objects.

Route discovery boots Laravel in a fresh child PHP process, with a process-local route-cache override. It does not dispatch a request or instantiate controllers, and does not clear the application's route cache. Normal application bootstrap code still runs. This keeps a long-lived MCP server from using routes from its initial boot. The timeout is 15 seconds; boot failure becomes an explicit incomplete-analysis finding when relevant. Programmatic callers without a Laravel bootstrap can pass a fresh `RouteMap::fromRoutes($router->getRoutes())` as the optional `routes` argument to `ApplicationAudit::run()`.

Analysis is limited to 12 method levels, 128 method visits and 20,000 AST nodes per endpoint, 100 KB per source file and 1 MB of retained source per controller, with an additional PHP memory headroom check. A cycle or exceeded budget is incomplete analysis. Source lookup stays in the configured project graph; excluded/out-of-scope dependencies are unresolved. The graph cache format is unchanged. In `--changed`, changed dependencies recheck their controller dependents; edits under routes/bootstrap/config/providers and deleted inputs recheck all controllers. Custom route-registration files outside those locations require a full audit.

## Installation

```bash
composer require gracjankubicki/laravel-architecture-kit
php artisan architecture-kit:install
```

Architecture Kit is a runtime dependency because the committed `config/architectures.php` references its `Architecture` enum while the application boots and caches configuration. Installing it only with `--dev` is unsupported and is blocked by install, doctor, and sync. Existing projects should follow [UPGRADE.md](UPGRADE.md) before updating to v0.2.0.

The install command is interactive. It asks which architecture patterns the project uses, writes `config/architectures.php`, and generates:

```text
.ai/guidelines/architecture-kit.md
.ai/skills/architecture-kit-{architecture}/SKILL.md
.ai/skills/architecture-kit-upgrade-{package}-{from}-to-{to}/SKILL.md
```

Before installing, inspect evidence-backed recommendations and the complete generated-file plan without changing the project:

```bash
php artisan architecture-kit:plan
php artisan architecture-kit:plan --agent
```

The planner recommends a pattern only when it finds explicit evidence such as an existing PHP file in the pattern's conventional folder, a relevant Composer dependency or a repo-local custom architecture guideline. Existing `config/architectures.php` selections remain authoritative. Missing evidence produces no recommendation, and the command never enables patterns or writes files.

It can also install agent integration without manual file editing:

```text
.codex/config.toml
.mcp.json
.codex/hooks.json
.claude/settings.json
.architecture-kit/hooks/guard.sh
```

If Architecture Kit is newly added to a project that already uses Laravel Boost, the installer can run one-time third-party discovery:

```bash
php artisan boost:update --discover
```

Boost then syncs the generated `.ai` resources into agent files such as `AGENTS.md`, `CLAUDE.md`, and other configured AI instructions.

For a fresh Boost project, generate Architecture Kit resources and then run `php artisan boost:install`. For recurring Composer or Laravel AI profile updates, do not rediscover packages; run:

```bash
php artisan architecture-kit:sync --no-interaction
php artisan boost:update --no-interaction
```

Architecture Kit remains usable without Boost through `.ai/**`, CLI commands, and MCP resources.

The generated `.ai/guidelines/architecture-kit.md` file is a compact index, not the full rulebook. It lists enabled architectures, folders, hard rules, global rules, and the guard command. Agents should expand details only when needed through:

```bash
php artisan architecture-kit:guidelines actions --agent
```

The same full guidance is also available through the generated `architecture-kit-{architecture}` skills, the MCP tool `architecture-rules`, and the MCP resource `architecture-kit://guideline`.

## Commands

```bash
php artisan architecture-kit:install
php artisan architecture-kit:install-agents
php artisan architecture-kit:install-agents --hooks
php artisan architecture-kit:mcp
php artisan architecture-kit:plan
php artisan architecture-kit:plan --agent
php artisan architecture-kit:upgrade-plan laravel/ai --to=0.10
php artisan architecture-kit:upgrade-plan laravel/ai --to=0.10 --agent
php artisan architecture-kit:doctor
php artisan architecture-kit:sync --no-interaction
php artisan architecture-kit:sync --dry-run --agent
php artisan architecture-kit:guidelines
php artisan architecture-kit:guidelines actions --agent
php artisan architecture-kit:file-rules app/Actions/CreateInvoice.php
php artisan architecture-kit:file-rules app/Actions/CreateInvoice.php --agent
php artisan architecture-kit:make actions CreateInvoice
php artisan architecture-kit:make actions CreateInvoice --agent
php artisan architecture-kit:context 'App\Actions\CreateInvoice' --agent
php artisan architecture-kit:context app/Actions/CreateInvoice.php
php artisan architecture-kit:guard --changed --strict
php artisan architecture-kit:guard --changed --base=origin/main --strict
php artisan architecture-kit:audit --changed --strict
php artisan architecture-kit:audit --changed --base=origin/main --strict
php artisan architecture-kit:audit --update-baseline
php artisan architecture-kit:audit --agent
php artisan architecture-kit:guard --agent
php artisan architecture-kit:doctor --agent
php artisan architecture-kit:explain E_THIN_CONTROLLER_MODEL_WRITE --agent
php artisan architecture-kit:cache-clear
```

`architecture-kit:install` is idempotent. Re-run it to change the selected architectures, the PHP runtime, or regenerate outdated `.ai` resources.

`architecture-kit:plan` is read-only. Before first install it recommends architectures from explicit project evidence; after installation it reports the configured selection. Both flows include requirement diagnostics and the predicted `create`, `update`, `remove`, and `blocked` resource changes. Use `--agent` for versioned JSON and `--schema` to inspect its contract.

`architecture-kit:upgrade-plan` is read-only. It requires a direct Composer package and an explicit target major.minor line, compares the declared constraint with locked and installed versions, then resolves a unique route through local atomic upgrade guides. The full route is visible, but only its first step is active. After completing and verifying that step, rerun the planner so it can derive the next step from the new project state. Use `--agent` for versioned JSON, `--schema` for its contract, or the MCP tool `plan-upgrade` from an AI agent.

`architecture-kit:sync` is the non-interactive recurring path. It reads the existing config, validates every enabled requirement and compatibility profile before writes, regenerates only marker-owned `.ai/**`, removes only stale marker-owned Architecture Kit skills, preserves unmanaged files, and never changes architecture selection. Use `--dry-run` for CI/agent preflight and `--schema` to inspect its JSON contract.

`architecture-kit:install-agents` bootstraps MCP and hook configuration for selected AI agents. It writes Codex MCP config to `.codex/config.toml` and Claude Code MCP config to `.mcp.json`, using the runtime from `config/architectures.php` as the initial wrapper command.

Agent integration files are developer-owned after creation. Re-running the command preserves existing Architecture Kit MCP entries that actually invoke `architecture-kit:mcp`, hook entries, `.architecture-kit/hooks/guard.sh`, and its README byte-for-byte. A valid existing config without the integration is merged safely; invalid JSON, an unrelated server under a reserved Architecture Kit key, or other incompatible configuration blocks installation instead of being overwritten. If the runtime or project-specific behavior changes later, edit these files directly.

In contrast, `.ai/guidelines/**` and `.ai/skills/**` are package-generated resources and may be regenerated by `architecture-kit:install`. `.architecture-kit/install.json` is internal package state.

`architecture-kit:doctor` is read-only. It reports missing, outdated, stale, or blocked generated resources and, when agents were installed, verifies that selected agent MCP and hook integrations still exist and remain parseable. Valid developer customizations are not treated as outdated.

`architecture-kit:guidelines` is read-only. Without an argument it lists known architectures with a one-line summary. With a slug it returns the full guideline for one architecture, even when that architecture is available but not enabled.

`architecture-kit:context` is read-only. It resolves one exact project FQCN or app-relative PHP path and returns a bounded static context: the subject role, direct dependencies and dependents with source evidence, current graph violations, files to inspect, and the next guard command. A path containing multiple symbols is rejected as ambiguous; use an exact FQCN. Use `--agent` for versioned JSON, `--schema` for its contract, or the MCP tool `architecture-context`.

`architecture-kit:audit` is read-only. It scans application code against the enabled file rules and the static project graph. Use `--changed --strict` before finishing AI-generated code so warnings and errors block the final handoff. In CI or after committing, pass `--base=origin/main` or another base ref to audit the committed diff.

Use `architecture-kit:audit --update-baseline` when adopting Architecture Kit in a legacy project. It writes the current findings to `.architecture-kit/baseline.json`; future audits suppress only the matching existing findings and still report new violations. Use `--no-baseline` to ignore the baseline for one run.

`architecture-kit:guard` is read-only. It combines `doctor`-equivalent generated-resource checks with the deterministic audit rules that are actually implemented. Guidance without a corresponding audit rule remains reviewer- and agent-enforced. Use `--json` for hooks and MCP tools.

### Project Architecture Graph

Architecture Kit parses every non-excluded PHP file in the audited scope into one deterministic graph. The scope is `app/` unless the project widens it. It records project classes, interfaces, traits and enums plus evidenced dependencies such as constructor and method types, inheritance, implementations, instantiation, static calls, class constants, enum cases and traits. Imports alone are not dependencies. Role classification follows architecture path segments in both top-level and domain-first layouts.

The graph distinguishes strong executable/type dependencies from weak context-only references. `SomeClass::class` and Eloquent relationship targets are visible to context, but do not independently trigger layer errors or namespace-cycle warnings. The graph is static: dynamic service-container bindings, runtime reflection and dependencies assembled from strings cannot be guaranteed.

Three graph-aware findings are available:

- `E_PORT_BYPASS` when application code depends on a concrete infrastructure adapter that implements an available port. Provider bindings remain valid composition-root code.
- `E_LAYER_DEPENDENCY` for a statically confirmed dependency from an inner domain/application/port role to an outer application, HTTP adapter or infrastructure role.
- `W_NAMESPACE_CYCLE` for a deterministic strongly connected component between project namespaces.

`audit.exclude`, inline ignores and the baseline apply to graph findings as they do to file findings. Changed-only audit still builds the full graph so an edited edge can reveal a cycle through unchanged files; reporting remains focused on findings anchored in changed source files.

### Before a change: what breaks and what to run

`architecture-kit:context` answers what a change to one symbol would break, not just what it is connected to. Each relationship carries an `impact` level derived from the edge the graph recorded:

| Level | Edge | What a change does |
|---|---|---|
| `breaking` | `extends`, `implements`, `trait` | The dependent stops loading |
| `signature` | `parameter`, `property`, `return` | The type system of the dependent catches it |
| `usage` | `new`, `static`, `instanceof`, `catch`, `attribute`, `class-constant` | The call site breaks |
| `context` | weak references such as `::class` | Readable, but nothing breaks |

Relationships are ordered by that level, so `--limit` cuts the least dangerous first. Sorting used to be alphabetical, which meant a subclass could fall out of the answer while a passing type reference stayed in it.

The same answer names the tests that cover the symbol, including those reaching it through another class, and `next` carries them as a ready `run_tests:` hint. That lets an agent run a narrow relevant set before the full suite instead of learning the effect from a red run. Coverage is resolved without building a project graph: files are filtered by the class's short name and only the matches are parsed, which keeps the cost in seconds even on a large application.

### After a finding: what is wrong here

`architecture-kit:explain` takes the reported path and line, not only the code:

```bash
php artisan architecture-kit:explain E_THIN_CONTROLLER_MODEL_WRITE \
    --path=app/Http/Controllers/InvoiceController.php --line=14 --agent
```

The answer then names the symbol at fault and, where the rule states a destination, carries a `proposal` describing what to move and where. Without a path the output is unchanged, so existing callers keep what they had.

A proposal appears only where the destination follows from the rule itself. Guessing it for the remaining codes would send an agent somewhere wrong with confidence, which costs more than saying nothing. Nothing here edits a file: the proposal is text to apply or reject.

### Audit scope beyond app/

Business logic closed inside a route file used to be invisible: the audit only read `app/`, so a green gate could mean nothing more than where the file was saved. A project can widen the scope:

```php
// config/architectures.php
'audit' => [
    'paths' => ['routes'],
],
```

A route closure that validates a request, writes to a model, opens a transaction, or dispatches a job is then reported as `route-logic`, using the same signals the package already applies to controllers. Files outside the scope stay unread, so a project that does not change its configuration gets exactly the audit it had before upgrading.

### Missing tests

Skipping tests is a systematic weakness of coding agents, and guidance written in prose does not change a gate result. The `missing-test` rule reports an architecture element that no test depends on:

```php
// config/architectures.php
'audit' => [
    'missing_test' => 'warn', // 'off' (default), 'warn', 'error'
],
```

The rule reads the dependency graph rather than a naming or folder convention, so it covers every architecture wherever its elements live, and a test that exercises an element through another class still counts. Classless Pest tests count too: a file with no class of its own contributes its dependencies to the graph under a stand-in symbol.

Interfaces and traits are not reported, because neither is tested on its own. An enum is reported only when it declares methods: a plain set of cases has nothing to assert beyond the language itself, while a method on an enum is behaviour like any other.

Enabling it also brings `tests/` into the audited scope. That is not optional: without test files in the graph the rule would see no test for anything and report every class, including well covered ones. Rules written for application code never fire inside test files.

The rule is off unless a project asks for it. Measured on an application with 6305 files under `app/` and 5153 test files, enabling it by default would have produced roughly 700 findings on the first run after an upgrade.

### The project graph is built once

Building the graph means parsing every PHP file in scope, which is 87% of the cost and takes about 19.5s on an application with 11566 files. `guard --changed` paid it on every run: its rules only check the files you touched, but a changed edge can reveal a cycle through a file you did not, so the graph still had to cover the whole project.

The graph is now kept between runs, per file, so a run parses only what moved:

```php
// config/architectures.php
'audit' => [
    'cache' => false,              // turn it off
    'cache' => 'storage/graphs',   // or put it somewhere else
],
```

Measured on that application, with `app/` in scope and nothing edited between runs:

| | Without the cache | With it |
|---|---|---|
| `guard --changed` | 6.44s | 0.47s |
| Full `audit` | 6.57s | 6.63s |
| `context` on one symbol | 19.67s | 0.72s |

A full audit gains nothing, and that is not an oversight: its rules need the syntax tree of every file they check, so those files are parsed either way. The cache removes parsing that nothing else needed, which is what `--changed` and `context` are made of.

Staleness is the risk, not speed: a graph that describes code the project no longer has would report findings for deleted code and stay silent about new code, with nothing in the output to say so. An entry is discarded when the enabled architectures, custom rules or audited scope change, and when the package itself changes, which is decided by hashing its sources rather than by a version constant somebody has to remember to bump. A file is parsed again when its modification time or size moves, and the run is driven by the files the project actually has, so a deleted file leaves the graph and a contribution missing from the entry is rebuilt rather than assumed. The stored file carries a hash of its own contents, because a damaged entry can still be well formed: one stripped of its symbols looks exactly like a file that declares none, and no structural check can tell those apart.

File state is stat rather than a content hash: hashing the same project costs 1.58s against 0.02s, on every run including the ones that hit the cache, and it would eat most of what the cache saves. The gap that leaves is narrow and named: content changed while both timestamp and size stayed identical, which `rsync -a`, an archive unpacked with its timestamps, and `touch -r` can do but Git, editors and agents cannot. `php artisan architecture-kit:cache-clear` is the answer to it, and to any entry you no longer trust. A corrupt or unreadable entry is not an error: the command rebuilds and moves on.

A rebuild answers correctly, which is why a rejected entry would otherwise be invisible while costing a full build on every run. When an entry is unreadable, or too large to restore inside the remaining memory budget, `audit`, `guard` and `context` all say so: `--agent` output carries a `cache` field and human output prints a note. An ordinary first run says nothing, because there is nothing to report.

The default location is `storage/framework/cache/architecture-kit`, which Laravel already ignores: the skeleton's `storage/framework/cache/.gitignore` excludes everything under it except `data/`. The entry is sizeable, 33.8 MB for 11629 symbols and 198858 edges, so it is not somewhere a project should commit by accident. Restoring it peaks at about six times that in memory and writing it adds more, so the cache checks the remaining budget first and steps aside rather than pushing the process past `memory_limit`. After moving or disabling the cache the old directory is no longer in the configuration, so `cache-clear` takes `--path=` for that case.

Recommended agent flow:

```bash
php artisan architecture-kit:context 'App\Actions\CreateInvoice' --agent
# inspect returned files and implement the approved change
php artisan architecture-kit:guard --changed --strict --agent
```

For example, when context classifies `App\Actions\FetchDocument` as `application` and returns `App\Documents\Ports\DocumentGateway` as a strong, allowed `port` dependency, the agent should inspect the Action and contract, preserve port injection, and avoid loading or depending on the concrete HTTP adapter unless the approved change reaches that boundary. If the Action points directly to the adapter, context reports `allowed: false` together with `E_PORT_BYPASS` and `E_LAYER_DEPENDENCY`.

This flow proves that the CLI/MCP payload carries the boundary, relevant files and next guard needed for the maintenance decision. It is not an independent benchmark across LLM providers or a guarantee about dependencies assembled dynamically by Laravel's runtime container.

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
php artisan architecture-kit:plan --schema
php artisan architecture-kit:upgrade-plan --schema
php artisan architecture-kit:context --schema
```

For cheap on-demand rule expansion:

```bash
php artisan architecture-kit:guidelines --agent
php artisan architecture-kit:guidelines actions --agent
php artisan architecture-kit:guidelines --schema
```

To install only hook integration through the merge-aware agent installer, use `architecture-kit:install-agents --hooks`:

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

`config/architectures.php` stores how the project runs PHP. When `runtime` is omitted, Architecture Kit uses local PHP as the default runtime.

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
- `Laravel AI` requires `laravel/ai` directly in root runtime `require`, an installed version consistent with `composer.lock`, and a declared constraint fully contained in the verified support ranges.

Laravel AI compatibility:

| Architecture Kit profile | Supported `laravel/ai` versions | Structured response | Provider options |
| --- | --- | --- | --- |
| `laravel-ai@0.8` | `>=0.8.0 <0.9.0` | `toArray()` or ArrayAccess | Laravel AI 0.8 contracts |
| `laravel-ai@0.9` | `>=0.9.0 <0.10.0` | `toArray()` or ArrayAccess | `withProviderOptions()` where applicable |
| `laravel-ai@0.10` | `>=0.10.0 <0.11.0` | `toArray()` or ArrayAccess | `withProviderOptions()` plus approval resumption |

Constraints such as `^0.8`, `^0.9`, `^0.10`, `^0.8 || ^0.9 || ^0.10`, and `>=0.8 <0.11` are supported. Constraints that also permit `0.11`, `1.x`, or development branches fail closed. Architecture Kit never guesses that the newest known profile is compatible with an unknown Laravel AI release.

Only the profile selected from the actually installed Laravel AI version is generated at `.ai/skills/architecture-kit-laravel-ai/SKILL.md`. Architecture Kit owns the application architecture overlay; exact SDK features remain in the official `ai-sdk-development` skill shipped by the installed `laravel/ai` package.

## Versioned package upgrade guides

Architecture Kit can ship atomic, evidence-first upgrade guides as generated AI skills. These are instructions for an agent working in the consuming repository, not deterministic codemods: the agent must inspect the real dependency state, classify each breaking change by applicability, follow the repository's Target State and plan gates, make only approved changes, run the project's verification, and return a requirement-evidence handoff.

The Laravel AI architecture currently generates:

```text
.ai/skills/architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md
.ai/skills/architecture-kit-upgrade-laravel-ai-0-9-to-0-10/SKILL.md
```

Each guide represents one atomic version edge. A request to upgrade `0.8 -> 0.9 -> 0.10` must complete and verify the first guide before loading the second. The guides distinguish mandatory, conditional, informational, and blocked work so an agent does not apply an application migration or contract change without evidence that the project uses it.

Before loading a guide, resolve the route from the real project state:

```bash
php artisan architecture-kit:upgrade-plan laravel/ai --to=0.10
```

The planner accepts only a direct dependency with a valid constraint, matching locked and installed stable versions, an enabled guide architecture, and a current marker-owned skill for the active edge. Missing state returns `blocked`, a route gap returns `unsupported`, and multiple complete routes return `ambiguous`; none of these outcomes changes project files. Target versions are explicit and local—Architecture Kit does not query for or infer the latest release.

Future package transitions follow `resources/upgrades/{package}/{from}-to-{to}/SKILL.md`. The source skill declares its package, architecture and version edge in frontmatter. Install, plan, sync and doctor then reuse the same marker-owned resource lifecycle as architecture skills; no new mutation command or remote recipe execution is introduced.

On the first install (before `config/architectures.php` exists), `Services` is preselected when the project already has an `app/Services` folder, and `Laravel AI` is preselected only when a valid supported runtime `laravel/ai` installation is detected.

Architecture Kit does not add Composer post-update hooks. After dependency updates, run the explicit sync commands shown above so validation failures remain visible and cannot silently rewrite project files.

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

Custom audit rules must return valid `AuditFinding` objects. Severity is exactly `error` or `warn`; the rule is a non-empty kebab-case slug; path and message are non-empty; and line is at least 1. Occurrence, when provided, is at least 1. A custom code is optional, but when present it must use the `E_*` or `W_*` uppercase underscore format. Invalid findings throw immediately and fail audit/guard instead of being silently ignored. Projects upgrading custom rules must correct their finding construction before enabling the stricter package version.

The public Ports And Adapters rule requires a real boundary reason in PHPDoc, but does not prescribe its language. A project that requires bilingual Port documentation can enforce it with its own `AuditRule` scoped to the architecture:

```php
'rules' => [
    'ports-and-adapters' => [
        App\Architecture\Rules\RequireBilingualPortDocumentation::class,
    ],
],
```

### Suppression

Inline suppression is for reviewed false positives only. Always name the rule and include a reason:

```php
// @architecture-kit-ignore thin-controller -- legacy endpoint accepted in PR #123
$invoice->update($payload);
```

The directive can also be placed in a multi-line docblock. It applies to findings
inside the comment and to the line immediately after the comment. Repeat the
directive in one comment to suppress several rules:

```php
/**
 * @architecture-kit-ignore thin-controller
 * @architecture-kit-ignore actions
 */
```

File-level suppression is also rule-specific:

```php
// @architecture-kit-ignore-file query-objects -- generated legacy query object
```

Unknown suppression rules are reported as `invalid-suppression` warnings and do not hide the original finding.
Known inline rules that do not match any finding are also reported as
`invalid-suppression` warnings with an `Unused` message, so stale suppressions
are visible instead of being silently ignored.

Baseline files use schema version 2, which fingerprints severity as well as rule, path, and message. A legacy version 1 baseline is rejected because it cannot distinguish a warning from a later error; recreate it deliberately with `php artisan architecture-kit:audit --update-baseline`.

### Optional Project-Owned Composer Update Check

Architecture Kit does not install Composer hooks. If the project deliberately owns one, it may use this read-only hook to detect stale generated resources after package updates:

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

The MCP server exposes read-only tools for enabled architectures, generated rules, project architecture context, package upgrade plans, doctor state, changed-file audit, guard state, and finding explanations. It does not regenerate files, install hooks, run migrations, write code, or mutate application data.

## Development

```bash
composer install
composer test
composer lint
bash tests/Smoke/workbench-commands.sh
```
