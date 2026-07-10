Purpose:
Ports And Adapters define explicit Laravel application boundaries for real outbound provider, infrastructure, package, legacy, runtime, or testability seams.

Default placement:
- Place Ports near the application boundary that owns the need.
- Place Adapters near the provider, SDK, integration, or infrastructure they wrap.
- Do not create global `app/Contracts` or `app/Adapters` folders unless the project already uses that convention consistently.
- Keep folders type-pure and follow existing project structure before introducing new folders.

Rules:
- Enable this pattern only for real boundaries. It is not a generic interface-generation rule.
- Use Laravel's native abstractions and concrete dependency injection by default.
- Before creating a custom Port, check whether Laravel already provides a suitable abstraction such as Cache, Queue, Mail, Storage, Notifications, Events, Bus, HTTP client, Config, or Logger.
- Do not wrap Laravel abstractions unless the application needs a named domain capability above them.
- Focus mainly on outbound boundaries to providers, infrastructure, SDKs, legacy systems, and external services.
- Do not create inbound Port interfaces for every controller, command, job, listener, Action, or Service.
- Use interfaces only for real architectural boundaries: external systems, multiple real implementations, swappable providers, package/public contracts, framework integration points, or concrete testability seams.
- Do not create one interface per class by default.
- A Port is an application-owned contract. It describes what the application needs, not provider-specific request arrays, SDK classes, HTTP responses, or vendor exceptions.
- Name Ports by application capability, not vendors or technologies.
- Name Adapters by the provider, implementation, SDK, or integration they wrap.
- Every Port interface must include short PHPDoc that explains why the Port exists and names the protected boundary. A project may add a bilingual policy as a custom audit rule.
- Port boundaries should use immutable inputs and outputs: readonly Data objects, Value Objects, enums, result objects, or explicit scalars.
- When Data Objects are enabled, Port methods must not use raw arrays as boundary payloads.
- Adapters translate provider-specific APIs, SDKs, payloads, exceptions, and response shapes into project-owned Data objects, Value Objects, enums, results, or domain exceptions.
- Adapters must translate vendor exceptions before they cross the Port boundary.
- Use `final readonly class` for Adapters by default unless a framework, package base class, lifecycle requirement, or existing project convention requires a different shape.
- Adapters may perform the technical provider call they wrap, but must not orchestrate application side effects such as dispatching domain jobs/events, changing model state, sending notifications, or starting unrelated workflows.
- Adapters may configure technical resilience such as authentication, timeouts, transport retries, backoff, rate limits, and provider error mapping.
- Business retry policy, state changes, compensation, review decisions, and workflow orchestration belong in application boundaries.
- Services may depend on Ports when they orchestrate a workflow or integration boundary.
- Prefer dedicated Adapters as Port implementations; do not make broad Services implement Ports by default.
- Controllers must not call Adapters directly. Controllers should delegate to an enabled application boundary such as an Action or cohesive Service.
- When multiple Adapters implement the same Port, choose the Adapter through explicit config, enum, or resolver logic.
- Do not rely on ambiguous container bindings to select business or provider behavior.
- Do not introduce a strategy registry or plugin system without a real extensibility need.
- Do not introduce repository interfaces just to hide Eloquent.
- Do not introduce CQRS as a separate architecture. Use Actions for writes and Query Objects for reusable or non-trivial reads.
- Do not model authorization, permission checks, or pure domain calculations as Ports.
- Do not use closures or untyped callables as production Adapters.
- Port interfaces must declare behavior. Do not create empty marker interfaces as application Ports.
- Avoid Port inheritance in application code. Prefer composing small Ports in Actions or Services.
- Packages may expose public interfaces more often than applications, but every public contract still needs a real extension point, integration boundary, or user-swappable implementation.
- Adapters should have tests that verify provider payload mapping, error translation, and Port contract behavior without calling real external services.
- Use shared contract tests only when multiple Adapters implement the same Port.
- Do not add a Port only for testing when the concrete class or Laravel/package abstraction can already be faked, mocked, or swapped cleanly in the project's tests.

Port decision checklist:

Before adding a Port, answer:

1. What boundary does this Port protect?
2. Why is Laravel's native abstraction or concrete DI not enough?
3. What provider/vendor/framework details must not leak into the application?
4. What project-owned input/output types cross the boundary?
5. How will this be tested without the real provider?
6. Where is the Adapter bound or resolved?
7. Does this duplicate an existing interface, binding, Laravel abstraction, or package fake?

If the protected boundary, typed payloads, test strategy, and binding/resolution point cannot be named, do not add the Port.

Good example:

```php
/**
 * Port boundary for document type detection.
 *
 * Exists to keep document workflows independent from the AI/OCR provider
 * and to allow tests to replace provider calls with a fake detector.
 */
interface DocumentTypeDetector
{
    public function detect(OcrResultData $ocrResult): DetectedDocumentTypeData;
}
```

```php
final readonly class LaravelAiDocumentTypeDetector implements DocumentTypeDetector
{
    public function __construct(
        private DocumentClassificationAgent $agent,
    ) {
    }

    public function detect(OcrResultData $ocrResult): DetectedDocumentTypeData
    {
        $result = $this->agent->classify($ocrResult->text);

        return new DetectedDocumentTypeData(
            type: DocumentType::from($result->type),
            confidence: Confidence::fromFloat($result->confidence),
        );
    }
}
```

Bad example:

```php
interface CreateInvoiceInterface
{
    public function handle(CreateInvoiceData $data): Invoice;
}

final class CreateInvoice implements CreateInvoiceInterface
{
    public function handle(CreateInvoiceData $data): Invoice
    {
        return Invoice::create($data->toArray());
    }
}
```

This interface mirrors one local class, has no provider or infrastructure boundary, and does not improve testability.
