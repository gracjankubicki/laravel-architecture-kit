Purpose:
API Resources define JSON response shape for Laravel APIs.

Default placement:
- `app/Http/Resources`
- Follow existing project structure if it is more specific.
- Keep Resource folders type-pure. Only API Resources and Resource Collections belong under `app/Http/Resources/**`.

Rules:
- Use Laravel `JsonResource` and `ResourceCollection`.
- Format data that has already been loaded.
- Do not query the database from a Resource.
- Do not trigger lazy loading.
- Use `whenLoaded`, `whenCounted`, and `whenAggregated`.
- Do not put business decisions or side effects in Resources.
- Format Value Objects into JSON-friendly shapes.
- Do not place Data Objects, Actions, Query Objects, Enums, or Exceptions in Resource folders.

Good example:

```php
final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => [
                'value' => $this->amount->toDecimal(),
                'currency' => $this->amount->currency,
            ],
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
        ];
    }
}
```

Bad example:

```php
final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'customer' => Customer::query()->find($this->customer_id),
        ];
    }
}
```
