---
name: architecture-kit-value-objects
description: Apply Value Objects to this Composer package.
---

Value Objects represent immutable domain values and enforce their invariants.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Use a final readonly class.
- Name the domain concept directly without a ValueObject, VO, Data or Dto suffix.
- Validate invariants at construction and keep behaviour local to the value.
- Keep persistence, transport and mutable service dependencies outside the Value Object.
- Map stored representations explicitly at the persistence boundary.
- Expose typed operations and comparisons when the domain needs them.

Choose tests for changed behaviour and verify the enabled Architecture Kit rules before finishing.
