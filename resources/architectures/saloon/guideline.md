Purpose:
Saloon defines the required architecture for outbound HTTP/API integrations.

Required packages:
- `saloonphp/saloon` with a Saloon 4 compatible constraint that does not allow Saloon 3.
- `saloonphp/laravel-plugin`
- `saloonphp/rate-limit-plugin`

Default placement:
- `app/Http/Integrations/<Service>/<Service>Connector.php`
- `app/Http/Integrations/<Service>/Requests/*Request.php`
- `app/Http/Integrations/<Service>/Dto/*Data.php`

Rules:
- Every third-party or internal outbound HTTP integration goes through Saloon.
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
- Integration DTOs die at the Action/Job boundary. Map them to domain models, `app/Data` objects, Value Objects, or named domain results before returning to controllers or API Resources.
- Controllers, FormRequests, API Resources, and Models must not instantiate connectors, import integration classes, or send Saloon requests.
- Integration calls live in Actions or queued Jobs.
- Actions/Jobs catch Saloon exceptions and rethrow named domain exceptions. Saloon exceptions must not reach controllers.
- APIs returning HTTP 200 with error bodies must override `hasRequestFailed()`.
- Prefer queued Jobs for external calls. Never call external APIs inside an open database transaction.
- Tests use `MockClient` / `Saloon::fake()` and fixtures grouped under `tests/Fixtures/Saloon/<service>/`.
- Enable `Config::preventStrayRequests()` in the base test case so tests never hit real APIs.
- Never `serialize()` or `unserialize()` authenticators for storage. Store token fields explicitly.
- Fixture names are static literals and must not include path segments built from variables.

Reguły PL:
- Cały wychodzący HTTP idzie przez Saloon, także usługi wewnętrzne.
- `Http::`, Guzzle, `curl_*` i `file_get_contents('http...')` poza `app/Http/Integrations/**` są zakazane.
- Jeden connector per usługa, jedna klasa request per endpoint.
- DTO odpowiedzi integracji mieszkają w `app/Http/Integrations/<Usługa>/Dto/`, nie w `app/Data`.
- DTO integracji nie wychodzą poza granicę Action/Joba.
- Kontrolery, FormRequesty, API Resources i modele nie wołają integracji bezpośrednio.
- Connectory mają retry/backoff, timeouty, `AlwaysThrowOnErrors` i `HasRateLimits`.
- Endpointy requestów są ścieżkami względnymi, nigdy absolutnymi URL-ami.
- Testy integracji używają `MockClient` / `Saloon::fake()` i blokują przypadkowe prawdziwe requesty.
