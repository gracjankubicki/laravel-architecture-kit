## Events, Mail, Notifications, And Jobs

- Use Actions for flow-specific orchestration when Actions are enabled. See `architecture-kit-actions`.
- Jobs, listeners, commands, and observers should call Actions instead of owning business decisions.
- Prefer queued jobs/listeners for work that does not need to block the request.
- Keep event listeners focused on one reaction.
