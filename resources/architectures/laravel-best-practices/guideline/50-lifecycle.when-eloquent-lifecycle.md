Events, mail, notifications, and model lifecycle:

- Model observers and `*-ing` / `*-ed` hooks MUST follow the Eloquent Lifecycle architecture. See `architecture-kit-eloquent-lifecycle`.
- Observers are adapters only; workflow decisions belong in enabled application boundaries.
- Prefer queued listeners or jobs for post-commit side effects.
