Database and Eloquent:

- Prefer Eloquent relationships, scopes, casts, and collections before custom query plumbing.
- Keep database access out of controllers and views.
- Avoid N+1 queries by eager loading required relationships deliberately.
