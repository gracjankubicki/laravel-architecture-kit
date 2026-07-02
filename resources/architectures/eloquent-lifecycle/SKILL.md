---
name: architecture-kit-eloquent-lifecycle
description: Route Laravel Eloquent lifecycle behavior through models, mutators, casts, observers, lifecycle handlers, and after-save events/listeners.
---

# Eloquent Lifecycle

Use this skill when implementing or refactoring Eloquent models, observers, model events, mutators, casts, pre-save behavior, after-save reactions, or code that uses `saveQuietly()` / `withoutEvents()`.

## First Question

Before using Eloquent lifecycle, ask:

> Must this behavior run on every save from every origin: HTTP, imports, seeders, tests, factories, jobs, and CLI commands?

If no, it is flow-specific behavior. Dispatch a named event from the owning Action or use-case boundary. Do not hide it in `created`, `updated`, or `saved`.

PL:
Zanim użyjesz lifecycle Eloquent, zapytaj: czy to zachowanie ma zajść przy każdym zapisie z każdego źródła? Jeśli nie, to jest zachowanie konkretnego przepływu i powinno wyjść jawnie z Action albo warstwy use case.

## Responsibility Map

| Need | Use | Do not use |
| --- | --- | --- |
| Single-attribute normalization | Mutator, inbound cast, custom cast | Observer |
| Value object stored in columns | Custom cast / value object mapping | Observer sync |
| HTTP/API input validation | FormRequest, validator, Data Object | Observer |
| Persistence invariant before save | Lifecycle handler | Inline observer logic |
| Side effect after save | Event + listener after commit | `*ing` observer |
| Several after-save reactions | One event + many listeners | Broad service with many `if` branches |
| Flow-specific reaction | Action-dispatched event | Eloquent lifecycle |

## Models

Eloquent models describe persistence shape:

- relationships,
- casts,
- accessors,
- mutators,
- scopes,
- factories,
- ORM configuration.

Do not put business workflows, side effects, integrations, HTTP concerns, or multi-step orchestration inside models.

PL:
Model opisuje kształt persistencji, a nie workflow biznesowy.

## Mutators And Casts

Use mutators or casts for deterministic attribute transformations.

Bad:

```php
final class LeadCarObserver
{
    public function saving(LeadCar $leadCar): void
    {
        $leadCar->car_reg = strtoupper(str_replace(' ', '', $leadCar->car_reg));
    }
}
```

Good:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

final class LeadCar extends Model
{
    protected function carReg(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null
                ? null
                : strtoupper(str_replace(' ', '', $value)),
        );
    }
}
```

PL:
Jeśli zmieniasz jedno pole w sposób deterministyczny, użyj mutatora albo castu. Observer nie jest setterem.

## Before-Save Lifecycle

Before-save events are:

- `creating`
- `saving`
- `updating`
- `deleting`
- `restoring`

Observers for these events are adapters only.

Allowed:

- one unconditional technical default on the current model,
- or one delegation to a named synchronous lifecycle handler.

Forbidden:

- business conditions,
- loops,
- queries,
- facades,
- `app()`,
- events,
- jobs,
- notifications,
- mail,
- HTTP calls,
- writes to other models.

Good adapter:

```php
final class InvoiceObserver
{
    public function updating(Invoice $invoice): void
    {
        $this->normalizeInvoiceBeforeUpdate->handle($invoice);
    }
}
```

Good technical default:

```php
final class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        $invoice->uuid ??= Str::uuid()->toString();
    }
}
```

Bad:

```php
final class InvoiceObserver
{
    public function updating(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Approved) {
            $invoice->customer->update(['has_approved_invoice' => true]);
            InvoiceApproved::dispatch($invoice);
        }
    }
}
```

PL:
Observer przed zapisem może przygotować bieżący model albo przekazać go do jednego handlera. Nie może sterować procesem ani robić side effectów.

## Lifecycle Handlers

Lifecycle handlers live in:

- `app/Lifecycle/<Domain>/`
- or `<Domain>/Lifecycle/` in domain-first projects.

Shape:

- `final class`
- imperative name without suffix, for example `NormalizeInvoiceNumber`
- one public `handle(Model $model): void` method
- constructor for dependencies only

Lifecycle handlers may coordinate several pre-save rules. Each rule may only:

- mutate the current model,
- or block the save by throwing a named exception.

Good:

```php
final class NormalizeLeadCarBeforeUpdate
{
    public function __construct(
        private NormalizeRegistrationNumber $normalizeRegistrationNumber,
        private PreventVinChangeWhenLocked $preventVinChangeWhenLocked,
    ) {
    }

