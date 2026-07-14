Compatibility profile: `laravel-ai@0.9` (`>=0.9.0 <0.10.0`).

Purpose:
Laravel AI represents production AI workflows built on `laravel/ai` 0.9.

Default placement:
- `app/Ai/Agents`
- `app/Ai/Tools`
- `app/Ai/Gateways`
- `app/Ai/Data`
- `app/Ai/Prompts`
- `app/Ai/Context`
- `app/Ai/Telemetry`

Rules:
- Controllers, requests, resources and models must not call Laravel AI directly. Route execution through a project-owned Gateway, Action or Job.
- A production Agent is a dedicated declarative class. It must not query Eloquent, call arbitrary HTTP, persist domain state, authorize users or choose business transitions.
- Runtime input belongs in typed Prompt Data. Structured output is mapped through `toArray()` or ArrayAccess into project-owned Result Data before persistence.
- Configure 0.9 provider options through `withProviderOptions()` when the workflow needs explicit provider behavior.
- Laravel AI response objects and provider exceptions must not cross the Gateway boundary.
- Production Tools are dedicated classes. Write Tools delegate to Actions that own validation, authorization, idempotency and audit behavior.
- Long-running domain workflows use project Jobs and visible retry/lifecycle policy. Hidden retries are forbidden.
- Persist correlation, workflow, provider/model, usage, status and failure metadata for non-trivial workflows. Sensitive raw prompts/responses require an explicit protected retention policy.
- User-visible, legal, financial and business-critical output defaults to draft or `review_required`.
- Tests must cover Prompt Data, Result Data validation, Gateway fakes, provider/options resolution and Tool boundaries without real providers.

Canonical flow:

```text
Action or Job -> Context Builder -> Prompt Data -> AI Gateway -> Laravel AI Agent -> Result Data -> Action/domain persistence
```

For exact SDK features and testing APIs, load the official `ai-sdk-development` skill from the installed Laravel AI package. Without Boost, read `vendor/laravel/ai/resources/boost/skills/ai-sdk-development/SKILL.md` and the official Laravel AI documentation.
