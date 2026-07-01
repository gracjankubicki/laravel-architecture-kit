---
name: architecture-kit-value-objects
description: Use immutable Value Objects for domain values and invariants.
---

# Value Objects

Use this skill when a domain concept needs type safety, invariants, and value semantics.

## Workflow

1. Name the class after the domain concept.
2. Use `final readonly class`.
3. Validate invariants at construction.
4. Expose behavior that belongs to the value.
5. Return new objects from transformation methods.
6. Map persistence explicitly or through Laravel casts.

## Rules

- No setters.
- No `ValueObject` suffix.
- No Eloquent inheritance.
- Value Objects protect the domain even when request validation exists.
