---
name: architecture-kit-saloon
description: Build Laravel outbound API integrations with Saloon connectors, requests, DTOs, resilience, and test fakes.
---

# Saloon

Use this skill when implementing or refactoring application-owned outbound HTTP integrations, or when deciding how Saloon coexists with an official provider SDK.

## Required Stack

This architecture requires:

- `saloonphp/saloon` v4-compatible constraint that does not allow Saloon v3.
- `saloonphp/laravel-plugin`
- `saloonphp/rate-limit-plugin`

Do not implement a custom HTTP client, retry layer, fixture layer, or ad hoc wrapper before using Saloon for application-owned direct HTTP.

## Choose The Integration Path

- Use Saloon when the application owns the direct HTTP request and response contract.
- Use an appropriate official SDK inside a provider Adapter when the SDK owns HTTP or gRPC transport and provides useful provider behavior.
- Do not rewrite SDK calls as Saloon Requests or wrap SDK transport in Saloon.
- Keep SDK Adapters near the owning area, for example `app/Advertising/Adapters/GoogleAdsCampaignReader.php`.
- Keep `app/Http/Integrations/**` for Saloon Connectors, Requests, DTOs, and integration-local support.

## Workflow

1. Create one Connector per external service under `app/Http/Integrations/<Service>/`.
2. Create one Request class per endpoint under `Requests/`.
3. Create immutable response DTOs under `Dto/`.
4. Without Ports And Adapters, call the integration from an Action or queued Job and map its DTOs and exceptions there.
5. With Ports And Adapters, call an application Port from the Action or Job. Map Saloon and SDK data and exceptions inside the Adapter.
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

Integration DTOs must not leak into controllers, API Resources, models, or Port signatures. Without Ports And Adapters, Actions or Jobs map them to application results. With Ports And Adapters, the Adapter maps them before returning through the Port.

## Application Boundary

Controllers, FormRequests, API Resources, and Models must not:

- instantiate connectors,
- import `App\Http\Integrations\...`,
- call `->send()` or `->sendAsync()`,
- consume Saloon responses.

Good when Ports And Adapters are not enabled:

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

Jobs may call connectors directly when the job is the async use case and Ports And Adapters are not enabled. Extract an Action only when a second caller needs the same workflow.

## Raw HTTP Is Forbidden

When Saloon is enabled, application-owned direct HTTP goes through Saloon:

- no `Http::`
- no direct `GuzzleHttp\Client`
- no `curl_*`
- no `file_get_contents('http...')`

This includes internal services such as localhost endpoints, monitoring, and own microservices. The rule does not require an official SDK to replace its HTTP or gRPC transport with Saloon.

## Failure Handling

Use `AlwaysThrowOnErrors` on connectors.

Without Ports And Adapters, catch Saloon exceptions at the Action or Job boundary. With Ports And Adapters, catch them in the Adapter. Map them to named application or domain exceptions before they cross the active boundary. Saloon exceptions must not reach controllers.

If an API returns HTTP 200 with an error payload, override `hasRequestFailed()`.

## Resilience

Every Connector defines:

- retries,
- backoff,
- explicit timeouts,
- rate limits with `HasRateLimits`.

Prefer queued Jobs for external calls. Never call external APIs inside an open database transaction.

SDK Adapters must also define explicit timeouts, rate-limit handling, and one owner for retry behavior. Prefer the SDK's retry mechanism. Do not add a second retry loop. Retry a mutation only when an idempotency key or another provider guarantee makes it safe.

## Ports And Adapters

If Ports And Adapters are enabled, Actions and Jobs depend on application Ports for provider calls. The Adapter uses a Saloon Connector or an official SDK and translates provider data and exceptions to project-owned types. Do not create a Port per class.

Controllers, FormRequests, and API Resources delegate to an Action or cohesive Service. They do not call a Port implementation, SDK, Connector, or Request directly.

Ports And Adapters does not require Saloon. When both are enabled, use Saloon for application-owned direct HTTP and keep official SDK transport inside its provider Adapter.

## SDK And Saloon In One Application

The SDK names below match `googleads/google-ads-php` v35.0.0 and Google Ads API V25. The Adapter receives the generated service client, so a test can replace that client without using the real provider.

```php
use App\Advertising\Data\CampaignSummaryData;
use Google\Ads\GoogleAds\V25\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V25\Services\SearchGoogleAdsRequest;

interface CampaignReader
{
    public function find(string $customerId): CampaignSummaryData;
}

final readonly class GoogleAdsCampaignReader implements CampaignReader
{
    public function __construct(private GoogleAdsServiceClient $client) {}

    public function find(string $customerId): CampaignSummaryData
    {
        try {
            $rows = $this->client->search(SearchGoogleAdsRequest::build(
                $customerId,
                'SELECT campaign.id, campaign.name FROM campaign LIMIT 1',
            ));
        } catch (\Google\ApiCore\ApiException $exception) {
            throw AdvertisingProviderFailed::fromGoogleAds($exception);
        }

        return CampaignSummaryData::fromGoogleAdsRow($rows->iterateAllElements()->current());
    }
}
```

Another capability can use Saloon behind its own Port:

```php
interface InvoiceIssuer
{
    public function issue(CreateInvoiceData $data): ExternalInvoiceIssued;
}

final readonly class FakturowniaInvoiceIssuer implements InvoiceIssuer
{
    public function __construct(private FakturowniaConnector $connector) {}

    public function issue(CreateInvoiceData $data): ExternalInvoiceIssued
    {
        try {
            $dto = $this->connector->send(new CreateInvoiceRequest($data))->dtoOrFail();
        } catch (RequestException $exception) {
            throw ExternalInvoiceFailed::fromSaloon($exception);
        }

        return ExternalInvoiceIssued::fromFakturownia($dto);
    }
}
```

Bind both Adapters in a Service Provider. The Action depends only on the Ports:

```php
$this->app->bind(CampaignReader::class, GoogleAdsCampaignReader::class);
$this->app->bind(InvoiceIssuer::class, FakturowniaInvoiceIssuer::class);

final readonly class LaunchCampaign
{
    public function __construct(
        private CampaignReader $campaigns,
        private InvoiceIssuer $invoices,
    ) {}

    public function handle(LaunchCampaignData $data): LaunchCampaignResult
    {
        $campaign = $this->campaigns->find($data->customerId);
        $invoice = $this->invoices->issue($data->invoice);

        return new LaunchCampaignResult($campaign, $invoice);
    }
}
```

## Testing

For Saloon Adapters, use:

- `MockClient`
- `Saloon::fake()`
- fixtures under `tests/Fixtures/Saloon/<service>/`
- `Config::preventStrayRequests()` in the base test case

Test application workflow with fake Ports. Separately test each Adapter's request payload, successful mapping, provider failure, malformed response, and error translation without the real provider.

Before selecting an official SDK, verify one supported isolation mechanism:

- a library fake,
- a replaceable client or transport,
- a configurable endpoint and local test server.

The mechanism must isolate credentials discovery, authentication, and token refresh as well as the API call. If no workable mechanism exists, report that limit and require a separate decision to accept the SDK or use Saloon.

`Saloon::fake()`, `Config::preventStrayRequests()`, and Laravel `Http::fake()` do not intercept SDK-owned traffic. A fake Port does not prove Adapter mapping or error translation.

## Security

- `resolveEndpoint()` returns relative paths only.
- Do not build endpoint paths from unsanitized user input.
- Never `serialize()` or `unserialize()` authenticators for storage.
- Store `accessToken`, `refreshToken`, and `expiresAt` explicitly.
- Fixture names are static literals without path segments from variables.
