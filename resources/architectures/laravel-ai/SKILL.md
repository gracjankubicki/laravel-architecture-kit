---
name: architecture-kit-laravel-ai
description: Use Laravel AI through testable, project-owned AI workflow boundaries.
---

# Laravel AI

Use this skill when implementing or refactoring production AI workflows that use `laravel/ai`.

## Workflow

1. Start from the domain use case, not from a raw prompt.
2. Route production execution through an Action or Job.
3. Build context with a dedicated Context Builder when data must be collected or normalized.
4. Pass runtime input through Prompt Data.
5. Call a project-owned AI Gateway.
6. Let the Gateway call one dedicated Laravel AI Agent.
7. Map structured output into Result Data.
8. Persist domain state through Actions after Result Data validation.
9. Record telemetry/audit metadata when the workflow is non-trivial.

Canonical flow:

```text
Action or Job
  -> Prompt Context Builder
  -> Prompt Data
  -> AI Gateway
  -> Laravel AI Agent
  -> Result Data
  -> Action/domain persistence
```

Use only the steps the workflow actually needs.

## Required Structure

```text
app/Ai/
  Agents/
  Tools/
  Gateways/
  Data/
  Enums/
  Prompts/
  Context/
  Telemetry/
```

Each production Agent, Tool, Prompt Data, Result Data, Prompt Builder, Context Builder, and telemetry helper should live in its own dedicated file.

## Boundary Rules

- Controllers, FormRequests, API Resources, Models, and view/payload helpers MUST NOT call Laravel AI directly.
- Production application code calls AI through a project-owned Gateway, Action, or Job.
- Do not leak Laravel AI response objects past the Gateway.
- Plain text output must also be wrapped in Result Data when it leaves the AI boundary.
- Generic `runAgent(string $agent, string $input): array` gateways are diagnostic-only. Production gateways need domain-named typed methods.
- Do not use `StructuredGatewayAgent` or another catch-all agent in production. Generic agents are allowed only for diagnostics, prototypes, or migration shims.

## Agent Rules

- Every production Agent is a dedicated class in its own file.
- Agents own `instructions()`, structured schema, provider options, tools, and sub-agents.
- Runtime domain input belongs in Prompt Data, not in the Agent constructor.
- Keep Agents declarative. They must not query Eloquent, call HTTP, write files, persist domain state, authorize users, or choose business state transitions.
- Constructor injection in Agents is limited to declarative collaborators such as Prompt Builders, provider option objects, or static context providers.
- AI Gateways should create Agents through `AgentClass::make()` or a small factory. Avoid injecting many concrete Agents into one class.

## Prompt Data And Result Data

- Prompt inputs are Prompt Data.
- Structured outputs map to Result Data.
- Result Data validates model output before persistence.
- Schema fields must be detailed and must mirror Result Data.
- Required fields should be required in the schema when the domain requires them.
- Allowed values must come from Enums, Value Objects, config, or Context Builders. Do not duplicate enum lists inside instructions.

Example:

```php
final readonly class DocumentReviewPromptData
{
    /**
     * @param  list<array{value: string, label: string}>  $allowedOutcomes
     */
    public function __construct(
        public string $documentText,
        public array $allowedOutcomes,
    ) {
    }
}
```

```php
final readonly class DocumentReviewResultData
{
    public function __construct(
        public string $summary,
        public string $recommendedOutcome,
        public float $confidence,
    ) {
    }

    /**
     * @param  array{summary: string, recommended_outcome: string, confidence: float}  $payload
     */
    public static function fromAiPayload(array $payload): self
    {
        return new self(
            summary: $payload['summary'],
            recommendedOutcome: $payload['recommended_outcome'],
            confidence: $payload['confidence'],
        );
    }
}
```

## Provider And Options

- Use `Laravel\Ai\Enums\Lab` where possible.
- Production provider/model choices must come from workflow config, typed config accessors, Agent methods, or provider option objects.
- Avoid raw provider/model strings in production call sites.
- Provider options are first-class architecture. Privacy, routing, data collection, temperature, max tokens, topP, timeout, and failover policy must be explicit for sensitive, costly, or critical workflows.
- Legal, compliance, extraction, and classification workflows should default to low temperature unless the workflow explicitly needs creativity.
- Failover must be explicit and telemetry must record attempts and the final provider/model.
- Streaming structured output is not supported; do not design workflows that require it.

