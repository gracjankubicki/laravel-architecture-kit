---
name: architecture-kit-laravel-ai
description: Apply the verified Laravel AI 0.11 architecture overlay with typed boundaries, explicit stream failure handling, queue-aware fakes and application-owned authorization.
---

# Laravel AI 0.11 Architecture

Use this skill for production workflows running Laravel AI `>=0.11.0 <0.12.0`.

## Boundary

Use `Action or Job -> Context Builder -> Prompt Data -> AI Gateway -> Agent -> Result Data -> Action/domain persistence`. Agents stay declarative; Gateways hide Laravel AI responses and exceptions; write Tools delegate to Actions.

Map structured responses with `toArray()` or ArrayAccess into project-owned Result Data. Configure provider-specific behavior through `withProviderOptions()` only when required.

```php
final readonly class DocumentReviewAiGateway
{
    public function summarizeForReview(DocumentReviewPromptData $prompt): DocumentReviewResultData
    {
        try {
            $response = DocumentReviewAgent::make()->prompt($prompt->documentText);

            return DocumentReviewResultData::fromAiPayload($response->toArray());
        } catch (ProviderConnectionException $exception) {
            throw DocumentReviewUnavailable::fromProviderFailure($exception);
        }
    }
}
```

## Streams and failover

Protect stream consumption, not only stream creation. `StreamErrorException` can be thrown while iterating; successful completion emits `StreamEnd`. Do not persist a success result until the terminal outcome has been checked.

`ProviderConnectionException` is failoverable in 0.11. Define which providers may fail over, when retry is safe and how partial output is shown. Failover cannot retract fragments already sent to a client, and neither retry nor the SDK makes application writes idempotent.

Map SDK failures and results to application contracts in the Gateway. Correlation or invocation IDs and failure metadata may feed existing telemetry; do not create a second logging system or store raw prompts by default.

## Queued fakes

In 0.11, `queue()` and `broadcastOnQueue()` dispatch the real job even when the Agent is faked. A test that previously relied on `FakePendingDispatch` no longer proves the callback path.

- Choose the queue driver deliberately; use `sync` when the callback must execute in the test.
- Assert the callback and observable application result.
- Do not use `Queue::fake()` in a test intended to prove job execution.
- Keep provider traffic blocked; Agent fakes still supply deterministic responses.

## Tools, approvals and conversations

Write Tools delegate to Actions that enforce validation, authorization, domain invariants and idempotency. Human approval is an additional gate, not authorization. Key resumes and external effects by tool call ID, handle concurrent resumes and authorize a conversation ID against its polymorphic participant before `continue()`.

## What the guard proves

The Laravel AI audit checks confirmed SDK-call placement and selected direct-use patterns. It does not prove prompt quality, Result Data completeness, authorization, retention, retry safety or idempotency. Verify those with focused application tests and review.

Tests use Laravel AI fakes and no real providers. Cover invalid structured output, stream failure during iteration, terminal completion, queue callbacks, participant access, approve/edit/reject decisions and duplicate resumes where relevant.

Load the official installed `ai-sdk-development` skill for exact 0.11 SDK APIs. Without Boost, read `vendor/laravel/ai/resources/boost/skills/ai-sdk-development/SKILL.md`, official docs and `architecture-kit-upgrade-laravel-ai-0-10-to-0-11`.
