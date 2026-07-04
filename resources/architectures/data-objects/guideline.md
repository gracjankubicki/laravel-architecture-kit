Purpose:
Data Objects are immutable typed boundary payloads.

Default placement:
- `app/Data`
- Follow existing project structure if it is more specific.
- Keep Data folders type-pure. Data Objects, DTOs, and Result objects belong here, not under Actions, Queries, Enums, or Resources.

Rules:
- Use `final readonly class`.
- Use the `Data` suffix.
- Prefer promoted readonly constructor properties.
- Do not add setters.
- Keep workflow and business decisions out of Data Objects.
- Use `fromArray`, `toArray`, or `fromRequest` only when they clarify boundary mapping.
- If Value Objects are enabled, Data Objects may contain Value Objects for domain values.
- Result objects for Actions or Query Objects are Data Objects unless the project has a more specific existing convention.
- If Ports And Adapters are enabled, Port boundaries should prefer immutable Data/Result objects over raw arrays.
- Data Objects used at Port boundaries must be project-owned and provider-neutral.

Good example:

```php
final readonly class CreateInvoiceData
{
    public function __construct(
        public int $customerId,
        public Money $amount,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            customerId: (int) $data['customer_id'],
            amount: Money::fromInteger((int) $data['amount']),
        );
    }
}
```

Bad example:

```php
final class CreateInvoicePayload
{
    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }
}
```