## Prompt Builders And Context Builders

- Keep Agents small and declarative.
- Move long or shared instructions to Prompt Builders or versioned prompt resources.
- Prompt versions that affect persisted domain results should be recorded.
- Prompt Builders render supplied Prompt Data and Context only.
- Prompt Builders must not query databases, call HTTP, mutate state, or make business decisions.
- Context Builders may collect and normalize data from Query Objects, Enums, Value Objects, config, and authorized input.
- Context Builders should return Prompt Data or Context objects, not raw prompt strings.

## Tools

- Production Tools must be dedicated classes under `app/Ai/Tools/**`.
- Do not use anonymous tool classes, closures, or inline throwaway tools in production.
- Tool descriptions and schema field descriptions must be specific.
- Read Tools return bounded, minimal context.
- Write Tools are allowed, but they must delegate to Actions.
- Tools must enforce input and output limits.
- Sensitive data should be redacted or pseudonymized by default unless the workflow explicitly requires raw data.
- Provider Tools must be checked against the selected provider. Some providers do not support web search, web fetch, or file search.
- RAG and similarity Tools should prefer project Query Objects and bounded chunks.
- Direct `SimilaritySearch::usingModel(...)` is acceptable only for simple local workflows.

## Jobs, Queue, And Lifecycle

- Use project Jobs for long-running domain workflows.
- Jobs should usually delegate to Actions.
- `Agent::queue()` is acceptable for simple prompt jobs only.
- Domain AI lifecycle states should be explicit: `queued`, `processing`, `completed`, `failed`, `review_required`.
- User-visible, legal, financial, or business-critical output should default to draft or `review_required`.
- Persist AI origin metadata for AI-created or AI-assisted data.
- Hidden retry is forbidden. Retry belongs in Jobs, Actions, or workflow policy and must be observable.

## Storage And Telemetry

- Conversation history may use Laravel AI conversation tables when `RemembersConversations` is intentional.
- Business results belong in domain tables after Result Data mapping.
- Telemetry/audit belongs in project logs, listeners, or audit tables.
- Raw prompts and raw responses may be stored for debug/audit when intentional, isolated, protected, and not treated as domain truth.
- Record a stable workflow enum, Agent class, invocation/correlation id, domain record id, provider/model, usage, tool calls/results, status, and failure reason when available.
- Keep correlation id across the Action/Job, AI Gateway, domain record, logs, and future tracing.

## Testing

- Production AI workflows must test without real providers.
- Test Prompt Data serialization.
- Test Result Data mapping.
- Test invalid structured output.
- Test Gateway fakes.
- Test provider/model/options resolution.
- Test Tool boundaries and write Tool delegation to Actions.
- Keep small redacted fixtures or examples for non-trivial workflows.

## Ports And Adapters

If Ports And Adapters are enabled, AI Gateway/Agent classes may act as technical Adapters behind project-owned Ports.

AI/OCR Ports must return project-owned Data/Result objects and must not expose provider payloads, provider model config, or vendor exceptions.

## Good Example

```php
final readonly class DocumentReviewAiGateway
{
    public function summarizeForReview(DocumentReviewPromptData $prompt): DocumentReviewResultData
    {
        $response = DocumentReviewAgent::make()
            ->prompt($prompt->documentText);

        return DocumentReviewResultData::fromAiPayload($response->structuredOutput());
    }
}
```

```php
final readonly class SummarizeDocumentForReview
{
    public function __construct(
        private BuildDocumentReviewPromptContext $context,
        private DocumentReviewAiGateway $ai,
        private StoreDocumentReviewDraft $storeDraft,
    ) {
    }

    public function handle(Document $document, User $reviewer): DocumentReviewDraft
    {
        $prompt = $this->context->handle($document, $reviewer);
        $result = $this->ai->summarizeForReview($prompt);

        return $this->storeDraft->handle($document, $reviewer, $result);
    }
}
```

## Bad Example

```php
final class DocumentController
{
    public function summarize(Document $document): array
    {
        return DocumentReviewAgent::make()
            ->prompt('Summarize: '.$document->content)
            ->toArray();
    }
}
```

```php
final class AiGateway
{
    public function runAgent(string $agent, string $input): array
    {
        return (new StructuredGatewayAgent($agent))->prompt($input)->toArray();
    }
}
```
