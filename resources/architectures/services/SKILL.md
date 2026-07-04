---
name: architecture-kit-services
description: Use Services as a compatibility-friendly Laravel service-layer architecture.
---

# Services

Use this skill when implementing or refactoring `app/Services/**` or an existing service-layer convention.

Services are compatibility-friendly architecture boundaries. They exist because many Laravel projects already use `app/Services/**`, but they are not a default dumping ground for business logic.

## Priority

1. Follow local project conventions first.
2. Follow more specific enabled Architecture Kit patterns before Services.
3. Use Services only when they are enabled and fit the service-layer responsibility.
4. Do not introduce Services into projects that do not use or explicitly enable them.

When `Actions` and `Services` are both enabled, new named write use cases should default to Actions. Use Services for existing service-layer consistency, shared workflow orchestration, integration boundaries, narrow cross-cutting concerns, or when replacing a Service with Actions would create unnecessary refactoring.

## Allowed Roles

A Service may represent:

- an existing service-layer convention,
- a cohesive workflow orchestration boundary,
- an integration boundary for external APIs, SDKs, AI providers, OCR providers, or payment gateways,
- a narrow cross-cutting concern such as telemetry, audit recording, event recording, or external logging,
- an application-level collaborator used by Controllers, Jobs, Commands, Actions, or other Services.

## Rules

- Services are allowed only when `Architecture::Services` is enabled.
- A Service must represent one clear service-layer responsibility.
- A Service may expose multiple public methods only when all methods belong to one cohesive responsibility.
- Do not create broad model-named Services that collect unrelated CRUD, workflow, export, import, and integration methods.
- Service class names should use the `Service` suffix.
- Services should be `final readonly` classes by default.
- Receive collaborators through constructor injection.
- Avoid mutable Service state.
- Do not create static utility Services.
- Do not add public static Service methods for application behavior.
- Do not create Service interfaces by default.
- Add a Service interface only when there are multiple real implementations, a swappable provider, a package/public boundary, or a concrete testing need.
- If Ports And Adapters are enabled, Services may depend on Ports when they orchestrate a workflow or integration boundary.
- Prefer dedicated Adapters as Port implementations; do not make broad Services implement Ports by default.
- Services may use Eloquent, transactions, queues, cache, HTTP clients, or external SDKs when that dependency belongs to the Service responsibility.
- Do not create a Service only to hide Eloquent from Actions or Controllers.
- When a Service orchestrates domain writes, transaction boundaries must be explicit and testable.
- Services must not accept `Request`, `FormRequest`, `JsonResponse`, `Response`, `RedirectResponse`, or `StreamedResponse`.
- Services must not return HTTP responses.
- Services must not perform HTTP request validation or parse raw request payloads.
- Use Form Requests, Data Objects, and Value Objects for input validation and mapping.
- Services may enforce domain invariants and workflow preconditions that are independent of HTTP.
- When `QueryObjects` are enabled, reusable or non-trivial read/query logic should live in Query Objects, not Services.
- Services may call Query Objects as part of workflow orchestration.
- Services should not become repositories or read-model containers.
- Services may return Eloquent models when the model is the natural and complete result of the operation.
- Use Result/Data objects for workflows that return multiple values, status, metadata, warnings, external response details, review state, or failure context.
- Services must not signal workflow failures with ambiguous `null`, `false`, or untyped arrays.
- Use named domain exceptions for invariant violations.
- Use typed Result/Data objects for expected alternative outcomes that callers must handle.
- Jobs may call Services when the Service represents the queued workflow or an integration boundary.
- Services should not hide asynchronous behavior behind ordinary-looking methods.
- Dispatching jobs from a Service is allowed only when queue orchestration is an explicit part of that Service responsibility.
- Services must be testable through explicit dependencies and typed inputs.
- Do not resolve collaborators with `app()` inside Services.
- Do not hide dependencies behind private static factories.
- Do not require HTTP requests to exercise Service behavior.

## Folder Purity

Use pattern-first placement when the project uses pattern-first folders:

```text
app/
  Services/Documents/DocumentPseudonymizationService.php
  Actions/Documents/StartDocumentPseudonymization.php
  Data/Documents/DocumentPseudonymizationResult.php
  Enums/Documents/DocumentPseudonymizationStatus.php
```

Use domain-first placement when the project already does that:

```text
app/
  Documents/Services/DocumentPseudonymizationService.php
  Documents/Actions/StartDocumentPseudonymization.php
  Documents/Data/DocumentPseudonymizationResult.php
  Documents/Enums/DocumentPseudonymizationStatus.php
```

Do not mix supporting classes into the Service folder:

```text
app/
  Services/Documents/DocumentPseudonymizationService.php
  Services/Documents/StartDocumentPseudonymization.php
  Services/Documents/DocumentPseudonymizationResult.php
  Services/Documents/DocumentPseudonymizationStatus.php
```

## Good Workflow Service

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

The Service is cohesive: every public method belongs to document pseudonymization.

## Bad Broad Service

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

This Service mixes CRUD, export, AI, and workflow behavior.

## Good Integration Service

```php
final readonly class Przelewy24PaymentService
{
    public function __construct(
        private Przelewy24Client $client,
    ) {
    }

    public function createPayment(PaymentRequestData $data): PaymentRedirectData
    {
        $response = $this->client->createPayment([
            'amount' => $data->amount->toInteger(),
            'email' => $data->payerEmail,
            'description' => $data->description,
        ]);

        return PaymentRedirectData::fromPrzelewy24Response($response);
    }
}
```

## Bad HTTP Leakage

```php
final class DocumentPseudonymizationService
{
    public function approve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => ['required', 'integer'],
        ]);

        // workflow + HTTP response...
    }
}
```

The Service replaced FormRequest, Data Object, Controller, and application workflow boundaries.

## Bad Hidden Dependency

```php
final class DocumentPseudonymizationService
{
    public function resolve(Document $document): DocumentPseudonymizationMap
    {
        return app(PseudonymizationMapResolver::class)->resolve($document);
    }

    private static function maps(): PseudonymizationMapResolver
    {
        return new PseudonymizationMapResolver();
    }
}
```

Inject collaborators instead.
