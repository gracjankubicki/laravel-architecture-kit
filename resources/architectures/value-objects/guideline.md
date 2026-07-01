Purpose:
Value Objects are immutable domain values with invariants.

Default placement:
- `app/ValueObjects`
- `app/Domain/.../ValueObjects` in modular projects
- Follow existing project structure if it is more specific.
- Keep Value Object folders type-pure. Do not place Actions, Data Objects, Enums, Resources, Queries, or Exceptions in Value Object folders.

Rules:
- Use `final readonly class`.
- Do not use `Value`, `ValueObject`, or `Vo` suffixes.
- Do not add setters.
- Validate invariants in the constructor or named constructors.
- Throw `InvalidArgumentException` or a project domain exception when invalid.
- Methods must return new objects instead of mutating current state.
- Do not extend Eloquent Model.
- Persist Value Objects through casts, accessors/mutators, or explicit mapping.
- Do not hide Value Objects inside Action or Data Object folders.

Good example:

```php
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }
}
```

Bad example:

```php
final class MoneyValueObject
{
    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }
}
```
