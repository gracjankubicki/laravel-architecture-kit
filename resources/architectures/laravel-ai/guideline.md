Purpose:
Laravel AI represents production AI workflows built on `laravel/ai`.

Default placement:
- `app/Ai/Agents`
- `app/Ai/Tools`
- `app/Ai/Gateways`
- `app/Ai/Data`
- `app/Ai/Enums`
- `app/Ai/Prompts`
- `app/Ai/Context`
- `app/Ai/Telemetry`
- Follow existing project structure only when it is more specific and still keeps AI classes type-pure.

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

Rules:
- Production application code must call project-owned AI gateways, Actions, or Jobs. Controllers, FormRequests, API Resources, and Models must not call Laravel AI directly.
- Each production Agent must be a dedicated class in its own file. Do not use generic production agents such as `StructuredGatewayAgent`.
- Agents own their instructions, structured schema, provider options, tools, and sub-agents. Application code supplies runtime input through Prompt Data, not through agent constructors.
- Keep agents declarative. Do not put Eloquent queries, HTTP calls, filesystem writes, domain persistence, authorization decisions, or business state transitions inside agents.
- Use `Laravel\Ai\Enums\Lab` where possible. Production provider/model choices must come from workflow config, typed config accessors, or agent/provider option objects, not raw call-site strings.
- Provider options are part of the architecture. Privacy, routing, data collection, temperature, max tokens, timeouts, and failover policy must be explicit for costly, sensitive, or critical workflows.
- Schema fields must be detailed and must mirror Result Data. Validate structured output through Result Data factories or validators before persistence.
- Hidden retry is forbidden. Retry policy belongs in Jobs, Actions, or workflow policy and must be visible in logs/telemetry.
- Use `Agent::queue()` only for simple prompt jobs. Domain workflows with lifecycle, retries, telemetry, review, or persistence should use project Jobs that delegate to Actions.
- Long or shared prompts should move to Prompt Builders or versioned prompt resources. Prompt versions that affect persisted domain results should be recorded.
- Prompt Builders render supplied Prompt Data and Context only. They must not query databases, call HTTP, mutate state, or make business decisions.
- Context Builders may collect and normalize data from Query Objects, Enums, Value Objects, config, and authorized input. They should return Prompt Data or Context objects, not raw prompt strings.
- Prompt context for allowed values must come from Enums, Value Objects, config, or Context Builders. Do not duplicate enum lists in agent instructions.
- Plain text AI output must also be wrapped in Result Data when it leaves the AI boundary.
- Laravel AI response objects must not leak past the gateway boundary.
- Conversation history may use Laravel AI conversation tables intentionally. Business results must be persisted through domain Actions. Telemetry/audit must be stored separately from domain truth.
- Raw prompts and raw responses may be stored for debug/audit only when intentional, isolated, protected, and not treated as domain state.
- AI workflows should record a stable workflow enum, agent class, invocation/correlation id, domain record id, provider/model, usage, tool calls/results, status, and failure reason when available.
- User-visible, legal, financial, or business-critical AI output should default to draft or `review_required`, not automatic final state.
- Persist AI origin metadata for AI-created or AI-assisted domain data.
- Authorization must be enforced before AI execution and inside write Tools through Policies, FormRequests, Actions, or Query Objects. AI output must never decide permissions.

Tools:
- Production tools must be dedicated classes under the AI Tool namespace. Do not use anonymous tool classes or closures in production.
- Tool descriptions and input schemas must be precise enough for the model to know when and how to call them.
- Read tools should return bounded, minimal context. Do not dump full models, documents, or unbounded collections.
- Write tools are allowed, but they must delegate to Actions and keep authorization, validation, idempotency, and audit behavior outside the model.
- Tools must enforce input/output limits and redact or pseudonymize sensitive data by default unless the workflow explicitly requires raw data.
- Provider tools such as web search, web fetch, and file search must be checked against the selected provider. Some providers do not support all provider tools.
- RAG and similarity tools should prefer project Query Objects and bounded chunks. Direct `SimilaritySearch::usingModel(...)` is acceptable only for simple local workflows.
- Sub-agents must be narrow, dedicated classes with their own Prompt Data, Result Data, schema, tests, and provider configuration.

Testing:
- Production AI workflows must be testable without real providers.
- Cover Prompt Data serialization, Result Data mapping, gateway fakes, invalid structured responses, provider/model/options resolution, and tool boundaries.
- Non-trivial workflows should keep small redacted fixtures or examples.
- Tests should prove that invalid AI output fails before persistence.

Ports And Adapters:
- If Ports And Adapters are enabled, AI Gateway/Agent classes may act as technical Adapters behind project-owned Ports.
- AI/OCR Ports must return project-owned Data/Result objects and must not expose provider payloads, provider model config, or vendor exceptions.

Good example:

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

Bad example:

```php
final class DocumentController
{
    public function summarize(Document $document): array
    {
        return DocumentSummaryAgent::make()
            ->prompt('Summarize document '.$document->content)
            ->toArray();
    }
}
```
