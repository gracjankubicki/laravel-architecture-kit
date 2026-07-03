## Validation

- Validate request input before it reaches application behavior.
- Keep validation close to the HTTP boundary.
- Do not duplicate validation arrays across controllers.
- Use Laravel validation rules such as `Rule::enum()` where they communicate intent better than strings.
