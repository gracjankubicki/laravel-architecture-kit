---
name: architecture-kit-laravel-ai
description: Apply the verified Laravel AI 0.8 architecture overlay through typed, testable project boundaries.
---

# Laravel AI 0.8 Architecture

Use this skill for production workflows running Laravel AI `>=0.8.0 <0.9.0`.

## Boundary

Use `Action or Job -> Context Builder -> Prompt Data -> AI Gateway -> Agent -> Result Data -> Action/domain persistence`. Agents stay declarative; Gateways hide Laravel AI responses; write Tools delegate to Actions.

## Structured output

Map the 0.8 structured response with `toArray()` or ArrayAccess. Do not use removed legacy structured-response accessors.

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

## Architecture rules

- Runtime inputs and outputs are project-owned typed objects.
- Controllers, requests, resources and models do not call Agents directly.
- Agents do not query databases, persist state, authorize users or choose domain transitions.
- Write Tools delegate to Actions and enforce bounded input/output.
- Important output defaults to draft or human review.
- Retry, provider/model selection, privacy and telemetry are explicit policy.
- Tests use Laravel AI fakes and prove invalid output fails before persistence.

Load the official installed `ai-sdk-development` skill for exact 0.8 SDK APIs. Without Boost, read `vendor/laravel/ai/resources/boost/skills/ai-sdk-development/SKILL.md` and official docs.
