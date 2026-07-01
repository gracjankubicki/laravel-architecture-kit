Purpose:
Modern PHP 8.5 guidance tells AI agents to use current PHP language features when the project runtime supports PHP 8.5 or newer.

Default requirement:
- The project should require PHP 8.5+ in `composer.json` before this architecture is enabled.
- If the project does not require PHP 8.5+, do not use PHP 8.5-only syntax.

Rules:
- Prefer strict types, typed properties, constructor property promotion, named arguments, `match`, enums, and readonly classes where they improve clarity.
- Prefer `final readonly class` for immutable Data Objects and Value Objects.
- Use PHP enums for finite sets when the Enums architecture is enabled.
- Use PHP 8.5 features when they make code clearer and the project runtime supports them.
- Use the pipe operator `|>` for readable data transformation pipelines, not for ordinary imperative workflows.
- Use clone-with for immutable object changes when it is clearer than constructing a new object manually.
- Use `#[\NoDiscard]` on methods or functions whose return value must not be ignored.
- Use the URI extension for URL parsing/normalization when available instead of ad hoc string parsing.
- Do not force new syntax when plain PHP is clearer.

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
