---
name: architecture-kit-data-objects
description: Use immutable Data Objects for typed Laravel boundary payloads.
---

# Data Objects

Use this skill when typed payloads should cross application boundaries.

## Workflow

1. Name the payload after the use case or boundary and use the `Data` suffix.
2. Use `final readonly class`.
3. Add typed promoted constructor properties.
4. Add boundary factories only when useful.
5. Keep behavior limited to mapping or simple structural helpers.

## Rules

- No setters.
- No Eloquent inheritance.
- No business workflow.
- Prefer Value Objects for domain values when enabled.
