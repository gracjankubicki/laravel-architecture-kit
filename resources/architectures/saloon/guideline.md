Purpose:
Saloon defines the required architecture for application-owned outbound HTTP integrations. Official provider SDKs may keep their own HTTP or gRPC transport inside project adapters.

Required packages:
- `saloonphp/saloon` with a Saloon 4 compatible constraint that does not allow Saloon 3.
- `saloonphp/laravel-plugin`
- `saloonphp/rate-limit-plugin`

Default placement:
- `app/Http/Integrations/<Service>/<Service>Connector.php`
- `app/Http/Integrations/<Service>/Requests/*Request.php`
- `app/Http/Integrations/<Service>/Dto/*Data.php`

Rules:
- Application-owned direct HTTP integrations go through Saloon.
- Do not rewrite an appropriate official SDK as Saloon Requests or wrap its transport in Saloon. Keep the SDK inside a provider adapter near the owning area, for example `app/Advertising/Adapters/GoogleAdsCampaignReader.php`.
- `app/Http/Integrations/**` remains reserved for Saloon Connectors, Requests, integration DTOs, and integration-local support.
- Do not use `Http::`, direct Guzzle clients, `curl_*`, or `file_get_contents('http...')` outside `app/Http/Integrations/**`.
- Use one `final` Connector per external service.
- Use one `final` Saloon Request class per endpoint.
- Request classes use the `Request` suffix; this is an intentional Saloon ecosystem exception to the Actions no-suffix rule.
- Connectors extend `Saloon\Http\Connector`.
- Requests extend `Saloon\Http\Request` or `Saloon\Http\SoloRequest`.
- Connector base URLs and credentials come from `config('services.*')`, never `env()` outside config and never hard-coded URLs.
- Connector classes use `AlwaysThrowOnErrors`.
- Connector classes define resilience defaults: retries, backoff, explicit timeouts, and `HasRateLimits`.
- Request endpoints are relative paths only. `resolveEndpoint()` must not return absolute `http://` or `https://` URLs.
- Request input is typed through constructors: Data Objects, Value Objects, models, or explicit scalar arguments.
- Requests map responses through `createDtoFromResponse()`.
- Code outside `app/Http/Integrations/**` must not consume raw Saloon responses with `->json()`, `->body()`, or response arrays.
- Callers use `->dto()` or `->dtoOrFail()`.
- Integration response DTOs live in `app/Http/Integrations/<Service>/Dto/`, are `final readonly`, and use a Data/Dto/Result suffix.
- Without Ports And Adapters, integration DTOs die at the Action/Job boundary. With Ports And Adapters, the adapter maps Saloon or SDK data and exceptions to project-owned Port results and errors.
- Controllers, FormRequests, API Resources, and Models must not instantiate connectors, import integration classes, or send Saloon requests.
- Without Ports And Adapters, integration calls and Saloon exception mapping live in Actions or queued Jobs.
- With Ports And Adapters, Actions and Jobs orchestrate application Ports. Adapters perform provider calls and map provider exceptions. Do not create a Port per class.
- APIs returning HTTP 200 with error bodies must override `hasRequestFailed()`.
- Prefer queued Jobs for external calls. Never call external APIs inside an open database transaction.
- Saloon adapter tests use `MockClient` / `Saloon::fake()` and fixtures grouped under `tests/Fixtures/Saloon/<service>/`.
- Enable `Config::preventStrayRequests()` in the base test case so tests never hit real APIs.
- For each official SDK, verify a supported fake, replaceable client or transport, or configurable endpoint for a local server. Isolate credentials, authentication, and token refresh too. If the SDK has no workable isolation seam, report the limit and require a separate SDK-versus-Saloon decision.
- A fake Port tests the Action workflow. It does not test adapter payload mapping or error translation. `Saloon::fake()`, `Config::preventStrayRequests()`, and Laravel `Http::fake()` do not intercept SDK traffic.
- Never `serialize()` or `unserialize()` authenticators for storage. Store token fields explicitly.
- Fixture names are static literals and must not include path segments built from variables.
- Credentials stay in configuration. Both Saloon and SDK adapters use typed inputs and project-owned results, explicit timeouts and rate-limit handling, and one named owner for retry behavior. Use SDK retry support without adding a second retry loop. Retry mutations only when their semantics are safe.
- Never call Saloon or an SDK while a database transaction is open.
- Ports And Adapters does not require Saloon. When both are enabled, use Saloon for application-owned direct HTTP and keep official SDK transport inside its adapter.
