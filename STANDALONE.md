# Use Architecture Kit outside the analyzed repository

The standalone executable reads Laravel applications and standalone Composer packages using its own dependencies. Configuration, graph cache, saved reports, task beginnings and generated integration files stay outside that repository. Installing the executable does not enable AI hooks or change any agent settings.


## Basic analysis and advisory control

Basic is the default on CLI, MCP and hooks, including existing profiles. It maps project source, including Modules, checks local architecture rules and demonstrated call/interface mismatches, and lists candidate tests. Vendor is a boundary. Candidates and relationships do not prove execution, test coverage or business correctness.

Read `analysis.mode`, `analysis.checks_complete`, `analysis.deep_run` and the real `ok`/status. `control.blocking` independently describes the reaction. Errors, timeouts, missing beginnings and stale results remain failed or unverified. Advisory permits continuing and completing a turn with an explicit handoff of remaining errors and unverified checks. Try to fix new errors first. For an important gap, search and read source, cite the file and relationship or describe uncertainty. Ask the human when missing information prevents choosing a correct change.

`--strict` changes warning evaluation. `--full` expands the report. Neither enables Deep or blocking. Use `--enforce` on audit/guard/task, MCP `enforce=true`, or explicit `control.policy=enforce` for blocking. `audit.incomplete_analysis=block` affects analysis completeness, and does not enable Enforce or Deep. Guard and task control check Basic. Skip remains `ok:false` and keeps its existing ability to release completion, including in enforced hooks.

Deep preserves semantic execution/effect analysis in the same package. Agents must ask the human before using it, then record the obtained consent for one repository and task. Standalone examples:

```sh
architecture-kit analysis-consent grant-deep TASK --confirm
architecture-kit audit --agent --mode=deep --task=TASK
architecture-kit impact 'App\Service::run' --agent --mode=deep --task=TASK
```

For local Artisan use `php artisan architecture-kit:analysis-consent TASK --confirm`, then add `--mode=deep --task=TASK` to audit, search, context, impact, path or reach. Full revision-diff and target assessment require explicit Deep and consent as well. Guard audit stays Basic; adding --target requires Deep and a consented task. file-rules reads only target declarations in Basic, with full assessment marked not_run, and reports invalid declarations without losing ordinary rules. MCP uses `analysis-consent` with `task` and `confirm=true`, then `analysis_mode=deep` and the same `task`. On architecture-graph, `mode=context|path|impact` still selects the query; `analysis_mode` selects Basic or Deep. Retry/resume retain consent. Another task or repository needs another grant. A confirmation flag records the caller statement and does not authenticate a human. Refusal leaves ordinary reading and Basic search available. Deep never grants runtime routes or custom PHP execution.


## Install an isolated global tool

The tool process requires PHP 8.3 or newer and the extensions required by Laravel Framework and Composer. Its PHP version is separate from the application's PHP version. Static parsing of a project does not certify compatibility with an older Laravel release.

Composer's global directory can contain other tools with conflicting dependencies. Use `bamarni/composer-bin-plugin` to give Architecture Kit a separate dependency namespace:

```sh
composer global config allow-plugins.bamarni/composer-bin-plugin true
composer global require bamarni/composer-bin-plugin
composer global bin architecture-kit require gracjankubicki/laravel-architecture-kit
```

Find the namespace's actual binary directory with `composer global bin architecture-kit config bin-dir --absolute`. Composer may inherit a global `bin-dir`, so do not assume the executable is under the namespace's `vendor/bin`. Add the reported directory to your shell's `PATH`, or use the absolute executable path.

Update with `composer global bin architecture-kit update gracjankubicki/laravel-architecture-kit`. Updating the tool invalidates task comparisons made with a different tool source fingerprint. Finish or explicitly skip the previous task before starting a new task under the new version.

## Select and inspect the analysis engine

Mago is the default engine for new and previously configured projects. It runs as a separate Rust executable. Install its matching binary outside the analyzed repository:

```sh
architecture-kit engine install
architecture-kit engine status
architecture-kit --project /absolute/path/project engine describe
```

