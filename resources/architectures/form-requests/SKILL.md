---
name: architecture-kit-form-requests
description: Use Laravel Form Requests for request validation, authorization, and optional Data Object mapping.
---

# Form Requests

Use this skill when adding or refactoring request validation or authorization.

## Workflow

1. Put endpoint input rules in a typed Form Request.
2. Put request-level authorization in `authorize()`.
3. Delegate complex authorization to policies or gates when needed.
4. If Data Objects are enabled, add a `data()` method that returns the typed Data Object.
5. Keep Actions and Query Objects independent from HTTP request classes.

## Rules

- `authorize()` must represent the endpoint's access rule.
- `rules()` validates input shape and constraints.
- `data()` maps validated input, not raw request input.
- Form Requests do not perform persistence, transactions, or external side effects.
