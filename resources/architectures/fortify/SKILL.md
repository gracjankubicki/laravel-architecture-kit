---
name: architecture-kit-fortify
description: Extend Laravel Fortify 1 actions, authentication, response contracts, and enabled features while preserving the project's architecture and security boundaries.
---

# Laravel Fortify 1

Use this skill when you add or change a Fortify action, provider registration, login customization, response binding, password flow, email verification, two-factor authentication, or passkey flow.

## Inspect the active contract

1. Read `config/fortify.php`, `config/auth.php`, the Fortify provider, the enabled Architecture Kit profiles, and the affected HTTP tests.
2. Confirm which Fortify features the application enables. Treat every other feature as out of scope.
3. Identify the native Fortify contract and method that own the extension point.
4. Check the guard, limiter, session, middleware, and authorization behavior before changing the flow.

## Keep native actions native

Implement the method required by the Fortify contract:

- `CreatesNewUsers::create()`
- `ResetsUserPasswords::reset()`
- `UpdatesUserProfileInformation::update()`
- `UpdatesUserPasswords::update()`

Keep a small operation in that class. Fortify provides an input array, so apply the existing validator or rule objects directly. Do not add `handle()` or a Form Request only to match another profile.

When the operation is shared by another entry point or owns a wider business workflow, delegate to an ordinary application Action:

```php
final readonly class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private RegisterAccount $registerAccount) {}

    public function create(array $input): User
    {
        return $this->registerAccount->handle(RegisterAccountData::from($input));
    }
}
```

The Fortify action adapts the contract. The delegated Action owns `handle()` and the reusable workflow.

## Keep the provider declarative

- Register action classes and response contracts in the provider.
- Use view callbacks to prepare a Blade or Inertia response.
- Put reusable reads in the enabled read boundary and writes in an Action.
- Implement the matching Fortify response contract with public `toResponse()`.
- Keep Fortify independent from Inertia. When Inertia is enabled, compose its response in the callback or response class.

## Preserve authentication safeguards

Use `Fortify::authenticateUsing()` only for a custom credential lookup. Return the user, `null`, or `false` according to Fortify's contract.

When you use `Fortify::authenticateThrough()`, retain the stages that throttle login and prepare the authenticated session. Retain the two-factor redirect stage when two-factor authentication is enabled. Keep the configured guard stateful. For browser and SPA sessions, use the `web` guard unless the project has an accepted alternative.

Define the rate limiters referenced by Fortify configuration. Keep authorization on the server for every protected action.

## Respect enabled features

Work only on features already listed in the application's Fortify configuration. This includes registration, password reset, email verification, two-factor authentication, and passkeys. Preserve their tokens, middleware, password confirmation, challenges, and recovery flow.

Do not add `Features::*` entries or change authentication configuration unless the task explicitly requires that behavior.

## Verify the public flow

- Test the enabled HTTP route and its validation response.
- Test login throttling and session regeneration when authentication changes.
- Test authorization and middleware for protected routes.
- Test the native Fortify action and any delegated application Action.
- Test custom response redirects or payloads.
- Test Blade, API, and Inertia paths that the application actually supports.

## Know what the guard checks

The deterministic `fortify` rule validates explicit Fortify action registrations and supported response bindings against their required contract and public method. It reports a warning when a dynamic target or unavailable source prevents proof. The shared folder rules recognize the same contracts, so valid native Fortify actions do not need `handle()` and valid Fortify responses are allowed under `app/Http/Responses/**`.

The guard does not prove credential logic, authorization, session regeneration, throttling behavior, enabled feature configuration, or frontend behavior. Prove those with focused application tests.
