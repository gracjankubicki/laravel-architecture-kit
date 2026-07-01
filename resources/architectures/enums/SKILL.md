---
name: architecture-kit-enums
description: Use PHP enums for closed Laravel domain states, types, categories, and choices.
---

# Enums

Use this skill when replacing magic strings, constants, status fields, type fields, or finite option lists.

## Workflow

1. Confirm the set of values is finite and meaningful in the domain.
2. Use a backed enum when values cross a database, API, queue, or config boundary.
3. Put enum behavior on the enum only when it describes the value itself.
4. Add Eloquent casts for model columns that store enum values.
5. Format enums explicitly in API Resources.

## Rules

- Do not use string constants for new finite state/type sets.
- Do not put workflows or service calls in enums.
- Do not use enums for open-ended user-defined values.
- Keep enum methods small and value-focused.
