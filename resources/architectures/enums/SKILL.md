---
name: architecture-kit-enums
description: Use PHP enums for closed Laravel domain states, types, categories, and choices.
---

# Enums

Use this skill when replacing magic strings, constants, status fields, type fields, or finite option lists.

Enums are a contract. A backed enum value can be stored in the database, returned by APIs, sent through queues, or consumed by frontend code. Do not rename backed values casually.

## Workflow

1. Confirm the set of values is finite and meaningful in the domain.
2. Use a backed enum when values cross a database, API, queue, or config boundary.
3. Put the enum near the owning domain/model. Use `App\Enums` only for shared/global enums.
4. Add Eloquent casts for model columns that store enum values.
5. Validate request input with `Rule::enum(MyEnum::class)`.
6. Format human-facing API enums as `value` + `label` objects in API Resources.
7. Serialize enum payloads for queues/events/webhooks as stable backed `value` strings.
8. Add focused tests for enum methods that define API, UI, or domain contracts.

## Rules

- Do not use string constants for new finite state/type sets.
- Enum case names MUST be `PascalCase`.
- New backed values MUST be `snake_case`.
- Backed values are breaking contracts. Changing one requires a data/API/queue rollout decision.
- Prefer `string`/`varchar` database columns plus PHP backed enums and Eloquent casts.
- Do not introduce native database enums or check constraints unless the project already uses them.
- Do not put workflows or service calls in enums.
- Do not use enums for open-ended user-defined values.
- Keep enum methods small and value-focused.
- `null` is only for a true missing value. Use an explicit enum case for real domain states.
- Use Laravel translations in `label()` when values are displayed in API/UI.
- Use static `options()` only when the enum is used as form choices or API metadata.
- Use `fromLegacyValue()` only for small, stable legacy mappings. Contextual imports need a mapper or Action.
- Simple transition rules may live on the enum; permissions, events, notifications, and side effects must live outside it.
- Config modes may use enums when options are closed and controlled by code. Parse config with `Enum::from()` and fail fast.
- Enum folders MUST contain Enums only.
- Do not put Enums under `app/Actions/**`, `app/Data/**`, or `app/Queries/**`.

## Placement

Prefer domain placement:

```php
namespace App\Invoices\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
}
```

Use `App\Enums` only when the enum is truly shared across domains.

## Database and Model Casts

Use a string column by default:

```php
Schema::table('invoices', function (Blueprint $table): void {
    $table->string('status')->default(InvoiceStatus::Draft->value);
});
```

Cast the column on the model:

```php
use App\Invoices\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
        ];
    }
}
```

Do not scatter `InvoiceStatus::from($invoice->status)` through services and resources when Eloquent can return the enum directly.

## FormRequest Validation

Use Laravel enum validation:

```php
use App\Invoices\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(InvoiceStatus::class)],
        ];
    }
}
```

Use `Rule::in()` only for legacy aliases or values that must be mapped before becoming an enum.

## Labels and Options

For UI/API labels, prefer translations:

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

Only add `options()` when callers need a choice list.

## API Resources

Human-facing API Resources MUST return `value` + `label`:

```php
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
        ];
    }
}
```

Machine-facing APIs, webhooks, events, and queues should use the stable value only:

```php
[
    'invoice_id' => $invoice->id,
    'status' => $invoice->status->value,
]
```

Rehydrate at the boundary:

```php
$status = InvoiceStatus::from($payload['status']);
```

## Domain Behavior

Small value behavior is allowed:

```php
public function isFinal(): bool
{
    return $this === self::Paid;
}

/**
 * @return list<self>
 */
public function transitionTargets(): array
{
    return match ($this) {
        self::Draft => [self::Issued],
        self::Issued => [self::Paid],
        self::Paid => [],
    };
}
```

Do not put authorization, persistence, notifications, events, or external calls in enum methods.

## Tests

Test public enum behavior that becomes an API/UI/domain contract:

```php
it('exposes invoice status options', function (): void {
    expect(InvoiceStatus::options())->toContain([
        'value' => 'paid',
        'label' => __('invoices.status.paid'),
    ]);
});
```

Plain enums with no behavior do not need dedicated tests.
