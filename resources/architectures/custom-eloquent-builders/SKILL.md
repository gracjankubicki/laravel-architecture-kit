---
name: architecture-kit-custom-eloquent-builders
description: Use Custom Eloquent Builders for reusable model-specific query vocabulary.
---

# Custom Eloquent Builders

Use this skill when repeated model-specific query methods should live on a custom Eloquent Builder.

## Workflow

1. Confirm the method belongs to one model's query vocabulary.
2. Add a final builder class under `app/Models/Builders`.
3. Register it from the model with `newEloquentBuilder`.
4. Keep methods chainable unless they are intentionally terminal.
5. Compose builder methods from Query Objects when read behavior is endpoint-specific.

## Rules

- Builders do not mutate domain state.
- Builders do not know about requests or responses.
- Builders should not become report or endpoint classes.
- Builder folders MUST contain custom Eloquent Builders only.
- Do not put Query Objects, Data Objects, Actions, API Resources, or Enums under `app/Models/Builders/**`.
