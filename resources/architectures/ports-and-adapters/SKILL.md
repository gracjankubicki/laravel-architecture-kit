---
name: architecture-kit-ports-and-adapters
description: Use Ports And Adapters for real Laravel outbound provider, infrastructure, package, legacy, runtime, or testability boundaries.
---

# Ports And Adapters

Use this skill when adding or refactoring an application boundary that separates Laravel application code from a provider, SDK, external API, AI/OCR service, legacy system, infrastructure dependency, package extension point, runtime seam, or concrete testability seam.

Do not use this skill to generate interfaces for ordinary Actions, Services, Query Objects, or Eloquent code.

## Required Pre-check

Before adding a Port, answer:

1. What boundary does this Port protect?
2. Why is Laravel's native abstraction or concrete DI not enough?
3. What provider/vendor/framework details must not leak into the application?
4. What project-owned input/output types cross the boundary?
5. How will this be tested without the real provider?
6. Where is the Adapter bound or resolved?
7. Does this duplicate an existing interface, binding, Laravel abstraction, or package fake?

If the protected boundary, typed payloads, test strategy, and binding/resolution point cannot be named, do not add the Port.

## Workflow

1. Check existing Laravel abstractions, project interfaces, service provider bindings, adapters, package contracts, and enabled Architecture Kit patterns.
2. Name the application capability first.
3. Add a small Port interface only when a real boundary exists.
4. Add short PHPDoc to the Port explaining why it exists and which boundary it protects. A project may enforce a bilingual policy through a custom audit rule.
5. Use project-owned immutable inputs and outputs: Data objects, Value Objects, enums, result objects, or explicit scalars.
6. Implement the Port with a named Adapter near the provider, SDK, integration, or infrastructure it wraps.
7. Bind or resolve the Adapter explicitly only when runtime/test wiring needs the abstraction.
8. Test the Adapter without calling the real provider.

## Rules

- Ports And Adapters is opt-in and focuses mainly on outbound boundaries.
- Do not create inbound Port interfaces for every controller, command, job, listener, Action, or Service.
- Use Laravel's native abstractions and concrete dependency injection by default.
- Do not wrap Laravel abstractions unless the application needs a named domain capability above them.
- Use interfaces only for real architectural boundaries: external systems, multiple real implementations, swappable providers, package/public contracts, framework integration points, or concrete testability seams.
- Do not create one interface per class by default.
- A Port is application-owned. It must not expose provider-shaped arrays, SDK classes, HTTP responses, vendor exceptions, or framework request/response types.
- Name Ports by application capability. Name Adapters by implementation, provider, SDK, or integration.
- Every Port interface must include PHPDoc explaining why the Port exists and naming the protected boundary. Bilingual wording is a project policy, not a public core rule.
- When Data Objects are enabled, Port methods must not use raw arrays as boundary payloads.
- Port boundaries should use immutable inputs and outputs.
- Adapter classes should be `final readonly` by default.
- Adapters translate provider payloads and vendor exceptions into project-owned types and exceptions.
- Adapters may configure authentication, timeout, transport retry, backoff, rate limit, and provider error mapping.
- Adapters must not orchestrate application side effects, mutate model state, send notifications, dispatch domain jobs/events, or start unrelated workflows.
- Controllers must not call Adapters directly. Delegate to an enabled application boundary such as an Action or cohesive Service.
- Services may depend on Ports, but broad Services should not implement Ports by default.
- When multiple Adapters implement one Port, choose through explicit config, enum, or resolver logic.
- Do not introduce a strategy registry or plugin system without a real extensibility need.
- Do not introduce repository interfaces just to hide Eloquent.
- Do not introduce CQRS as a separate architecture. Use Actions for writes and Query Objects for reusable or non-trivial reads.
- Do not model authorization, permission checks, or pure domain calculations as Ports.
- Do not use closures or untyped callables as production Adapters.
- Port interfaces must declare behavior and must not be empty marker interfaces.
- Avoid Port inheritance in application code. Prefer composing small Ports in Actions or Services.
- Use shared contract tests only when multiple Adapters implement the same Port.
- Do not add a Port only for testing when the concrete class or Laravel/package abstraction can already be faked, mocked, or swapped cleanly.

## Examples

Good:

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

Bad:

```php
interface CreateInvoiceInterface
{
    public function handle(CreateInvoiceData $data): Invoice;
}
```

This mirrors one local Action and has no provider, infrastructure, package, legacy, runtime, or testability boundary.
