Database and Eloquent:

- Read-side query composition that grows beyond a simple model query MUST move into Query Objects. See `architecture-kit-query-objects`.
- Keep controllers from assembling multi-branch filters, joins, or sorting logic inline.
- Return builders or explicit query results according to the enabled Query Objects rule.
