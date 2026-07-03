HTTP client:

- All outbound HTTP integrations MUST go through Saloon connectors and requests. See `architecture-kit-saloon`.
- Do not create ad-hoc framework-level HTTP calls for integrations that belong in a connector.
- Keep retries, authentication, rate limits, and response mapping inside the Saloon integration boundary.
