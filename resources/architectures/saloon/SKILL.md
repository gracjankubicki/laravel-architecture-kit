---
name: architecture-kit-saloon
description: Build Laravel outbound API integrations with Saloon connectors, requests, DTOs, resilience, and test fakes.
---

# Saloon

Use this skill when implementing or refactoring outbound HTTP/API integrations.

## Required Stack

This architecture requires:

- `saloonphp/saloon` v4-compatible constraint that does not allow Saloon v3.
- `saloonphp/laravel-plugin`
- `saloonphp/rate-limit-plugin`

Do not implement a custom HTTP client, custom retry layer, custom fixture layer, or ad hoc integration wrapper before using Saloon.

## Workflow

1. Create one Connector per external service under `app/Http/Integrations/<Service>/`.
2. Create one Request class per endpoint under `Requests/`.
3. Create immutable response DTOs under `Dto/`.
4. Call the integration from an Action or queued Job.
5. Map integration DTOs to domain/application results before returning to controllers.
6. Test with `MockClient` / `Saloon::fake()` and prevent stray requests.

## Connector Shape

Good:

```php
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Connector;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Faking\MockClient;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Helpers\LaravelCacheStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class FakturowniaConnector extends Connector
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;
    use HasRateLimits;

    public ?int $tries = 3;
    public ?int $retryInterval = 500;
    public ?bool $useExponentialBackoff = true;

    public function resolveBaseUrl(): string
    {
        return config('services.fakturownia.url');
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator(config('services.fakturownia.token'));
    }

    protected function resolveLimits(): array
    {
        return [
            Limit::allow(60)->everyMinute(),
        ];
    }

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new LaravelCacheStore(Cache::store());
    }
}
```

Rules:

- Connector is `final`.
- Connector extends `Saloon\Http\Connector`.
- Base URL and credentials come from `config('services.*')`.
- Never call `env()` inside integrations.
- Never hard-code service URLs.
- Use `AlwaysThrowOnErrors`.
- Use `HasRateLimits`.
- Define retries, backoff, and timeouts.

## Request Shape

Good:

```php
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Contracts\Body\HasBody;

final class CreateInvoiceRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly CreateInvoiceData $data,
    ) {
    }

    public function resolveEndpoint(): string
    {
        return '/invoices.json';
    }

    protected function defaultBody(): array
    {
        return $this->data->toPayload();
    }

    public function createDtoFromResponse(Response $response): InvoiceCreatedData
    {
        return InvoiceCreatedData::fromArray($response->json());
    }
}
```

Rules:

- Request is `final`.
- Request extends `Saloon\Http\Request` or `Saloon\Http\SoloRequest`.
- Request class name uses the `Request` suffix.
- Endpoint is relative. Never return an absolute `http://` or `https://` URL from `resolveEndpoint()`.
- Input is typed through the constructor.
- Response mapping lives in `createDtoFromResponse()`.

## DTO Boundary

Integration DTOs live under:

```text
app/Http/Integrations/<Service>/Dto/
```

They are:

- `final readonly`
- named with `Data`, `Dto`, or `Result` suffix
- local to the integration boundary

Good:

```php
final readonly class InvoiceCreatedData
{
    public function __construct(
        public string $externalId,
        public string $number,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            externalId: (string) $payload['id'],
            number: (string) $payload['number'],
        );
    }
}
```

Integration DTOs must not leak into controllers, API Resources, or models. Actions/Jobs map them to domain/application results.

## Application Boundary

Controllers, FormRequests, API Resources, and Models must not:

- instantiate connectors,
- import `App\Http\Integrations\...`,
- call `->send()` or `->sendAsync()`,
- consume Saloon responses.

Good:

```php
final readonly class IssueExternalInvoice
{
    public function __construct(
        private FakturowniaConnector $fakturownia,
    ) {
    }

    public function handle(CreateInvoiceData $data): ExternalInvoiceIssued
    {
        try {
            $dto = $this->fakturownia
                ->send(new CreateInvoiceRequest($data))
                ->dtoOrFail();
        } catch (RequestException $exception) {
            throw ExternalInvoiceFailed::fromSaloon($exception);
        }

        return ExternalInvoiceIssued::fromFakturownia($dto);
    }
}
```

Jobs may call connectors directly when the job is the async use case. Extract an Action only when a second caller needs the same workflow.

## Raw HTTP Is Forbidden

When Saloon is enabled, all outbound HTTP goes through Saloon:

- no `Http::`
- no direct `GuzzleHttp\Client`
- no `curl_*`
- no `file_get_contents('http...')`

This includes internal services such as localhost endpoints, monitoring, and own microservices.

## Failure Handling

Use `AlwaysThrowOnErrors` on connectors.

Catch Saloon exceptions at the Action/Job boundary and map them to named domain exceptions. Saloon exceptions must not reach controllers.

If an API returns HTTP 200 with an error payload, override `hasRequestFailed()`.

## Resilience

Every connector defines:

- retries,
- backoff,
- explicit timeouts,
- rate limits with `HasRateLimits`.

Prefer queued Jobs for external calls. Never call external APIs inside an open database transaction.

## Ports And Adapters

If Ports And Adapters are enabled, Saloon Connector and Request classes are technical integration adapters. Add an application Port above Saloon only when the workflow needs a provider-neutral capability boundary or tests should replace the provider call.

Ports And Adapters does not require Saloon. When both are enabled and an Adapter wraps an external HTTP API, use Saloon Connector/Request classes instead of raw HTTP clients.

## Testing

Use:

- `MockClient`
- `Saloon::fake()`
- fixtures under `tests/Fixtures/Saloon/<service>/`
- `Config::preventStrayRequests()` in the base test case

Test application handling of success, failure, and malformed responses. Do not test whether the provider API works.

## Security

- `resolveEndpoint()` returns relative paths only.
- Do not build endpoint paths from unsanitized user input.
- Never `serialize()` or `unserialize()` authenticators for storage.
- Store `accessToken`, `refreshToken`, and `expiresAt` explicitly.
- Fixture names are static literals without path segments from variables.
