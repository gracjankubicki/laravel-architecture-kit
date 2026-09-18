Compatibility profile: `fortify@1` (`>=1.0.0 <2.0.0`).

Use Fortify as the frontend-agnostic authentication backend. This profile applies to Blade, Inertia, and API-oriented applications. Inertia is optional.

## Native extension contracts

- Keep `CreatesNewUsers::create()`, `ResetsUserPasswords::reset()`, `UpdatesUserProfileInformation::update()`, and `UpdatesUserPasswords::update()` as Fortify's public entry methods.
- Keep a simple operation in its standard Fortify action. Do not add `handle()` only to satisfy the general Actions convention.
- When an operation is a wider or shared business workflow, call an ordinary application Action from the Fortify action. The ordinary Action owns `handle()` and the reusable workflow.
- Fortify passes arrays to these contracts. Validate the array with the project's existing rules and validators. Do not require a Form Request where Fortify does not provide an HTTP request object.
- A class under `app/Actions/**` receives the Fortify exception only when it implements a recognized contract and exposes that contract's public method. Its name and folder are not evidence.

## Provider and responses

- Register action classes and response contracts in the Fortify provider. Keep workflow code out of `register()` and `boot()`.
- Use view callbacks only to prepare a Blade or Inertia response. Put reusable reads in the enabled read boundary and writes in an Action.
- A custom Fortify response implements the matching `Laravel\Fortify\Contracts\*Response` contract and exposes `toResponse()`.
- Keep server-side authorization on every protected operation. A hidden control or client route is not an authorization check.

## Authentication and sessions

- Use `Fortify::authenticateUsing()` only when the credential lookup differs from Fortify's default behavior. Return the authenticated user, `null`, or `false` as required by Fortify.
- If you replace the authentication pipeline with `Fortify::authenticateThrough()`, retain login throttling and `PrepareAuthenticatedSession`. Preserve two-factor routing when the feature is enabled.
- Use a stateful guard. For a browser or SPA session, keep the `web` guard unless the application has an accepted authentication design that requires another stateful guard.
- Define named rate limiters for the configured Fortify limiter keys. Keep credential and recovery endpoints protected from repeated attempts.

## Enabled features

- Implement registration, password reset, email verification, two-factor authentication, and passkeys only when the application already enables the matching Fortify feature.
- Keep password-reset tokens, verified-email middleware, password confirmation, recovery codes, and two-factor challenges in their standard server-side flows.
- Do not add `Features::*` entries or change `config/auth.php` or `config/fortify.php` only because this profile is enabled.

## Tests

- Test each enabled authentication route through its public HTTP entry point.
- Assert validation, throttling, session regeneration, authorization, response redirects, and feature-specific state where relevant.
- Test the native Fortify action and any delegated application Action at their own boundaries.
- Test both the Blade or API response and the Inertia response when the application supports both presentation paths.

## Audit coverage

The deterministic `fortify` audit checks recognized action registrations and supported Fortify response bindings. For an explicit target class it verifies the required contract and public method. A confirmed mismatch is an error. A dynamic target, unavailable source, or bounded-analysis limit produces an incomplete-analysis warning.

The audit also lets recognized Fortify actions keep `create()`, `reset()`, or `update()` under `app/Actions/**`, and lets recognized Fortify response contracts live under `app/Http/Responses/**`. These exceptions apply only while the Fortify profile is enabled.

The audit does not prove credential correctness, authorization, session safety, throttling behavior, enabled feature configuration, or frontend behavior. Cover those requirements with application tests.

Source contract: `laravel/fortify` commit `fe0fce8814660317df0684f2c7be3b573def67d3` and Laravel 13 Fortify documentation.
