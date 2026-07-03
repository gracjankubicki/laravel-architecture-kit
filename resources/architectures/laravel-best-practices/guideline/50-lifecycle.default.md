Events, mail, notifications, and model lifecycle:

- Dispatch side effects after the state change that makes them true.
- Keep observers thin; they must not become hidden application workflows.
- Use jobs/listeners for asynchronous work when the result does not need to block the request.
