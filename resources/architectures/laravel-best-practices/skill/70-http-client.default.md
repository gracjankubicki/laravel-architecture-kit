## HTTP Client

- Use Laravel's `Http` facade for simple outbound HTTP calls.
- Always set explicit `timeout()` and retry behavior for network calls.
- Do not use raw `curl_*` calls or instantiate low-level clients directly unless a package requires it.
- Keep request payload construction and response mapping outside controllers.
