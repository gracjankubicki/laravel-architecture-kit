Use Inertia 3 as a presentation adapter between Laravel and the existing React or Vue application. Do not use this profile to redesign the frontend.

## Page props

- Pass a small explicit array when a page has simple scalar or already formatted props.
- Do not pass an Eloquent model or collection without an explicit presentation mapping.
- When API Resources are enabled, use them to format model data that crosses the page boundary. Load relations before creating the Resource.
- When API Resources are not enabled, map the required model fields explicitly in the presentation layer.
- Extract a named page props class when composition is complex, reused, or easier to test outside the controller. Do not create one for every page.
- Use `ProvidesInertiaProperties` for one reusable property that needs the `PropertyContext`. It is not a required base for every page payload.
- Do not require Spatie Laravel Data. If Data Objects are enabled, keep them as typed transport values rather than Inertia response builders.

## Responsibilities

- Controllers, dedicated page responders, middleware, and named page props classes may compose Inertia responses.
- Actions and Query Objects must not depend on `Inertia\*` classes. They return application results or loaded read models.
- If Query Objects are enabled, use them for reusable reads and pass their result into the presentation layer.
- API Resources format data that is already loaded. They do not query, lazy-load relations, authorize, or write.
- Data Objects store typed data. They do not render pages or perform workflows.
- Prop preparation must not create, update, delete, dispatch, or perform another write.

## Requests, authorization, and forms

- Pass only named request fields. Use `validated()`, `safe()`, `only(...)`, or `input('field')` as appropriate.
- Never pass the whole `Request`, `$request->all()`, `$request->toArray()`, `$request->input()` without a key, or `$request->collect()` without a key to page props.
- Authorize on the server with policies, gates, Form Requests, or the owning application boundary. A prop can describe an allowed UI action, but the frontend is not the authorization boundary.
- On validation failure, use Laravel's redirect and session error flow. Do not return a custom validation-error page payload.
- After a successful write, redirect to a GET page instead of rendering the next page directly from the write request.
- Keep frontend prop types aligned with the serialized payload. Do not infer a TypeScript type from an Eloquent model shape.

## Shared and incremental data

- Keep shared data small and stable. Use names such as `auth.user` or `flash.message` to avoid collisions with page props.
- Share data in the Inertia middleware only when many pages need it. Keep route-specific data in the page response.
- Use closures for optional props that partial reloads can skip. Use `Inertia::optional(...)` for data requested only through `only`, and `Inertia::always(...)` only when the value must be included.
- Use `Inertia::defer(...)` for independent data that may arrive after the first response. Group related deferred props when they can load together.
- Handle deferred failures deliberately. Convert an expected failure with `rescue()` to a typed fallback or an explicit error prop. Do not hide an unexpected exception.

## Tests

- Test page name and serialized props with `assertInertia`.
- Test partial reload behavior with `reloadOnly` or the equivalent client test helper.
- Load and assert deferred props with `loadDeferredProps`.
- Test the server-side authorization and validation response independently of disabled frontend controls.

## Audit coverage

The `inertia` audit rule enforces two boundaries:

1. A class under `app/Actions/**` or `app/Queries/**` must not depend on a recognized `Inertia\*` symbol.
2. An Inertia page or shared-prop call must not receive the whole unfiltered request.

Explicit field selection is accepted. When static analysis sees request-derived props but cannot prove whether fields were selected, it reports an incomplete-analysis warning. The remaining guidance in this profile is advisory and requires application tests.
