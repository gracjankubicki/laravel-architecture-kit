Purpose:
Modern PHP 8.5 guidance tells AI agents to use current PHP language features when the project runtime supports PHP 8.5 or newer.

Default requirement:
- The project should require PHP 8.5+ in `composer.json` before this architecture is enabled.
- If the project does not require PHP 8.5+, do not enable this architecture and do not use PHP 8.5-only syntax.

Rules:
- Apply this guidance to new code and directly changed code. Do not perform broad style refactors without an explicit task.
- Write modern PHP 8.5+ style. Do not generate old-compatible PHP "just in case" when the project requires PHP 8.5+.
- Prefer clarity over brevity. New syntax is required when it improves correctness or readability, not as decoration.
- New application PHP files should use `declare(strict_types=1)`. Config and migrations should follow the local project convention.
- Use explicit parameter and return types, including `void`, `never`, `self`, `static`, nullable, union, and intersection types where they express the contract.
- Prefer `final` for new application classes unless framework extension, inheritance, or project testing conventions require otherwise.
- Prefer `final readonly class` for immutable Data Objects and Value Objects.
- Use constructor property promotion for dependency injection, Data Objects, and Value Objects when it reduces boilerplate without hiding important initialization.
- Use `private` promoted dependencies in Actions/Services and `public readonly` promoted properties only for data carriers.
- Prefer `match` for value mapping and branching. Use `switch` only when it is clearly more readable for imperative flow.
- Use named arguments for unclear calls, especially multiple booleans, strings, integers, or optional parameters.
- Use PHP enums for finite sets when the Enums architecture is enabled.
- Use `#[\Override]` for methods/properties that override or implement a parent contract when supported by the runtime.
- Use `#[\NoDiscard]` when ignoring a return value would likely be a bug.
- Use clone-with for immutable `with*()` methods when it preserves validation and improves clarity.
- Use the pipe operator `|>` only for readable pure data transformations. Do not replace Laravel query builder, collection, or pipeline idioms with it.
- Use the PHP URI extension for real URL/URI parsing and normalization instead of ad hoc string parsing.
- Use property hooks and asymmetric visibility only for simple, side-effect-free cases where they are clearer than a method or readonly property.
- Avoid redundant PHPDoc that repeats native types. Keep PHPDoc for generics, array shapes, domain constraints, and meaningful `@throws` contracts.
- Use `mixed` only at true external/framework boundaries and map it into typed DTOs, Value Objects, or enums quickly.
- Prefer immutable dates (`DateTimeImmutable` or `CarbonImmutable`) for DTOs, Value Objects, and domain boundaries.
- Do not create utility classes as the default escape hatch. Prefer Value Objects, enums, Actions, Query Objects, or injectable services.

Good example:

```php
final readonly class PriceRange
{
    public function __construct(
        public Money $minimum,
        public Money $maximum,
    ) {
        if ($minimum->greaterThan($maximum)) {
            throw new InvalidArgumentException('Minimum price cannot exceed maximum price.');
        }
    }
}
```

Bad example:

```php
class PriceRange
{
    public $minimum;
    public $maximum;

    public function __construct($minimum, $maximum)
    {
        $this->minimum = $minimum;
        $this->maximum = $maximum;
    }
}
```

Good Action dependency example:

```php
final class CreateInvoiceAction
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private Clock $clock,
    ) {
    }

    public function execute(CreateInvoiceData $data): Invoice
    {
        // ...
    }
}
```
