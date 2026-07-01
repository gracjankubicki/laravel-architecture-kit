Purpose:
Actions represent named write/application use cases.

Default placement:
- `app/Actions`
- Follow existing project structure if it is more specific.
- Keep Action folders type-pure. Do not place Data Objects, Result objects, Enums, Exceptions, Resources, or Value Objects under `app/Actions/**`.

Rules:
- Use `final class`.
- Do not add an `Action` suffix. The namespace already explains the role.
- Expose one public entry method: `handle(...)`.
- Use the constructor only for dependencies.
- Actions may use Eloquent directly.
- Do not create repositories only to hide Eloquent.
- Put transaction boundaries in the Action when the use case needs atomicity.
- Keep framework-specific request and response concerns outside the Action.
- Do not accept `Request`, `FormRequest`, `JsonResponse`, `Response`, `RedirectResponse`, or `StreamedResponse` in Actions.
- Controllers and other adapters must map HTTP input into Data Objects, Value Objects, or explicit typed arguments before calling Actions.
- Actions should return models, Data/Result objects, enums, scalars, or domain/application results. Controllers format HTTP responses.
- Action-to-Action calls are exceptional and should only represent explicit orchestration of multiple full use cases.
- Put supporting classes in their matching architecture folders, for example `app/Data`, `app/Enums`, `app/Exceptions`, or their domain-first equivalents.

Good names:
- `CreateInvoice`
- `ApproveContract`
- `SyncCustomerAddress`

Bad names:
- `CreateInvoiceAction`
- `InvoiceManager`
- `ContractProcessor`

Good example:

```php
final class CreateInvoice
{
    public function handle(CreateInvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($data): Invoice {
            return Invoice::create([
                'customer_id' => $data->customerId,
                'amount' => $data->amount->toInteger(),
            ]);
        });
    }
}
```

Bad example:

```php
final class InvoiceService
{
    public function create(Request $request): JsonResponse
    {
        // HTTP parsing, validation, persistence, mail, and response in one method.
    }
}
```
