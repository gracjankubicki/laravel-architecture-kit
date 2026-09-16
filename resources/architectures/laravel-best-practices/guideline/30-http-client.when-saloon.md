HTTP client:

- Application-owned direct HTTP integrations MUST go through Saloon connectors and requests. See `architecture-kit-saloon`.
- Appropriate official SDKs may keep their own HTTP or gRPC transport inside provider adapters. Do not rewrite them as Saloon Requests.
- Do not create ad-hoc framework-level HTTP calls for integrations that belong in a connector.
- Keep credentials, timeouts, retries, rate limits, and response mapping inside the owning Saloon or SDK adapter. Do not stack an independent retry loop on SDK retries.
