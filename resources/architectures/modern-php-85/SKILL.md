---
name: architecture-kit-modern-php-85
description: Use modern PHP 8.5 language features in Laravel code when the project runtime supports PHP 8.5+.
---

# Modern PHP 8.5

Use this skill when implementing or refactoring code in a project that has enabled PHP 8.5 guidance.

This architecture is a runtime contract. It may only be enabled when `composer.json` requires PHP 8.5 or newer. Once enabled, do not write legacy-compatible PHP style "just in case". Use modern PHP where it improves clarity, type safety, or correctness.

## Workflow

1. Check that the project requires PHP 8.5+ before using PHP 8.5-only syntax.
2. Prefer strong typing, readonly immutability, enums, named arguments, and `match` expressions.
3. Apply modern style to new code and directly changed code only.
4. Use PHP 8.5 features where they improve readability or correctness.
5. Prefer Laravel idioms where they are clearer than language-level cleverness.
6. Keep compatibility with the project's declared PHP version.

## Rules

- Do not enable this architecture unless the project requires PHP 8.5+.
- Do not generate PHP 8.5-only syntax unless the project requires PHP 8.5+.
- Do not refactor whole files or modules just to modernize style without an explicit task.
- Do not use new syntax as decoration. Clarity beats brevity.
- New application PHP files should include `declare(strict_types=1)`.
- Config and migration files should follow the local project convention.
- Use explicit parameter and return types everywhere, unless a framework override requires a looser signature.
- Prefer `final` for new application classes unless inheritance, framework extension, or project tests require otherwise.
- Use `final readonly class` for immutable boundary and domain values.
- Prefer constructor property promotion for dependency injection, Data Objects, and Value Objects.
- Use `private` promoted dependencies in Actions/Services.
- Use `public readonly` promoted properties only for DTOs, Value Objects, and other data carriers.
- Prefer `match` over `switch` for value mapping and branching.
- Use named arguments when argument order is not obvious, especially multiple values with the same scalar type.
- Use `#[\Override]` for methods/properties that implement or override parent contracts when supported by the runtime.
- Use `#[\Override]` only when the parent class or implemented interface really declares the member. Do not change inheritance just to make the attribute valid.
- Do not put `#[\Override]` on Laravel FormRequest `authorize()` or `rules()` unless the actual parent class declares those methods.
- Use `#[\NoDiscard]` when ignoring a return value would be a bug.
- Prefer clone-with for immutable `with*()` methods when it keeps validation correct.
- Use the pipe operator `|>` very carefully and only for readable pure transformations.
- Prefer built-in URL/URI handling over ad hoc parsing when PHP 8.5 URI support is available.
- Use property hooks and asymmetric visibility only for simple, side-effect-free cases where they are clearer.
- Remove redundant PHPDoc that only repeats native types.
- Keep PHPDoc for generics, array shapes, non-empty strings, collection item types, domain constraints, and meaningful `@throws`.
- Use `mixed` only at true external/framework boundaries and map it to typed code quickly.
- Prefer immutable dates at DTO, Value Object, and domain boundaries.
- Avoid utility classes as a default. Prefer enums, Value Objects, Actions, Query Objects, or injectable services.

## Strict Types and Signatures

New application files should start with:

```php
<?php

declare(strict_types=1);
```

Use explicit signatures:

```php
final class PublishInvoiceAction
{
    public function execute(Invoice $invoice, User $actor): Invoice
    {
        // ...
    }
}
```

Use `void`, `never`, `self`, `static`, nullable, union, and intersection types when they describe the real contract.

Do not keep PHPDoc like this when it repeats native types:

```php
/**
 * @param string $name
 * @return bool
 */
public function isAllowed(string $name): bool
{
    // ...
}
```

Keep PHPDoc when PHP cannot express the detail:

```php
/**
 * @return \Illuminate\Support\Collection<int, Invoice>
 */
public function execute(User $user): Collection
{
    // ...
}
```

## Classes and Immutability

Prefer `final` for new application classes:

```php
final class CreateInvoiceAction
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private Clock $clock,
    ) {
    }
}
```

Use `final readonly class` for immutable Data Objects and Value Objects:

```php
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }
    }
}
```

Do not use `readonly` classes for Eloquent models, FormRequests, Controllers, Jobs, Mailables, Notifications, or other Laravel classes that the framework hydrates, serializes, or mutates.

Prefer public readonly promoted properties only for DTOs/VOs:

```php
final readonly class CreateInvoiceData
{
    public function __construct(
        public int $customerId,
        public Money $total,
        public CarbonImmutable $issuedAt,
    ) {
    }
}
```

Use private promoted dependencies in Actions/Services:

```php
final class CreateInvoiceAction
{
    public function __construct(
        private CreateInvoiceNumber $numbers,
        private DispatchInvoiceCreated $events,
    ) {
    }
}
```

