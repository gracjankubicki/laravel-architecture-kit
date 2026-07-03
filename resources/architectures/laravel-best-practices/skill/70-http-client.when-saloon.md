## HTTP Client

- All outbound HTTP integrations MUST go through Saloon connectors and requests. See `architecture-kit-saloon`.
- Keep authentication, retries, rate limits, request payloads, and response mapping inside the Saloon integration boundary.
- Application code should call an Action or Job that uses the connector; controllers must not build integration calls.
- Do not create ad-hoc framework-level HTTP calls for integrations that belong in Saloon.