The distribution supports macOS and Linux on ARM64 and x64. Linux binaries use static musl linking and also run on glibc systems. Windows is outside the binary distribution. Users do not need Rust. The installer verifies the release manifest, size, SHA-256, protocol and executable version before replacing an installation. A release that defaults to Mago requires all matching binaries to be published first.

Package v0.11.0 requires engine 0.2.0, based on Mago 1.57.0. Engine 0.1.0 does not provide the new Basic facts capability. Once the matching binary release is published, run `architecture-kit engine install` using the upgraded executable, then check `engine status` and the project's `engine describe`. Use the same `--tool-home` as the selected installation if it is not the default. Restart existing MCP sessions after upgrading; running processes retain their loaded tool code and engine identity.

Updating the Composer package and installing the native engine are separate operations. Neither step enables hooks, changes project profiles or grants Deep/runtime permissions. Do not interpret the package release as authorization to upgrade or activate a user's global integration.

To use PHP explicitly, add this to the selected JSON profile or shared settings:

```json
{
  "analysis": {
    "engine": "php",
    "php_version": "8.3"
  }
}
```

Omit `php_version` to use the project's `composer.json`: `config.platform.php` takes precedence over the minimum concrete version allowed by `require.php`. An explicit version must satisfy `require.php`. Missing or unbounded requirements need an explicit target. The host PHP version is never the fallback. Historical comparisons read the target from each revision, and fail explicitly if historical settings cannot be decoded.

A missing, incompatible or failed Mago executable stops the analysis with a reason and installation instructions. It never switches to PHP automatically. `engine describe` and MCP resource `architecture-kit://analysis-engine` report the selected engine, version, implementation fingerprint, schema, PHP target and setting origin. An explicitly selected local profile can override shared engine settings; diagnostics identify that override.

Syntax errors identify the file and location, block a successful control result, and cannot be suppressed by a baseline or inline ignore. Other files are still analyzed. Unsupported valid syntax is a separate engine error. Changing engines, binaries, tool sources or configuration invalidates caches and task comparisons without refreshing an existing task beginning. Start a new task only at an authorized task boundary.

## Prepare and collect a Basic map

Standalone CLI and MCP share a Basic map runtime for the selected repository, profile and analysis identity. It launches tool-only background processes and keeps ready generations in worker memory. Preparing a map does not bootstrap application PHP or execute its jobs.

```sh
architecture-kit --project /absolute/path/project map prepare
architecture-kit --project /absolute/path/project map status JOB_ID
architecture-kit --project /absolute/path/project map result JOB_ID
```

`prepare` reuses a verified ready generation or starts a build. It waits within its request budget and can return `building` with `job_id` and a suggested next call. Keep that job ID, continue ordinary source inspection, and use `result` to collect it. Disconnecting the CLI or MCP client does not cancel the background build.

MCP exposes the same actions through `architecture-map`:

```json
{"action":"prepare"}
```

```json
{"action":"result","job_id":"<job_id returned by prepare>"}
```

| Field or action | Interpretation |
| --- | --- |
| `availability` | `building`, `ready`, `failed` or `unavailable`; readiness is separate from freshness and completeness |
| `status` action | Inspect the job without rechecking source inputs; a historical ready state is returned as `freshness: unverified` |
| `result` action | Collect current metadata; a ready worker checks its source inputs before reporting freshness |
| `freshness` | `current`, `stale` or `unverified`; it never follows from a ready job record alone |
| `checks_complete`, `analysis` | Analysis completeness within the supported static scope; a current generation can remain partial |
| `cache.read`, `cache.write` | Disk reuse and persistence outcomes; RAM readiness does not imply a successful cache write |
| `deadline_exceeded`, `request_deadline_exceeded` | Recorded budget overruns where applicable; a late or still-building job does not prove the budget was met |
| `snapshot_id`, `snapshot_ref` | Identity of the prepared generation; job metadata does not contain the full graph |

Queries such as search and graph use the prepared catalog. Context, impact and related full products keep their own product keys and scopes. Their preparation must not be replaced by a catalog result. Public query responses retain their existing facts and pagination; lifecycle metadata appears under `runtime`, or as the top-level lifecycle response while the product is pending.