## Named Arguments

Use named arguments when positional arguments are easy to confuse:

```php
$period = BillingPeriod::create(
    startsAt: $startsAt,
    endsAt: $endsAt,
    prorated: true,
);
```

This is especially important for several booleans, strings, integers, or optional parameters.

## Match Expressions

Prefer `match` for value mapping:

```php
public function label(): string
{
    return match ($this) {
        self::Draft => __('invoices.status.draft'),
        self::Issued => __('invoices.status.issued'),
        self::Paid => __('invoices.status.paid'),
    };
}
```

Use `switch` only when it is clearly better for a complex imperative flow. In new code, consider extracting that flow into methods instead.

## Override and NoDiscard

Use `#[\Override]` where the method implements or overrides a parent contract:

```php
final class InvoiceResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
        ];
    }
}
```

Do not do this:

```php
abstract class ArchitectureFormRequest extends EmailVerificationRequest
{
    #[\Override]
    public function rules(): array
    {
        return [];
    }
}
```

That changes the Laravel inheritance model to satisfy an attribute. Prefer a normal `FormRequest` base and omit `#[\Override]` for convention methods the framework resolves dynamically.

Use `#[\NoDiscard]` when ignoring the result would likely be a bug:

```php
final readonly class Money
{
    #[\NoDiscard]
    public function withAmount(int $amount): self
    {
        return new self($amount, $this->currency);
    }
}
```

Good candidates include immutable `with*()` methods, parsers, normalizers, calculators, and validators that return a result instead of mutating state.

## Clone-With

Use clone-with for immutable `with*()` methods when it preserves validation and reads better:

```php
final readonly class PriceFilter
{
    public function __construct(
        public ?Money $minimum,
        public ?Money $maximum,
    ) {
        if ($minimum !== null && $maximum !== null && $minimum->greaterThan($maximum)) {
            throw new InvalidArgumentException('Minimum price cannot exceed maximum price.');
        }
    }

    #[\NoDiscard]
    public function withMinimum(?Money $minimum): self
    {
        $next = clone($this, ['minimum' => $minimum]);

        if ($next->minimum !== null && $next->maximum !== null && $next->minimum->greaterThan($next->maximum)) {
            throw new InvalidArgumentException('Minimum price cannot exceed maximum price.');
        }

        return $next;
    }
}
```

Use `new self(...)` instead when constructor validation or normalization is clearer and should be reused directly.

## Pipe Operator

Use the pipe operator only for readable, pure transformations:

```php
$slug = $title
    |> trim(...)
    |> Str::lower(...)
    |> (fn (string $value): string => str_replace(' ', '-', $value));
```

Do not replace Laravel collection chains, query builders, middleware pipelines, or normal imperative workflows with `|>` just to be shorter.

## URI Handling

Use the PHP URI API for real URL/URI parsing and normalization when available:

```php
use Uri\Rfc3986\Uri;

$uri = new Uri($callbackUrl);
$host = $uri->getHost();
```

Do not parse URLs with ad hoc string operations when a URI parser is available.

## Property Hooks and Asymmetric Visibility

Use these only for simple, side-effect-free cases where the property syntax is clearer than a method.

Do not hide I/O, persistence, validation workflows, authorization, or Laravel accessor behavior in property hooks.

For immutable DTOs/VOs, prefer `readonly`. Use asymmetric visibility only when public read access and internal mutation after construction are both intentional.

## Typed Boundaries

Do not pass shape-less arrays between application layers when the data has structure and meaning. Use Data Objects or Value Objects when those architectures are enabled.

Allowed boundary shape:

```php
/**
 * @param array{id: int, status: string} $payload
 */
public function fromWebhookPayload(array $payload): InvoiceWebhookData
{
    return new InvoiceWebhookData(
        id: $payload['id'],
        status: InvoiceStatus::from($payload['status']),
    );
}
```

After the boundary, use typed objects.

Use `mixed` only for decoded JSON, external payloads, framework callbacks, dynamic config, or legacy boundaries. Normalize it immediately.

## Dates

Prefer immutable dates in DTOs, Value Objects, and domain boundaries:

```php
final readonly class InvoicePeriod
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {
    }
}
```

Follow existing Eloquent date conventions in models, but do not pass mutable dates through domain/application layers when immutable dates are available.

## Named Constructors

Prefer named constructors over one constructor with many optional meanings:

```php
final readonly class Money
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {
    }

    public static function fromGross(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }
}
```

Use a factory or Action when construction needs dependencies.

## Avoid Utility Classes

Do not create `SomethingHelper` or static utility classes for business behavior by default.

Choose the architecture that matches the responsibility:

- enum for closed sets of values;
- Value Object for a value with invariants;
- Action for a process or command;
- Query Object for a read use case;
- injectable service only when the project already uses that boundary for the responsibility.
