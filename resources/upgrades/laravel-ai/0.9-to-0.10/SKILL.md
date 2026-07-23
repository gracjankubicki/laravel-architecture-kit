---
name: architecture-kit-upgrade-laravel-ai-0-9-to-0-10
description: Upgrade a Laravel application from laravel/ai 0.9 to 0.10 using an evidence-first workflow.
metadata:
  architecture: laravel-ai
  package: laravel/ai
  from: "0.9"
  to: "0.10"
---

# Upgrade Laravel AI 0.9 to 0.10

Use this skill when a Laravel application declares or installs `laravel/ai` 0.9 and the user wants to move to 0.10. If the application starts on 0.8, complete `architecture-kit-upgrade-laravel-ai-0-8-to-0-9` first.

## Outcome

The application runs a supported Laravel AI 0.10 release, every applicable schema and contract change is addressed, version-derived guidance is regenerated, and the handoff distinguishes required changes from optional new capabilities.

## Sources of truth

- Official upgrade guide: https://github.com/laravel/ai/blob/v0.10.1/UPGRADE.md#upgrading-to-010-from-09
- Official releases: https://github.com/laravel/ai/releases/tag/v0.10.0 and https://github.com/laravel/ai/releases/tag/v0.10.1
- Full upstream diff: https://github.com/laravel/ai/compare/v0.9.1...v0.10.1
- The application's `composer.json`, `composer.lock`, migrations, installed package source, tests and runtime configuration.

Treat the official upgrade guide and installed source as authoritative. Pull-request descriptions are supporting evidence, not a substitute for the final tagged contract.

## Evidence-first workflow

1. Read repository instructions, wrappers, the current Architecture Kit state and any accepted Target State or implementation plan.
2. Record the root constraint, locked version and installed version for `laravel/ai`. Stop if they disagree or if the installed version is not 0.9.
3. If evidence shows a 0.8 starting state, stop and load `architecture-kit-upgrade-laravel-ai-0-8-to-0-9`.
4. Search migrations, schema, custom contracts, Agents, Tools, providers, configuration, tests and generated resources for every conditional area below.
5. Classify each item as `required`, `not applicable`, `informational`, or `blocked`, with a file, schema readback or command as evidence.
6. Prepare the repository's required Target State and implementation plan before changing files. The user must approve the implementation path.
7. Apply the smallest coherent dependency and application diff. Do not adopt optional product capabilities without scope approval.
8. Regenerate version-derived resources, run focused tests and then complete the full verification.
9. Produce the requirement-evidence handoff. An unchecked required or conditional item remains open.

## Required and conditional upgrade checks

### Polymorphic conversation participants

Remembered conversations moved from `user_id` to nullable `participant_type` plus `participant_id`, and `HasConversations` now returns `MorphMany`.

- Fresh installations receive the new schema from the published migration and need no upgrade migration.
- Applications that already ran the older published migration need a new project migration that renames IDs, adds participant types, rebuilds indexes and backfills existing rows.
- Derive the stored type through the model's morph class. Respect enforced morph maps.
- If existing rows can belong to multiple model types, do not guess: the old schema cannot infer the type from a shared numeric ID. Stop for an explicit mapping decision.
- Custom `ConversationStore` implementations receive participant type before participant ID.
- `forParticipant()` is canonical for non-user participants; `forUser()` remains an alias.
- Calling `continue()` with a conversation ID does not prove ownership. Keep authorization in the application.

Do not generate or run a database migration merely because the package version changed. First prove that the older conversation tables were published and applied.

### Approval state schema

Human-in-the-loop resumption stores coordination data in a nullable `TEXT` `approval_state` column on conversation messages.

- Fresh installations already receive the column.
- Existing applications with the older conversation migration need a project migration adding the column.
- Confirm the configured conversation table name rather than assuming the default.

Do not mark this `not applicable` solely because the application has not yet adopted approval Tools; the shipped 0.10 conversation-store schema still requires the column when upgrading an existing published table.

### Direct Agent implementations

The Agent entry points accept `Decisions|string`. Agents using the shipped `Promptable` trait need no signature change. Applications implementing `Laravel\Ai\Contracts\Agent` directly must update every affected prompt, stream, queue and broadcast method to the tagged 0.10 contract.

