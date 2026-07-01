---
name: architecture-kit-form-requests
description: Use Laravel Form Requests for request validation, authorization, and optional Data Object mapping.
---

# Form Requests

Use this skill when adding or refactoring request validation or authorization.

## Workflow

1. Put endpoint input rules in a typed Form Request.
2. Put request-level authorization in `authorize()`.
3. Delegate complex authorization to policies or gates when needed.
4. If Data Objects are enabled, add a `toData()` method that returns the typed Data Object.
5. Keep Actions and Query Objects independent from HTTP request classes.

## Rules

- `authorize()` must represent the endpoint's access rule.
- `rules()` validates input shape and constraints.
- `toData()` maps validated input, not raw request input.
- Do not define `data()` on a Form Request. Laravel already has a `data(?string $key = null, mixed $default = null)` request method, and overriding it with a Data Object return type breaks method compatibility.
- Form Requests do not perform persistence, transactions, or external side effects.

## Data Object Mapping

When Data Objects are enabled, use `toData()`:

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
