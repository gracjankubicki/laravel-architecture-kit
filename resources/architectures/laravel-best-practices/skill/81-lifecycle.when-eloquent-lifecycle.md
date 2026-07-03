## Eloquent Lifecycle

- Model observers and `*-ing` / `*-ed` hooks MUST follow the Eloquent Lifecycle architecture. See `architecture-kit-eloquent-lifecycle`.
- Observers are adapters only; they must not become hidden workflows.
- Use `*-ing` hooks only for local model normalization, invariant checks, or vetoes.
- Use `*-ed` hooks to dispatch explicit events, listeners, or jobs for post-save work.
