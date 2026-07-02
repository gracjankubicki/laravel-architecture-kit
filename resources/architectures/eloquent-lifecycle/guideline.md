Purpose:
Eloquent Lifecycle defines where model lifecycle behavior belongs: models, mutators, casts, observers, synchronous pre-save handlers, and after-save events/listeners.

Default placement:
- Observers: `app/Observers`
- Lifecycle handlers: `app/Lifecycle/<Domain>`
- Domain-first projects may use `<Domain>/Observers` and `<Domain>/Lifecycle`
- Keep `app/Lifecycle/**` type-pure: lifecycle handlers only.

Decision ladder:
1. Ask first: must this behavior run on every save from every origin: HTTP, imports, seeders, tests, and CLI?
2. If no, it is flow-specific behavior. Dispatch a named event from the owning Action or use-case boundary, not from Eloquent lifecycle.
3. If it is deterministic single-attribute transformation, use an Eloquent mutator, inbound cast, or custom cast.
4. If it maps a value object across one or more columns, use a custom cast or value object mapping.
5. If it validates HTTP/API input, use FormRequest, validator, Data Object, or command input mapping.
6. If it must enforce a persistence invariant before save, use one synchronous lifecycle handler.
7. If it reacts after save, use a named event and separate listeners.

Rules:
- Models describe persistence shape: relationships, casts, accessors, mutators, scopes, factories, and ORM configuration.
- Do not place business workflows, side effects, integrations, HTTP concerns, or multi-step orchestration inside models.
- Before-save observers (`creating`, `saving`, `updating`, `deleting`, `restoring`) are lifecycle adapters only.
- A before-save observer may set one unconditional technical default on the current model, for example `$model->uuid ??= Str::uuid();`.
- Otherwise a before-save observer delegates to exactly one named synchronous lifecycle handler.
- Before-save observers must not contain business conditions, loops, queries, facades, `app()`, job dispatching, events, notifications, mail, HTTP calls, or writes to other models.
- A lifecycle handler may coordinate several pre-save rules, but those rules may only mutate the current model or block the save by throwing a named exception.
- Cross-model writes and side effects belong outside before-save lifecycle handling.
- Use `isDirty()` and `getDirty()` only before persistence.
- Use `wasChanged()` and `getChanges()` only after persistence.
- After-save reactions (`created`, `saved`, `updated`, `deleted`, `restored`) should be modeled as named events and separate listeners/handlers.
- Prefer `$dispatchesEvents` on the model when the event does not need a custom change snapshot.
- If a change snapshot is needed, an after-save observer may contain exactly one named event dispatch.
- Independent after-save reactions live in separate listeners with their own explicit conditions.
- External integrations, notifications, mail, webhooks, expensive recalculations, document processing, AI calls, and other side effects should run in queued listeners or jobs after commit.
- Queued after-save reactions must not rely on live Eloquent dirty state. Pass model identity and an immutable `getChanges()` / `getOriginal()` snapshot.
- Do not rely on observers for critical behavior when models can be changed through mass update or mass delete. Eloquent model events are not fired for mass operations.
- Register observers with `#[ObservedBy(...)]` on the model instead of hidden `Model::observe(...)` provider registration.
- Frequent `saveQuietly()`, `updateQuietly()`, `deleteQuietly()`, or `withoutEvents()` calls mean observer behavior likely belongs in an explicit Action or named event.
- Do not put lifecycle closures such as `static::updating(...)` in concrete models. Use an observer + handler or `$dispatchesEvents`.
- Reusable cross-cutting lifecycle behavior may live in trait boot hooks, but the closure body follows the same adapter rules as observer methods.
- `deleting` and `restoring` handlers are gatekeepers only: verify invariants and throw named exceptions. Cascades belong to explicit Actions or database constraints.

Handler shape:
- Use `final class`.
- Use an imperative class name without a suffix, for example `NormalizeInvoiceNumber`.
- Expose one public method: `handle(Model $model): void`.
- Use the constructor only for dependencies.
- Do not put Data Objects, Result objects, Enums, Exceptions, Resources, Requests, or Eloquent Models under `app/Lifecycle/**`.

Reguły PL:
- Lifecycle Eloquent stosuj tylko dla zachowania, które musi zajść przy każdym zapisie z każdego źródła.
- Zachowanie specyficzne dla przepływu dispatchuj jawnie z Action albo warstwy use case, nie z observera.
- Transformacje pojedynczego atrybutu należą do mutatorów albo castów.
- Value objecty mapuj custom castem albo mappingiem value objectu, nie observerem.
- Walidacja wejścia HTTP/API należy do FormRequest, validatora, Data Object albo command input.
- Observer `*ing` jest adapterem: może ustawić bezwarunkowy techniczny default bieżącego modelu albo delegować do jednego lifecycle handlera.
- Handler `*ing` może zmieniać bieżący model albo zablokować zapis nazwanym wyjątkiem. Nie może robić side effectów ani zapisów innych modeli.
- Reakcje `*ed` modeluj jako jeden nazwany event i osobne listenery.
- Side effecty po zapisie uruchamiaj po commicie, zwykle asynchronicznie.
- Event po zapisie powinien nieść ID modelu i snapshot zmian, jeśli listener potrzebuje informacji o zmianach.
- Observerów nie rejestruj ukrycie w providerze, tylko przez `#[ObservedBy(...)]` na modelu.