    public function handle(LeadCar $leadCar): void
    {
        $this->normalizeRegistrationNumber->handle($leadCar);
        $this->preventVinChangeWhenLocked->handle($leadCar);
    }
}
```

Do not put Data Objects, Result objects, Enums, Exceptions, Resources, Requests, or Eloquent Models under `app/Lifecycle/**`.

PL:
Kilka reguł przed zapisem koordynuje jeden lifecycle handler. Observer nie powinien mieć listy handlerów.

## Dirty Checks

Use before persistence:

- `isDirty()`
- `getDirty()`

Use after persistence:

- `wasChanged()`
- `getChanges()`

Do not mix these mental models in one lifecycle rule.

PL:
`isDirty()` / `getDirty()` są przed zapisem. `wasChanged()` / `getChanges()` są po zapisie.

## After-Save Lifecycle

After-save events are:

- `created`
- `saved`
- `updated`
- `deleted`
- `restored`

Prefer:

1. `$dispatchesEvents` on the model when no custom snapshot is needed.
2. Exactly one named event dispatch in an after-save observer when a snapshot or dirty-field filter is needed.
3. Trait boot hook only for reusable cross-cutting lifecycle behavior.

Good:

```php
final class LeadCarObserver
{
    public function updated(LeadCar $leadCar): void
    {
        LeadCarUpdated::dispatch(LeadCarUpdatedData::fromModel($leadCar));
    }
}
```

Event payload:

```php
final readonly class LeadCarUpdatedData
{
    public function __construct(
        public int $leadCarId,
        public array $changes,
        public array $original,
    ) {
    }

    public static function fromModel(LeadCar $leadCar): self
    {
        return new self(
            leadCarId: $leadCar->getKey(),
            changes: $leadCar->getChanges(),
            original: $leadCar->getOriginal(),
        );
    }
}
```

Each independent reaction lives in its own listener.

PL:
Po zapisie observer może wyemitować jeden nazwany event. Różne reakcje mają osobne listenery.

## Async And After Commit

After-save lifecycle is async-friendly by default.

Queue after commit for:

- external integrations,
- notifications,
- mail,
- webhooks,
- expensive recalculations,
- document processing,
- AI calls,
- other side effects.

Do not put `ShouldHandleEventsAfterCommit` on the whole observer when that observer also has `*ing` methods. It delays all observer methods and breaks the meaning of before-save hooks.

Use after-commit at the dispatch/listener/job level:

- `ShouldQueueAfterCommit`
- `ShouldDispatchAfterCommit`
- `DB::afterCommit(...)`
- `->afterCommit()` where the framework supports it

PL:
Side effecty po zapisie uruchamiaj po commicie. Nie opóźniaj całego observera, jeśli ma metody `*ing`.

## Registration

Register observers with `#[ObservedBy(...)]` on the model.

Good:

```php
#[ObservedBy([InvoiceObserver::class])]
final class Invoice extends Model
{
}
```

Avoid hidden provider registration:

```php
Invoice::observe(InvoiceObserver::class);
```

PL:
Observer ma być widoczny z pliku modelu.

## Quiet Saves

Frequent `saveQuietly()`, `updateQuietly()`, `deleteQuietly()`, or `withoutEvents()` calls are a smell.

They usually mean observers carry behavior callers need to opt out of. Move that behavior to an explicit Action or named event.

PL:
Częste wyciszanie eventów oznacza, że observer prawdopodobnie robi za dużo.

## Mass Operations

Eloquent model events are not fired for mass updates or mass deletes.

Do not rely on observers for critical behavior when the model can be changed through:

- `Model::query()->update(...)`
- `Model::query()->delete()`
- relationship mass updates

Use database constraints or explicit Actions for critical invariants and cascades.

PL:
Mass update/delete nie odpala eventów modelu, więc krytyczne reguły nie mogą zależeć tylko od observera.
