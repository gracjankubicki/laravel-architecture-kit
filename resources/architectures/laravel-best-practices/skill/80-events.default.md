## Events, Mail, Notifications, And Jobs

- Dispatch side effects after the state change that makes them true.
- Prefer queued jobs/listeners for work that does not need to block the request.
- Keep event listeners focused on one reaction.
- Do not hide workflow decisions inside observers or model callbacks.