### Custom ConversationStore implementations

The contract adds `storeApprovalResults()` and `storeAssistantMessage()` returns `?string`. It also carries the polymorphic participant type through store methods.

The shipped database store needs no custom implementation work. A project binding its own `ConversationStore` must implement durable approval result storage and preserve the package's mismatch behavior.

### Human-in-the-loop Tools

Laravel AI 0.10 adds approval pauses for side-effecting Tools across prompt, stream, queue and broadcast flows.

Treat adoption as optional unless explicitly requested. When adopted:

- use the final tagged `Approvable`, `InteractsWithApprovals`, `Decision` and `Decisions` contracts;
- require a resumable conversational Agent;
- authorize the participant independently of the approval decision;
- key idempotency by `$request->toolCallId()`;
- expect concurrent resumes and make external side effects idempotent;
- test approve, edit, reject, stale/mismatched decisions and continuation failure.

Built-in write, copy and delete filesystem Tools require approval by default. Check whether the application exposes them.

## New capabilities and behavior to evaluate

These items are not authorization to expand product scope, but their defaults may affect an existing application:

- **Multimodal embeddings:** Gemini accepts text, image, audio, document and video inputs; Voyage AI accepts text, image and video. Other providers reject non-text input instead of silently degrading.
- **Text summarization:** `str($text)->summarize()` and `Str::summarize()` use the dedicated cheapest-model summarization Agent.
- **Bedrock AssumeRole:** cross-account STS credentials can be configured, with bearer token, assumed role, static credentials and default-chain precedence.
- **Gemini default models in 0.10.1:** default/smartest text, cheapest text and embedding defaults changed. Compare explicit application configuration and snapshots before accepting behavior or cost changes.
- **Rector tooling:** upstream development tooling changed but does not add a runtime dependency requirement.

Record each item as `informational`, `adopted by scope`, or `behavior-impacting`. Do not silently enable a new feature.

## Dependency and generated resources

- Update the root constraint to a fully supported 0.10 range and update only the dependency set required by Composer.
- Inspect direct and transitive lockfile changes.
- Run `php artisan architecture-kit:install` or `php artisan architecture-kit:sync --no-interaction` according to repository instructions.
- If Laravel Boost is installed, refresh its generated resources using the repository's established Boost command.
- Verify that Architecture Kit resolves `laravel-ai@0.10`, both Laravel AI upgrade skills remain discoverable, and generated files have no pending dry-run changes.

Use project wrappers before raw Composer, Artisan or PHPUnit commands.

## Verification

At minimum, prove:

- Composer resolves an installed and locked 0.10 version consistent with the root constraint;
- schema state matches the applicable conversation and approval requirements;
- focused conversation, Agent contract, custom store, Tool approval, provider and embedding tests pass where those areas exist;
- authorization and idempotency tests pass for adopted approval flows;
- the full project test command passes;
- Architecture Kit doctor/guard and sync dry-run pass when available;
- formatter/linter and `composer audit` pass when required by the repository;
- no accepted requirement remains without evidence.

Provider smoke tests requiring credentials remain explicitly open unless they were actually executed.

## Requirement-evidence handoff

Return:

| Requirement | Applicability | Evidence | Status |
|---|---|---|---|
| Dependency and lock resolve to 0.10 | required | Composer readback | PASS or OPEN |
| Polymorphic participant schema and backfill | conditional | Migration/schema/data evidence | PASS, N/A or OPEN |
| `approval_state` schema | conditional | Migration/schema evidence | PASS, N/A or OPEN |
| Direct Agent contract | conditional | Search, diff and focused tests | PASS, N/A or OPEN |
| Custom ConversationStore contract | conditional | Binding, diff and focused tests | PASS, N/A or OPEN |
| Human-in-the-loop safety | conditional | Authorization/idempotency tests | PASS, N/A or OPEN |
| New capability/default review | required review | Config/search evidence | PASS or OPEN |
| Generated resources and full verification | required | Commands and outputs | PASS or OPEN |

Explain the final flow, material decisions, schema/data assumptions, remaining risks and how future upgrades should add another atomic guide. Do not call the upgrade complete while any required row is `OPEN`.
