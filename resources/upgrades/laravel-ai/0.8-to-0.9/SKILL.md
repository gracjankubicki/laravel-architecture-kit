---
name: architecture-kit-upgrade-laravel-ai-0-8-to-0-9
description: Upgrade a Laravel application from laravel/ai 0.8 to 0.9 using an evidence-first workflow.
metadata:
  architecture: laravel-ai
  package: laravel/ai
  from: "0.8"
  to: "0.9"
---

# Upgrade Laravel AI 0.8 to 0.9

Use this skill when a Laravel application declares or installs `laravel/ai` 0.8 and the user wants to move to 0.9 or later. This is an atomic transition: finish and verify 0.9 before continuing to 0.10.

## Outcome

The application runs a supported Laravel AI 0.9 release, all applicable 0.9 breaking changes are addressed, generated AI guidance matches the installed version, and the handoff proves what changed and what did not apply.

## Sources of truth

- Official upgrade guide: https://github.com/laravel/ai/blob/v0.9.1/UPGRADE.md#upgrading-to-09-from-08
- Official release: https://github.com/laravel/ai/releases/tag/v0.9.0
- Full upstream diff: https://github.com/laravel/ai/compare/v0.8.1...v0.9.0
- The application's `composer.json`, `composer.lock`, installed package source, tests and runtime configuration.

Treat the official upgrade guide and installed source as authoritative. Do not infer an SDK contract from Architecture Kit examples.

## Evidence-first workflow

1. Read repository instructions, wrappers, the current Architecture Kit state and any accepted Target State or implementation plan.
2. Record the root constraint, locked version and installed version for `laravel/ai`. Stop if they disagree or if the installed version is not 0.8.
3. Search the application and its tests for every conditional area below. Classify each item as `required`, `not applicable`, or `blocked`, with a file or command as evidence.
4. Prepare the repository's required Target State and implementation plan before changing files. The user must approve the implementation path.
5. Apply the smallest coherent dependency and application diff. Do not combine unrelated refactors.
6. Regenerate version-derived resources and run focused tests before the full project verification.
7. Produce the requirement-evidence handoff. An unchecked conditional item remains open.

## Conditional upgrade checks

### Provider options

`providerOptions()` on embeddings and transcription builders was removed. Replace applicable calls with `withProviderOptions()`.

Provider Tools also changed `withProviderOptions()`: the former provider argument was removed. Pass an array or a closure that returns options for the active provider.

Search production code, tests, fixtures and examples. Do not report this step as required when neither legacy call shape exists.

### Custom text gateways

The `TextGateway` contract was removed in favor of `StepTextGateway`, while multi-step behavior moved to `TextGenerationLoop`.

Only applications implementing or type-hinting custom Laravel AI gateways need changes. Code extending the shipped provider base receives the loop implementation without a project-owned replacement.

### Faked responses

Faked responses now run through the real text generation loop. Inspect exact-message and exact-event assertions for:

- an unregistered faked Tool now throwing `NoSuchToolException`;
- the final assistant reply appearing in response messages after a Tool call;
- empty streamed text no longer emitting text start/end events;
- each faked Tool call emitting a streaming ToolCall event.

Adjust only assertions whose observed 0.9 behavior changed. Preserve domain expectations.

### Anthropic structured output

Laravel AI 0.9 enables native Anthropic structured outputs by default. Check provider configuration and response fixtures. Set `use_native_structured_output` to `false` only when the application deliberately requires the legacy synthetic Tool behavior.

### Newly available capabilities

Record new 0.9 capabilities such as filesystem Tools, OpenAI-compatible providers and `withProviderOptions()` support as informational unless the accepted task explicitly adopts them. A dependency upgrade does not authorize product changes.

## Dependency and generated resources

- Update the root constraint to a fully supported 0.9 range and update only the dependency set required by Composer.
- Inspect direct and transitive lockfile changes; do not describe the named package as the only change when Composer updated more packages.
- Run `php artisan architecture-kit:install` or `php artisan architecture-kit:sync --no-interaction` according to repository instructions.
- If Laravel Boost is installed, refresh its generated resources using the repository's established Boost command.
- Verify that Architecture Kit resolves `laravel-ai@0.9` and that generated files have no pending dry-run changes.

Use project wrappers before raw Composer, Artisan or PHPUnit commands.

## Verification

At minimum, prove:

- Composer resolves an installed and locked 0.9 version consistent with the root constraint;
- focused Agent, Tool, provider-options, structured-output and fake-response tests pass where those areas exist;
- the full project test command passes;
- Architecture Kit doctor/guard and sync dry-run pass when available;
- formatter/linter and `composer audit` pass when required by the repository;
- no accepted requirement remains without evidence.

Provider smoke tests requiring credentials remain explicitly open unless they were actually executed.

## Sequential upgrade rule

If the requested destination is 0.10, stop after the verified 0.9 handoff, then load `architecture-kit-upgrade-laravel-ai-0-9-to-0-10`. Do not jump directly from the 0.8 code state to the 0.10 checklist.

## Requirement-evidence handoff

Return:

| Requirement | Applicability | Evidence | Status |
|---|---|---|---|
| Dependency and lock resolve to 0.9 | required | Composer readback | PASS or OPEN |
| Provider options migration | conditional | Search, diff and focused tests | PASS, N/A or OPEN |
| Custom gateway migration | conditional | Search, diff and focused tests | PASS, N/A or OPEN |
| Fake behavior migration | conditional | Test results | PASS, N/A or OPEN |
| Anthropic behavior decision | conditional | Config/test evidence | PASS, N/A or OPEN |
| Generated resources and full verification | required | Commands and outputs | PASS or OPEN |

Explain the final flow, material decisions, remaining risks and the next guide when the target exceeds 0.9. Do not call the upgrade complete while any required row is `OPEN`.
