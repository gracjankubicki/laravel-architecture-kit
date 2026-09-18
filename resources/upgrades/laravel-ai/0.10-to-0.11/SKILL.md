---
name: architecture-kit-upgrade-laravel-ai-0-10-to-0-11
description: Upgrade a Laravel application from laravel/ai 0.10 to 0.11 using the tagged SDK contract and an evidence-first workflow.
metadata:
  architecture: laravel-ai
  package: laravel/ai
  from: "0.10"
  to: "0.11"
---

# Upgrade Laravel AI 0.10 to 0.11

Use this skill only after the application is verified on Laravel AI 0.10. For a 0.8 or 0.9 starting point, run `architecture-kit:upgrade-plan laravel/ai --to=0.11` and complete each earlier atomic guide first.

## Sources of truth

- Official tagged guide: https://github.com/laravel/ai/blob/v0.11.2/UPGRADE.md#upgrading-to-011-from-010
- Tagged source: Laravel AI v0.11.2, commit `ee2c5162838d440c4e2e629ea93c8c87e838eaed`
- Release comparison: https://github.com/laravel/ai/compare/v0.10.3...v0.11.2
- The application's `composer.json`, `composer.lock`, installed package source, configuration, code and tests.

Do not derive the release contract from upstream HEAD.

## Evidence-first workflow

1. Read project instructions, wrappers, the accepted Target State and implementation plan.
2. Confirm the root constraint, locked version and installed version all describe 0.10. Stop on mismatch.
3. Search code, configuration, tests and custom SDK extensions for every area below.
4. Classify every item as `required`, `conditional`, `not applicable`, `informational` or `blocked`, with concrete evidence.
5. Verify dependency requirements before updating: PHP `^8.3`, Illuminate `^12.0|^13.0`, `illuminate/json-schema` `^12.62|^13.15`, and every other constraint resolved by Composer. Do not authorize unrelated package updates.
6. Apply the smallest coherent dependency and application diff. Do not adopt optional 0.11 capabilities unless the accepted scope requires them.
7. Refresh Architecture Kit and Boost resources, run focused tests, then run the project's full verification.
8. Rerun `architecture-kit:upgrade-plan laravel/ai --to=0.11`; it must report `complete` before handoff.

## Required and conditional checks

### Provider connection failures

`Laravel\Ai\Exceptions\ProviderConnectionException` now wraps provider connection failures and implements `FailoverableException`. Replace catches of an underlying HTTP client connection exception when they cover Laravel AI calls. The original exception remains available through `getPrevious()`.

Failover status handling also expands beyond 503 to 502, 504, 520, 522 and 524, and Anthropic usage-limit rejection can fail over. Review configured provider order, retry policy, observable errors and side-effect safety. Do not assume failover is harmless for workflows that already emitted output or performed writes.

### Stream failures and terminal completion

Provider errors inside a stream now throw `Laravel\Ai\Exceptions\StreamErrorException` while the stream is consumed. A successful stream emits terminal `Laravel\Ai\Streaming\Events\StreamEnd`.

- Put error handling around iteration, not only around `$agent->stream(...)`.
- Do not accept or persist a partial response as success when iteration throws.
- Do not promise rollback of fragments already delivered to a client.
- Test both terminal completion and an error raised after partial output without contacting a real provider.

### Queued fakes execute real jobs

Faked queued Agent, transcription, image, audio and embedding operations now dispatch their real jobs, and `then(...)` callbacks execute. `Laravel\Ai\FakePendingDispatch` was removed.

- Replace tests that type-check or configure `FakePendingDispatch`.
- Choose a deterministic queue driver for callback tests.
- Do not use `Queue::fake()` when the test must prove job execution.
- Assert callback and application persistence effects while keeping the SDK/provider faked.

### Gemini default model

The Gemini default and smartest text model changes from `gemini-3.6-flash` to `gemini-3.7-flash`. Classify this review as required even when no code change is needed: record whether the application accepts the new behavior and cost or pins the old model explicitly. Never silently pin or switch a model.

### Event and Tool Request constructors

Only project code that constructs or subclasses these SDK types needs changes:

- `AgentFailedOver` adds required `string $invocationId` as its first constructor argument.
- `ToolInvoked` adds required final `float $time` in milliseconds.
- `Laravel\Ai\Tools\Request` accepts third `?string $toolInvocationId`; subclasses overriding the constructor must forward it, and `toolInvocationId()` exposes it.

Listeners that only receive the events do not need constructor changes.

## Generated resources and verification

- Update the root constraint to a fully supported 0.11 range and inspect the complete lockfile delta.
- Run the repository's install or sync command for Architecture Kit and refresh Boost when installed.
- Verify the generated `architecture-kit-laravel-ai` skill reports `laravel-ai@0.11` and this atomic upgrade skill remains current.
- Run focused connection, failover, stream, fake queue/callback, model configuration, event and Tool Request tests where applicable.
- Run the full project suite, formatter/linter, Architecture Kit doctor/strict guard and required dependency audit.
- Provider smoke tests needing credentials stay `OPEN` unless actually executed; deterministic fake tests never require provider credentials.

The 0.10 -> 0.11 transition does not repeat the 0.9 -> 0.10 conversation schema migration. If the application started below 0.10, the earlier guide still owns its schema and backfill evidence.

## Requirement-evidence handoff

| Requirement | Applicability | Evidence | Status |
|---|---|---|---|
| Dependency and lock resolve to 0.11 | required | Composer readback and lock diff | PASS or OPEN |
| Provider connection/failover handling | conditional | Search, config and focused tests | PASS, N/A or OPEN |
| Stream error and terminal completion | conditional | Search and consumed-stream tests | PASS, N/A or OPEN |
| Queued fake job and callback behavior | conditional | Executed job/callback tests | PASS, N/A or OPEN |
| Gemini model decision | required review | Config and accepted decision | PASS or OPEN |
| Event and Request constructors | conditional | Search, diff and focused tests | PASS, N/A or OPEN |
| Generated resources and full verification | required | Commands and outputs | PASS or OPEN |

Explain the resulting flow, decisions, remaining risks and provider-smoke boundary. Do not call the upgrade complete while a required row is `OPEN`.
