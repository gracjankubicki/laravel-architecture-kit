Purpose:
Form Requests own request validation and request-level authorization.

Default placement:
- `app/Http/Requests`
- Follow existing project structure if it is more specific.

Rules:
- Use `authorize()` for request-level access decisions.
- Do not leave `authorize()` as `true` when the endpoint has meaningful access restrictions.
- Use `rules()` for input validation.
- Keep business workflow out of Form Requests.
- If Data Objects are enabled, expose a `toData()` method that maps validated input into a Data Object.
- Do not add a custom `data()` method to Form Requests because it conflicts with Laravel's request data API.
- Do not pass Form Requests into Actions or Query Objects.

Good example:

```php
final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toData(): CreateInvoiceData
    {
        return CreateInvoiceData::fromArray($this->validated());
    }
}
```

Bad example:

```php
final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
```
