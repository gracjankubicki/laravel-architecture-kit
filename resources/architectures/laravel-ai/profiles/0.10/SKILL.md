---
name: architecture-kit-laravel-ai
description: Apply the verified Laravel AI 0.10 architecture overlay with typed boundaries, participant authorization and human-in-the-loop approvals.
---

# Laravel AI 0.10 Architecture

Use this skill for production workflows running Laravel AI `>=0.10.0 <0.11.0`.

## Boundary

Use `Action or Job -> Context Builder -> Prompt Data -> AI Gateway -> Agent -> Result Data -> Action/domain persistence`. Agents stay declarative; Gateways hide Laravel AI responses; write Tools delegate to Actions.

## Structured output and provider options

Map structured responses with `toArray()` or ArrayAccess. Configure provider-specific behavior through `withProviderOptions()` when required.

```php
final readonly class DocumentReviewAiGateway
{
    public function summarizeForReview(DocumentReviewPromptData $prompt): DocumentReviewResultData
    {
        $response = DocumentReviewAgent::make()->prompt($prompt->documentText);

        return DocumentReviewResultData::fromAiPayload($response->toArray());
    }
}
```

## Human-in-the-loop Tools

Use the 0.10 human-in-the-loop contract for side effects that require review. An approvable Tool pauses before execution and resumes through conversation history after an explicit decision. Approval is not authorization: the Action receiving the Tool request must still enforce permissions and domain invariants.

- Use `Approvable` with `InteractsWithApprovals` for gated Tools.
- Require a resumable conversational Agent for approval flows.
- Key decisions and idempotency by the Laravel AI tool call ID.
- Treat concurrent resumes as possible and make external side effects idempotent.
- Persist the shipped `approval_state` schema when upgrading an existing conversation store.

## Conversation participants

Laravel AI 0.10 stores remembered conversations by polymorphic participant. Use `forParticipant()` for non-user participants. Authorize access before calling `continue()` with a conversation ID; the SDK does not prove ownership for the application.

Applications upgrading from 0.9 must evaluate the matching upgrade guide before relying on remembered conversations. Existing rows need an explicit participant-type backfill, and custom `ConversationStore` implementations must satisfy the 0.10 contract.

## Architecture rules

- Runtime inputs and outputs are project-owned typed objects.
- Controllers, requests, resources and models do not call Agents directly.
- Agents do not query databases, persist state, authorize users or choose domain transitions.
- Write Tools delegate to Actions and enforce bounded input/output.
- Important output defaults to draft or human review.
- Retry, provider/model selection, privacy and telemetry are explicit policy.
- Tests use Laravel AI fakes and prove invalid output fails before persistence.
- Approval tests prove approve, edit, reject, stale decision and duplicate-resume behavior where applicable.

Load the official installed `ai-sdk-development` skill for exact 0.10 SDK APIs. Without Boost, read `vendor/laravel/ai/resources/boost/skills/ai-sdk-development/SKILL.md`, official docs and `architecture-kit-upgrade-laravel-ai-0-9-to-0-10`.
