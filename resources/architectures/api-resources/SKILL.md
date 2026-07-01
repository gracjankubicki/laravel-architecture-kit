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
- Resources format output only.
