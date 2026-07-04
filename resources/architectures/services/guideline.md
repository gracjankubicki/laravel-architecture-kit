Purpose:
Services represent compatibility-friendly service-layer collaborators for Laravel projects that already use a service layer or intentionally choose Services.

Default placement:
- `app/Services`
- Follow existing project structure if it is more specific.
- In domain-first projects, use `*/Services/**`.
- Keep Service folders type-pure. Do not place Actions, Data Objects, Result objects, Enums, Exceptions, API Resources, Value Objects, Requests, or Eloquent Models under `app/Services/**`.

Priority:
- Follow local project conventions first.
- Follow more specific enabled Architecture Kit patterns before Services.
- When Actions and Services are both enabled, new named write use cases should default to Actions.
- Use Services for existing service-layer consistency, shared workflow orchestration, integration boundaries, narrow cross-cutting concerns, or when replacing a Service with Actions would create unnecessary refactoring.

Rules:
- Services are allowed only when `Architecture::Services` is enabled.
- A Service must represent one clear service-layer responsibility.
- A Service may expose multiple public methods only when all methods belong to one cohesive responsibility.
- Do not create broad model-named Services that collect unrelated CRUD, workflow, export, import, and integration methods.
- Service class names should use the `Service` suffix.
- Use `final readonly class` by default.
- Use constructor injection for collaborators.
- Avoid mutable Service state.
- Do not create static utility Services.
- Do not add public static application behavior to Services.
- Do not create Service interfaces by default. Add an interface only for multiple real implementations, swappable providers, package/public boundaries, or a concrete testing need.
- If Ports And Adapters are enabled, Services may depend on Ports when they orchestrate a workflow or integration boundary.
- Prefer dedicated Adapters as Port implementations; do not make broad Services implement Ports by default.
- Services may use Eloquent, transactions, queues, cache, HTTP clients, or external SDKs when that dependency belongs to the Service responsibility.
- Do not create a Service only to hide Eloquent from Actions or Controllers.
- When a Service orchestrates domain writes, transaction boundaries must be explicit and testable.
- Services must not accept `Request`, `FormRequest`, `JsonResponse`, `Response`, `RedirectResponse`, or `StreamedResponse`.
- Services must not return HTTP responses.
- Use Form Requests, Data Objects, and Value Objects for input validation and mapping.
- Services may enforce domain invariants and workflow preconditions that are independent of HTTP.
- When Query Objects are enabled, reusable or non-trivial read/query logic should live in Query Objects, not Services.
- Services may return Eloquent models when the model is the natural and complete result.
- Use Result/Data objects for workflows that return multiple values, status, metadata, warnings, external response details, review state, or failure context.
- Do not signal workflow failures with ambiguous `null`, `false`, or untyped arrays.
- Use named domain exceptions for invariant violations and typed Result/Data objects for expected alternative outcomes.
- Jobs may call Services when the Service represents the queued workflow or an integration boundary.
- Services should not hide asynchronous behavior behind ordinary-looking methods.
- Dispatching jobs from a Service is allowed only when queue orchestration is an explicit part of that Service responsibility.
- When Laravel AI and Services are both enabled, existing AI Services may remain as compatibility layers, but new production AI workflows should use the `app/Ai/**` boundary.

Good example:

```php
final readonly class DocumentPseudonymizationService
{
    public function __construct(
        private StartDocumentPseudonymization $start,
        private RetryDocumentPseudonymization $retry,
        private ApproveDocumentPseudonymization $approve,
    ) {
    }

    public function start(Document $document): DocumentPseudonymizationResult
    {
        return $this->start->handle($document);
    }

    public function retry(Document $document): DocumentPseudonymizationResult
    {
        return $this->retry->handle($document);
    }

    public function approve(Document $document, User $reviewer): DocumentPseudonymizationResult
    {
        return $this->approve->handle($document, $reviewer);
    }
}
```

Bad example:

```php
final class DocumentService
{
    public function create(array $payload): Document {}
    public function update(Document $document, array $payload): Document {}
    public function delete(Document $document): void {}
    public function exportPdf(Document $document): string {}
    public function sendToAi(Document $document): array {}
    public function approvePseudonymization(Document $document): void {}
}
```
