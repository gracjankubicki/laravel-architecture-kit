---
name: architecture-kit-api-resources
description: Use Laravel API Resources to define JSON response shape without queries or business logic.
---

# API Resources

Use this skill when implementing JSON response presentation.

## Workflow

1. Create `JsonResource` or `ResourceCollection` classes for API response shape.
2. Ensure required relations and counts are eager loaded before resource creation.
3. Use conditional resource helpers for optional data.
4. Format Value Objects explicitly.
5. Keep queries and business decisions outside Resources.

## Rules

- Resources do not query.
- Resources do not lazy load.
- Resources do not mutate state.
- Resources do not use service locator calls such as `app(SomeClass::class)`.
- Resources format output only.
- Resource folders MUST contain API Resources and Resource Collections only.
- Do not put Data Objects, Actions, Query Objects, Enums, Exceptions, or Value Objects under `app/Http/Resources/**`.
