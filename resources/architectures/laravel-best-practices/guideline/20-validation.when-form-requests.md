Validation:

- All non-trivial HTTP validation MUST use Form Requests. See `architecture-kit-form-requests`.
- Controllers should receive already validated input and pass DTO/Data objects or validated values into the next enabled boundary.
- Do not place validation arrays directly in controllers when a Form Request is appropriate.
