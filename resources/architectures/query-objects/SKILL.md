---
name: architecture-kit-query-objects
description: Use Query Objects for named Laravel read use cases without mutations.
---

# Query Objects

Use this skill when implementing search, list, dashboard, report, or other named read behavior.

## Workflow

1. Decide whether the read behavior is more than a simple model lookup.
2. Create a `final` query class under `app/Queries` or the local project equivalent.
3. Use one public `handle(...)` method.
4. Accept typed filters, not HTTP request classes.
5. Compose Custom Eloquent Builder methods when available.
6. Return a paginator, collection, builder, scalar metric, or typed result object.

## Rules

- Query Objects do not mutate data.
- Query Objects do not authorize HTTP requests.
- Query Objects can own eager loading and pagination for their read use case.
- Query folders MUST contain Query Objects only.
- Do not put filter Data Objects, Result objects, Builders, Resources, Actions, or Enums under `app/Queries/**`.
