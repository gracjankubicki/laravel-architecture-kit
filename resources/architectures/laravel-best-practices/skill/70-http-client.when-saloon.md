## HTTP Client

- Application-owned direct HTTP integrations MUST go through Saloon connectors and requests. See `architecture-kit-saloon`.
- Appropriate official SDKs may keep their own HTTP or gRPC transport inside provider adapters. Do not rewrite them as Saloon Requests.
- Keep authentication, timeouts, retries, rate limits, request payloads, and response mapping inside the owning Saloon or SDK adapter. Do not stack an independent retry loop on SDK retries.
- With Ports And Adapters enabled, Actions and Jobs call application Ports; the adapter calls Saloon or the SDK. Without it, an Action or Job may call the Saloon integration directly. Controllers must not build integration calls.
- Do not create ad-hoc framework-level HTTP calls for integrations that belong in Saloon.
