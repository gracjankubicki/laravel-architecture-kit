## Framework Practices

- Security: use policies, gates, signed URLs, encryption, hashing, and CSRF protection through Laravel features.
- Caching: cache expensive reads with explicit keys and invalidation rules; do not cache mutable user-specific data casually.
- Scheduling: keep scheduled commands small and delegate behavior to enabled application boundaries.
- Migrations: make migrations deterministic, reversible where practical, and safe for existing data.
- Collections: use collection pipelines when they improve readability; prefer clear intermediate variables when a chain becomes dense.
- Blade and views: keep presentation logic in views, not database access or business workflows.
- Testing: cover changed behavior with the narrowest useful test and use Laravel test helpers before custom harnesses.
- Config and environment: read `env()` from config files only; application code should use `config()`.
- Performance: measure before optimizing and avoid broad rewrites without evidence.
