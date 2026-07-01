Purpose:
Custom Eloquent Builders provide reusable model-specific query vocabulary.

Default placement:
- `app/Models/Builders`
- Follow existing project structure if it is more specific.

Rules:
- Use `final class`.
- Extend `Illuminate\Database\Eloquent\Builder`.
- Keep methods small, composable, and model-specific.
- Filter and scope-like methods should return `self`.
- Terminal methods may return results, but use them carefully.
- Do not include request, response, endpoint orchestration, authorization, or mutations.
- Query Objects may compose builder methods for endpoint-specific reads.

Good example:

```php
final class InvoiceBuilder extends Builder
{
    public function overdue(): self
    {
        return $this->where('due_at', '<', now())
            ->whereNull('paid_at');
    }

    public function forCustomer(Customer $customer): self
    {
        return $this->where('customer_id', $customer->id);
    }
}
```

Bad example:

```php
final class InvoiceBuilder extends Builder
{
    public function createAndNotify(array $data): Invoice
    {
        // Mutating workflow does not belong in a builder.
    }
}
```
