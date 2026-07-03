## Controllers And Routing

- Keep routes readable and controllers thin.
- Controllers should translate HTTP input into application calls and translate the result into a response.
- Do not put business decisions, integration workflows, or database query composition in controllers.
- Use route model binding when it keeps the route contract clear.
