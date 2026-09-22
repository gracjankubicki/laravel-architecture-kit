---
name: architecture-kit-thin-controllers
description: Keep Laravel controllers as thin HTTP adapters around selected Architecture Kit boundaries.
---

# Thin Controllers

Use this skill when adding or refactoring Laravel controllers.

## Workflow

1. Identify whether the endpoint is a write use case, read use case, or simple resource endpoint.
2. Use a Form Request for validation and authorization when enabled.
3. Call one Action for write behavior when Actions are enabled.
4. Call one Query Object for named read behavior when Query Objects are enabled.
5. Return an API Resource when API Resources are enabled.
6. Move business decisions out of the controller before adding more HTTP handling.

## Rules

- Controllers adapt HTTP. They do not own domain workflow.
- One controller method should call at most one Action.
- Do not open database transactions in controllers.
- Do not call external APIs from controllers.
- Do not create several models with conditional business decisions in controllers.
- When Actions are enabled, do not inject `App\Services` into controllers for write use cases. Create a named Action and inject that instead.
- Avoid `app(SomeClass::class)` in controllers. Prefer constructor/method injection, or move behavior to an Action or Query Object.
- Response formatting belongs in API Resources when that architecture is enabled.

### Route-aware read checks

With Thin Controllers and Actions enabled, the audit reads a fresh Laravel route collection and associates each controller method with its HTTP verbs. GET/HEAD may call a cohesive read Service when Services are enabled; when Query Objects are enabled, use a Query Object for reusable read composition. POST/PUT/PATCH/DELETE Service calls retain the Action advisory. Imports and unused injected parameters are not dependency findings. Constructor dependencies are attributed to the methods that call them.

The audit follows reachable project method bodies without executing endpoints. Recognized model, builder, relation and DB mutations, jobs, mail and notifications remain visible with a call chain and the operation's file/line. A write reached through GET or HEAD is not an Architecture Kit violation by itself. Placement advice is non-blocking `suggestions`; enabled rules still report their own findings. It examines called methods, not unrelated write methods in the same Service. A transaction alone is not a write; its callback is analysed.

`analysis.notices` records unresolved routes, calls, types, raw SQL, and bounded traversal. Notices identify incomplete analysis and do not fail `--strict` by themselves. Absence of a notice means no effect was detected in the supported static subset, not proof that runtime hooks, model events, macros, magic dispatch, vendor SDKs or every possible branch are harmless. Dynamic SQL is never treated as a proved read. `W_THIN_CONTROLLER_READ_SERVICE` remains an enforced warning when Query Objects are enabled.

Guard success means that no enforced rule blocks the change. Review architectural suggestions separately. Suggestions may propose an architecture that is not enabled; do not enable it or refactor outside the agreed scope without the user's decision. Incomplete analysis identifies unresolved code, not a violation or proof of correctness. Keep enforcing the project's selected architecture boundaries.

Route discovery boots Laravel in a fresh child PHP process, with a process-local route-cache override. It does not dispatch a request or instantiate controllers, and does not clear the application's route cache. Normal application bootstrap code still runs. This keeps a long-lived MCP server from using routes from its initial boot. The timeout is 15 seconds; boot failure becomes an explicit incomplete-analysis finding when relevant. Programmatic callers without a Laravel bootstrap can pass a fresh `RouteMap::fromRoutes($router->getRoutes())` as the optional `routes` argument to `ApplicationAudit::run()`.

Analysis is limited to 12 method levels, 128 method visits and 20,000 AST nodes per endpoint, 100 KB per source file and 1 MB of retained source per controller, with an additional PHP memory headroom check. A cycle or exceeded budget is incomplete analysis. Source lookup stays in the configured project graph; excluded/out-of-scope dependencies are unresolved. The graph cache format is unchanged. In `--changed`, changed dependencies recheck their controller dependents; edits under routes/bootstrap/config/providers and deleted inputs recheck all controllers. Custom route-registration files outside those locations require a full audit.
