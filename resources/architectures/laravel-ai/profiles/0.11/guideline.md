Compatibility profile: `laravel-ai@0.11` (`>=0.11.0 <0.12.0`).

Purpose:
Laravel AI represents production AI workflows built on `laravel/ai` 0.11.

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
- Configure provider-specific behavior through `withProviderOptions()` only when the workflow requires it.
- A project-owned Gateway maps SDK responses, `ProviderConnectionException`, `StreamErrorException` and other provider failures into application contracts. SDK responses and exceptions do not cross that boundary.
- Consume a stream inside the protected failure boundary. A `StreamErrorException` may occur during iteration, while a successful terminal event is `StreamEnd`; constructing the response is not proof that the stream completed.
- Define failover and retry policy explicitly. A provider connection failure is failoverable in 0.11, but already emitted stream fragments cannot be rolled back and application side effects still require idempotency.
- Production Tools are dedicated classes. Write Tools delegate to Actions that own validation, authorization, idempotency and audit behavior.
- Side-effecting Tools use human-in-the-loop approval when a user must review the action. Approval never replaces domain authorization inside the delegated Action.
- Approval resumes and externally visible effects are idempotent by tool call ID. The SDK does not make application writes idempotent automatically.
- Remembered conversations use the polymorphic participant identity. Continuing a conversation by ID requires an application authorization check against that participant.
- Queued fakes in 0.11 dispatch the real `InvokeAgent` or `BroadcastAgent` job. Tests must use an intentional queue driver and assert callback and persistence effects instead of assuming a fake pending dispatch suppresses execution.
- Persist correlation or invocation ID, workflow, provider/model, usage, status and failure metadata for non-trivial workflows. Do not persist sensitive raw prompts or responses without an explicit protected retention policy.
- User-visible, legal, financial and business-critical output defaults to draft or `review_required`.
- Tests cover Prompt Data, Result Data validation, Gateway fakes, stream consumption, queue callbacks, participant authorization, approval decisions and Tool boundaries without real providers.

Audit boundary:
- The Laravel AI audit enforces supported placement and confirmed SDK-call boundaries, including direct agent entry points and supported media/file operations.
- It can warn about literal provider/model selection in confirmed Laravel AI calls.
- It does not prove prompt quality, Result Data completeness, participant authorization, retention policy, retry safety or domain idempotency. Review and test those requirements explicitly.

Canonical flow:

```text
Action or Job -> Context Builder -> Prompt Data -> AI Gateway -> Laravel AI Agent -> Result Data -> Action/domain persistence
```

For exact SDK features and testing APIs, load the official `ai-sdk-development` skill from the installed Laravel AI package. Without Boost, read `vendor/laravel/ai/resources/boost/skills/ai-sdk-development/SKILL.md`, the official Laravel AI documentation and the matching Architecture Kit upgrade skill.