A graph query that reaches its traversal limit remains incomplete even when the resident generation is complete. Its `traversal_limit` diagnostic belongs to that response and does not change the generation's diagnostics or later full queries. Request budgets are checked after queries on every product path; a late response keeps its facts and freshness while recording `deadline_exceeded` in the job.

The coordinator retains at most 64 job records in memory. Admission can discard the oldest failed or unavailable records, protecting active generations, queued/running builds and in-flight queries. An expired job ID returns `unknown_or_expired_job`; it never identifies a replacement job. This history limit does not delete graph cache data or invalidate an active writer.

The worker checks source and tool identity before and after each actual query. A change during the read marks the result stale. Content checks detect same-size edits even when timestamps stay unchanged. Added/deleted files, scope or configuration changes, engine/tool upgrades and cache clearing also invalidate reuse. Restart search or the first page after `stale_snapshot`; do not combine pages from different snapshots.

Disk storage retains validated per-file contributions and prepared catalog state outside the inspected repository. A later worker can restore unchanged facts without parsing them again, but it still verifies current inputs. Corrupt, incompatible or unreadable cache entries are rejected. Disabled caching or a failed write can leave a usable RAM generation without persistent reuse. `architecture-kit cache-clear` invalidates stored state and resident generations; an older worker cannot publish into the new cache generation.

Replaced workers retire after their in-flight queries finish. Workers can exit after 300 seconds without requests; the coordinator can exit after 300 seconds without an active build or client. A later request starts the required processes and restores or rebuilds state. There is no filesystem watcher, and idle retention is not a persistence guarantee.

Background execution requires macOS or Linux with Unix-domain sockets, file locking and PHP process-launch functions available, including `exec` and `proc_open`, plus the shell's `nohup` command. The native engine distribution targets ARM64 and x64. Selecting the PHP backend does not add Windows support to this background runtime. PHP 8.3 or newer is still required for the tool itself.

The current request budgets distinguish cold/major preparation at 20 seconds, unchanged restore at 2 seconds and single-file changes at 3 seconds. They are not guaranteed completion times, and crossing a budget does not cancel an independent build. This release does not guarantee those targets or a 100 ms ready query. Source verification, setup, IPC and response handling remain part of the observed request cost. Performance acceptance remains open.

## Apply architecture to a Composer package

