HTTP client:

- Use Laravel's `Http` facade with explicit `timeout()` and retry policy for simple outbound HTTP calls.
- Do not use raw `curl_*` calls.
- Keep request/response mapping outside controllers.
