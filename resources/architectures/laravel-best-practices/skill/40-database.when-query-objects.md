## Database And Eloquent

- Read-side query composition that grows beyond a simple model query MUST move into Query Objects. See `architecture-kit-query-objects`.
- Do not assemble multi-branch filters, joins, eager-load decisions, or sorting logic in controllers.
- Keep Eloquent relationships and casts on the model, but move endpoint-specific read composition into the Query Object.
- Use transactions around multi-write operations that must commit or roll back together.
