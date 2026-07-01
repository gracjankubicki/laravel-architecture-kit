Purpose:
Data Objects are immutable typed boundary payloads.

Default placement:
- `app/Data`
- Follow existing project structure if it is more specific.

Rules:
- Use `final readonly class`.
- Use the `Data` suffix.
- Prefer promoted readonly constructor properties.
- Do not add setters.
- Keep workflow and business decisions out of Data Objects.
- Use `fromArray`, `toArray`, or `fromRequest` only when they clarify boundary mapping.
- If Value Objects are enabled, Data Objects may contain Value Objects for domain values.

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
