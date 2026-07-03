## Custom Eloquent Builders

- Model-specific reusable query language belongs in Custom Eloquent Builders. See `architecture-kit-custom-eloquent-builders`.
- Prefer a custom builder when the same model query vocabulary appears in several places.
- Keep endpoint orchestration outside the builder; builders expose query language, not workflow.
