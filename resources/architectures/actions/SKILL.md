---
name: architecture-kit-actions
description: Use Actions for named Laravel application write use cases.
---

# Actions

Use this skill when implementing or refactoring write/application use cases.

## Workflow

1. Name the business operation with an imperative class name.
2. Create a `final` class under the project's Action namespace.
3. Add one public `handle(...)` method.
4. Accept a Data Object or explicit typed arguments.
5. Keep request and response concerns in the adapter.
6. Put transaction boundaries in the Action when atomicity is required.
7. Test the Action by calling `handle(...)` directly.

## Rules

- Do not add an `Action` suffix.
- Do not create repositories merely to hide Eloquent.
- Do not split one use case into artificial micro-Actions.
- Orchestrator Actions are allowed only when the class names a larger workflow.
