## Database And Eloquent

- Prefer Eloquent relationships, casts, scopes, factories, and collections before custom query plumbing.
- Eager load relationships deliberately when the response or view needs them.
- Keep database access out of controllers, Blade views, API Resources, and validation rules.
- Use transactions around multi-write operations that must commit or roll back together.
- Avoid raw SQL unless the query cannot be expressed clearly with Laravel's query tools.
