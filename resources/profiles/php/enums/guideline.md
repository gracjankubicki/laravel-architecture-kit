Enums represent closed domain states, types and choices.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Use native PHP enums for closed sets; use backed enums when a stable scalar representation is needed.
- Place the enum near the domain that owns it, or in the shared Enums location when genuinely shared.
- Map external and persisted scalar values at their boundary.
- Keep arbitrary open-ended external values as data instead of inventing enum cases.
- Keep display text and translation integration explicit; no Laravel dependency is required.
