## Controllers And Routing

- Controllers MUST follow Thin Controllers. See `architecture-kit-thin-controllers`.
- Controllers should delegate behavior to enabled application boundaries and return responses.
- Do not place validation, database query composition, integration workflows, or business decisions in controllers.
- Use route model binding when it keeps the route contract clear.