A package does not need `artisan`, `app/`, an `App` namespace or a Laravel bootstrap. For example, a library with `Example\Payments\` mapped to `lib/` can use:

```sh
architecture-kit --project /absolute/path/payments init actions data-objects ports-and-adapters
architecture-kit --project /absolute/path/payments guidelines --agent
architecture-kit --project /absolute/path/payments make actions ChargePayment --agent
architecture-kit --project /absolute/path/payments make actions ChargePayment --allow-write
architecture-kit --project /absolute/path/payments audit --agent
```

The proposal uses `lib/Actions/ChargePayment.php` and `Example\Payments\Actions`. The first `make` command previews files. The second explicitly writes them. The same repository can use exported hooks, MCP and task control. Existing files are not overwritten.

Production source roots come from Composer PSR-4, PSR-0, classmap and autoload files, or explicit audit scope. Scaffolding requires an unambiguous directory and namespace. With multiple roots or a custom layout, specify placements in the selected profile:

```json
{
  "placements": {
    "actions": {
      "path": "lib/Domain/Payments",
      "namespace": "Example\\Payments\\Domain\\Payments"
    }
  }
}
```

Guidance, generated code and audit use this same placement. Plain PHP packages get package-specific examples and defaults for Actions, Services, Data Objects, Value Objects, Enums and Ports and Adapters. Select additional rules explicitly. Laravel packages can select rules for their providers, Eloquent models, requests and other framework elements without creating an artificial Laravel application. This workflow does not add dependency-source discovery in application `vendor` directories.

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

Each export writes `hooks.json`, `mcp.json` and `status.json` under the external integration directory printed by the command. It does not install them into the agent. The exported commands preserve the executable used to invoke the tool, including the Composer proxy that supplies its isolated autoloader. They use absolute paths and pin the selected project and optional profile. Hooks do nothing for a different or unregistered project. MCP stays pinned to its canonical project for the entire process; tool arguments cannot switch it.

Merge the generated hooks into the client's **user** settings, preserving other hooks:

- Codex reads user hooks from `~/.codex/hooks.json`. Configure an MCP server in the user's Codex settings using the generated command and argument list. Review and trust the exact hook definitions through `/hooks`; changed hook definitions need renewed trust.
- Claude Code reads user hooks from `~/.claude/settings.json`. Add the generated MCP command through Claude Code's user-scoped MCP configuration. Preserve existing settings and restart the session after installation.

The export has `active: false`. `integration status codex` or `integration status claude` reports historical hook observations, including the last event and check status. An observation is evidence that a hook ran; it does not prove the current settings are enabled or trusted. Verify a fresh real session in the client. Without running trusted hooks, MCP instructions alone cannot enforce a completion check.

## Keep one beginning throughout a task

At a fresh `SessionStart` with source `startup`, the hook captures the existing audit findings before edits and injects the selected guidance. Existing uncommitted changes are included. Subsequent prompts, corrections and resumed sessions retain this beginning. A resumed or compacted session without a known beginning remains unverified and reports that gap; it cannot invent a baseline after edits. Advisory permits continuing source inspection.

The agent can also use the standalone MCP `task-control` tool. CLI equivalents are:

```sh
architecture-kit task begin invoice-task
architecture-kit task bind invoice-task --client codex --session CLIENT_SESSION_ID
architecture-kit task check invoice-task
architecture-kit task status invoice-task
```

Beginning the same task again returns its original record. To start another task in the same chat, first complete the previous task or explicitly skip it, then begin a new named task and bind that task to the client session. Binding cannot replace an unfinished task. New task names represent human-approved task boundaries, not a way to hide a failing result.

The completion check compares the current multiset of enforced errors with the initial multiset. Moving lines does not create a new error; another occurrence of the same error does. Earlier errors are counted separately and warnings do not block. All current changes participate, including parallel edits by other people; the result does not infer authorship. Do not automatically repair other people's changes.

Partial analysis warns by default. A task can capture a partial beginning and pass when it finds no new recognized errors. Read `begin_analysis`, `analysis` and `reason` even when `ok` is true. These fields preserve the analysis status, selected policy, notice counts and counts by reason, without storing source messages. Inspect `audit --agent` for detailed notices and findings. A partial pass does not prove a complete code check or passing tests.

Set `audit.incomplete_analysis` to `block` to require complete analysis. The only accepted values are `warn` and `block`; omitting the setting selects `warn`, including for existing profiles. In an external JSON profile:

```json
{
  "audit": {
    "incomplete_analysis": "block"
  }
}
```

The same key works in shared settings and `config/architectures.php`. Task control, MCP and both clients' hooks use this policy. Standalone and Artisan guards use it too, including with `--strict`. The guard still reports every warning; strict mode marks ordinary architecture warnings unsuccessful; only explicit Enforce blocks, while the five built-in analysis-only warnings follow the selected completeness policy. Raw `audit` preserves finding counters; advisory exit 0 does not mean PASS.

Missing beginnings, changed configuration/tool policy, unreadable required inputs and audit failures never produce PASS. A source-discovery limit that returns a partial report follows the completeness policy; a failed audit cannot pass. Changing the policy or upgrading the tool does not recreate old task beginnings. A failed beginning remains unavailable, and an incompatible beginning requires a new explicitly approved task boundary before edits. The Stop hook reports failures without blocking by default. Explicit Enforce asks for correction and retains existing retry/attempt limits. When another continuation would loop, it releases the turn with an explicit failure message; the task remains unverified. Report the blocker rather than treating released completion as a passed audit.

A human can explicitly skip a known task:

```sh
architecture-kit task skip invoice-task --confirm
```

A skipped task remains `ok: false`, `status: skipped`. It does not certify code or tests. A passed record becomes stale after a source or configuration change. Architecture PASS only means no new recognized enforced errors; application tests still need separate verification.

## Execution and repository writes

Default analysis does not load the application's PHP autoloader, configuration or bootstrap. In explicit Deep, controller checks use project-local route declarations, including groups, providers and included files, through bounded source discovery. Source candidates carry their location and do not prove runtime registration. Unresolved handlers, declarations, missing sources and discovery limits retain recognized routes and mark analysis partial. They warn by default and block task verification when `audit.incomplete_analysis` is `block`. Runtime framework facts remain unavailable; calls that require them can still make an endpoint analysis incomplete. Static search, relationships and guidance remain available.

`--allow-execution` opts into runtime route discovery in a child process. It does not install the package into the application or override unsupported runtime requirements. Custom PHP rules require this flag and must be available to the tool's autoloader; unavailable rules produce an error rather than being silently omitted. The tool does not mix an arbitrary application's autoloader into its host process.

Use `--runtime-routes` to opt into fresh route registration snapshots without enabling custom PHP rules:

```sh
architecture-kit --project /absolute/path/crm-backend --runtime-routes audit --agent
architecture-kit --project /absolute/path/crm-backend --runtime-routes integration export codex
```

The default driver starts the application's bootstrap in a separate PHP process. For an existing Docker Compose container, add this to the selected external profile:

```json
{
  "runtime": {
    "routes": {
      "driver": "docker-compose",
      "service": "laravel.test",
      "user": "glengarry",
      "project_path": "/home/glengarry/app"
    }
  }
}
```

The Compose driver runs `docker compose -f docker-compose.yml exec -T -u <user> <service> php` from the selected repository. Both runtime drivers pass the parent PHP memory limit and disable PCOV collection only in the route process. It streams the tool-owned snapshot worker through stdin, without installing the package or copying files into the application. It does not start or recreate containers. Package, service and route cache paths use a temporary directory outside the repository and are cleaned at process shutdown. Application bootstrap still executes providers and application code, which can have its own side effects. The worker does not instantiate controllers or dispatch endpoints.

Each explicit Deep audit with separate runtime permission starts route registration after source analysis, then immediately drains the process output. Large snapshots cannot remain blocked on a full pipe while source files are analyzed, and the source phase does not consume the 30 second registration budget. Registration and source analysis run sequentially.

Each permitted Deep runtime audit gets a fresh snapshot for the selected application environment. Basic task and guard checks do not request runtime snapshots. It does not certify all deployments or prove that an endpoint executed. Failure, invalid settings, a 30 second timeout or more than 8 MB of output keeps analysis unverified. Advisory still permits completion. Exporting with this option preserves it in both MCP and hooks and sets the hook timeout to 300 seconds. Exporting without it keeps source-only hooks and their existing 120 second timeout. Changing this option or the profile invalidates earlier task comparisons; it never refreshes their beginning automatically.

Enum and Ports and Adapters rules index file names once per audit, without retaining application syntax or persisting the index between audits. Controller analysis normalizes fully qualified handler identities and reuses bounded syntax only within the current audit. Each handler and middleware combination is analysed once, while every route retains its own witness and HTTP-verb checks. Source limits and memory thresholds remain enforced; optional cached syntax is discarded under memory pressure. Runtime context includes observed class aliases, not guesses based on short names. Project implementations take precedence over framework descriptions. Missing or out-of-scope route handlers retain their witnesses and make endpoint analysis incomplete. External dependency methods remain unresolved when their sources are outside the audited graph, and an incomplete result follows `audit.incomplete_analysis`, warning by default or blocking when explicitly configured.

Scaffolding proposals through MCP or `make --agent` write nothing. `make` without `--agent`, baseline updates and `sync` require `--allow-write` in standalone mode. `sync --dry-run` previews its repository files. The graph cache and paginated reports remain in external storage even when execution or writes are allowed.

## Audit reuse and freshness

Full standalone audits share bounded source syntax, declaration indexes, provider facts and confirmed function/agent relationships. The selected engine uses the project PHP target; syntax trees, namespaces and source positions remain owned by each file. Each source index reuses up to 4,096 successful root method lookups; unresolved and recursive depth-dependent lookups remain uncached. Large full audits can use up to eight tool-only workers per phase. Workers use the tool's autoloader, preserve the parent's PHP memory limit and disable PCOV coverage collection only in their own processes. Worker failure or an invalid/budget-exceeding response falls back to serial analysis. Route witnesses are sent once per distinct witness and reconstructed before results are merged; HTTP verbs and middleware contexts remain separate. Facts are transferred in chunks of at most 64 entries to avoid restoring a large cache payload alongside the full report.

The additional analysis cache stores only hashed keys and confirmed absence/relationship flags under the external repository storage directory. Each entry is limited to 3 MB and 10,000 facts per channel, with at most four completed identities retained. It does not store source text, syntax trees, method argument values, runtime route snapshots or full audit reports. `audit.cache: false` disables persistence while still allowing facts already proven in the current audit to be shared. Missing, corrupt or unwritable entries supply no cached evidence.

The cache identity includes the profile, configuration, tool sources, execution mode, PHP version and SHA-256 source contents. Content checks detect edits even when file size and modification time are unchanged. Generated coverage, PHPUnit caches and Laravel view/cache/session/testing files do not affect this identity unless the project explicitly includes them in audit/graph paths or Composer helper files. Vendor sources remain outside analysis. Each runtime-enabled audit still obtains fresh routes. Registration starts alongside independent source checks, and the audit awaits the complete route snapshot before endpoint analysis. A failed or abandoned audit stops its pending route process. A full audit releases restored graph contributions before collecting their replacements, because its rules must read every in-scope file.

The full audit verifies source and configuration hashes before publishing cache facts. Task checks reuse this verified source fingerprint instead of scanning the same inputs twice more. Task status still checks whether a previously passed result is current. A running MCP process rejects modified tool sources and requires a restart, because its loaded PHP classes cannot be replaced by files changed on disk.

Task locks cover reservation and publication rather than the full audit. Only the first caller may capture a task beginning. Concurrent callers and interrupted captures remain unverified; retries cannot replace the reserved beginning. An explicit skip wins over an in-flight audit, and a check cannot publish findings against task state replaced by another check. Cache clearing leaves task beginnings intact.

Full CLI JSON reports bypass terminal formatting and use bounded output writes without dropping records. Full standalone MCP audit/guard responses use incremental JSON serialization, preserving the SDK dispatch and both text and structured content on the same JSON-RPC line. The server releases the response and unused allocator pages after transmission so subsequent audits retain their memory headroom. Unicode, escaping and invalid-data validation are covered by tests.

These mechanisms do not establish a universal runtime guarantee. The selected CRM/Glenross acceptance limit is 20 seconds per complete invocation. Acceptance requires separate end-to-end measurements for CLI, new/running MCP and hooks on each selected repository, including cold cache, source changes, fresh routes and lock waits. The final measurement series was stopped at the user's request before completion and includes invocations exceeding 20 seconds. Local use does not establish that performance target.

## Reference

CLI supports map preparation/status/result, audit, guard, doctor, context, search, impact, path, reach, public API, revision comparisons, target architecture, file rules, guidelines, explanation, upgrade planning, scaffold proposals, sync and cache clearing. Use `COMMAND --help` for the reused command's options. The map lifecycle is a standalone facility; Laravel's existing `php artisan architecture-kit:*` commands retain their normal behavior.

Official integration contracts:

- [Composer global tools](https://getcomposer.org/doc/03-cli.md#global)
- [Composer vendor binaries](https://getcomposer.org/doc/articles/vendor-binaries.md)
- [composer-bin-plugin](https://github.com/bamarni/composer-bin-plugin)
- [Codex hooks](https://learn.chatgpt.com/docs/hooks)
- [Claude Code hooks](https://code.claude.com/docs/en/hooks)
- [Claude Code settings](https://code.claude.com/docs/en/settings)

## Candidate, publication, upgrade and activation

Local tests produce a candidate, not an installed integration. After independent QA and final review, publication and release version need separate authorization. Upgrading the isolated global tool is another explicit step; preserve the selected profiles and external task state. Activation for a named repository/client needs separate authorization and actual readback of the installed version, selected repository/profile, MCP registration and hook configuration. Verify a real client event and a source-only query afterward. Exported settings or historical observations do not prove current activation. Preserve developer-owned scripts and application repositories while preparing the candidate. Runtime/bootstrap permissions remain separate from activation. Publishing v0.11.0 does not close the open graph performance targets.
