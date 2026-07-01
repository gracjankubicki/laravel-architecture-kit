Purpose:
Enums represent closed sets of states, types, categories, and choices with explicit type safety.

Default placement:
- Near the domain or model that owns the concept.
- `app/Enums` is acceptable for shared cross-domain enums.
- Follow existing project structure if it is more specific.

Rules:
- Use PHP enums for finite sets instead of string constants or magic strings.
- Use backed enums for values persisted in the database, exchanged through APIs, or stored in queues.
- Use unit enums only when no external value is needed.
- Keep enum methods small and related to the enum's meaning.
- Good enum methods include `label()`, `isFinal()`, `color()`, `transitionTargets()`, or `fromLegacyValue()`.
- Do not put workflow, persistence, service calls, or authorization logic in enums.
- Use Laravel enum casts on Eloquent models when enum values are stored in columns.
- API Resources should convert enums into explicit JSON values or shapes.

Good example:

```php
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';

    public function isFinal(): bool
    {
        return $this === self::Paid;
    }
}
```

Bad example:

```php
final class InvoiceStatus
{
    public const DRAFT = 'draft';
    public const ISSUED = 'issued';
    public const PAID = 'paid';
}
```
