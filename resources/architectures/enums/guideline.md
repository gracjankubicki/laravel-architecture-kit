Purpose:
Enums represent closed sets of states, types, categories, and choices with explicit type safety.

Default placement:
- Place enums near the domain or model that owns the concept, for example `App\Invoices\Enums\InvoiceStatus`.
- Use `App\Enums` only for truly shared cross-domain enums or simple applications without domain folders.
- Follow existing project structure if it is more specific.
- Keep Enum folders type-pure. Do not place Actions, Data Objects, Exceptions, Resources, Queries, or Value Objects in Enum folders.

Rules:
- Use PHP enums for finite sets instead of string constants or magic strings.
- Name enum cases with `PascalCase`.
- Use `snake_case` backed values for new database/API/config values.
- Use backed enums for values persisted in the database, exchanged through APIs, or stored in queues.
- Use unit enums only when no external value is needed.
- Treat backed enum values as API/database/queue contracts. Do not rename values without a migration and rollout decision.
- Keep enum methods small and related to the enum's meaning.
- Good enum methods include `label()`, `options()`, `isFinal()`, `transitionTargets()`, or small stable `fromLegacyValue()` mappings.
- Do not put workflow, persistence, service calls, or authorization logic in enums.
- Use Laravel enum casts on Eloquent models when enum values are stored in columns.
- Use `string`/`varchar` columns for enum values by default. Native database enums or check constraints are only appropriate when the project already uses that convention.
- Use `Rule::enum(MyEnum::class)` in FormRequests.
- Human-facing API Resources MUST expose enums as explicit `value` + `label` objects.
- Machine-facing APIs, queues, events, and webhooks should serialize stable enum `value` strings.
- `label()` should use Laravel translations for API/UI values.
- Optional `options()` helpers may expose `value` + `label` lists for form choices and API metadata.
- Nullable enum columns should use `null` only for a true missing/unknown value. Real domain states must be explicit cases.
- Public enum methods that define API/UI/domain contracts should have focused tests.
- Do not hide Enums inside Action or Data Object folders.

Good example:

```php
use Illuminate\Support\Facades\Lang;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';

    public function label(): string
    {
        return Lang::get("invoices.status.{$this->value}");
    }

    public function isFinal(): bool
    {
        return $this === self::Paid;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            self::cases(),
        );
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

Resource example:

```php
'status' => [
    'value' => $this->status->value,
    'label' => $this->status->label(),
],
```
