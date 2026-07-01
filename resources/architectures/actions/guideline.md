Purpose:
Actions represent named write/application use cases.

Default placement:
- `app/Actions`
- Follow existing project structure if it is more specific.

Rules:
- Use `final class`.
- Do not add an `Action` suffix. The namespace already explains the role.
- Expose one public entry method: `handle(...)`.
- Use the constructor only for dependencies.
- Actions may use Eloquent directly.
- Do not create repositories only to hide Eloquent.
- Put transaction boundaries in the Action when the use case needs atomicity.
- Keep framework-specific request and response concerns outside the Action.
- Action-to-Action calls are exceptional and should only represent explicit orchestration of multiple full use cases.

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
