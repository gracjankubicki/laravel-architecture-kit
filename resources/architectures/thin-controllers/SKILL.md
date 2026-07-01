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
