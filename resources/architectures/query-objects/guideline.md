Purpose:
Query Objects represent named read use cases.

Default placement:
- `app/Queries`
- Follow existing project structure if it is more specific.
- Keep Query folders type-pure. Do not place Data Objects, Result objects, Builders, Resources, Actions, or Enums under `app/Queries/**`.

Rules:
- Use `final class`.
- Do not add a `Query` suffix. The namespace explains the role.
- Expose one public entry method: `handle(...)`.
- Do not mutate data.
- Do not accept `Request` or `FormRequest`.
- Accept a Data Object, Value Objects, or explicit typed arguments for filters.
- A Query Object may compose Custom Eloquent Builder methods.
- A Query Object may decide eager loading, sorting, filtering, and pagination for one read use case.
- Put filter/result payloads in Data Object folders and reusable model query vocabulary in Builder folders.

Good example:

```php
final class SearchInvoices
{
    public function handle(InvoiceSearchData $data): LengthAwarePaginator
    {
        return Invoice::query()
            ->with('customer')
            ->when($data->status, fn ($query) => $query->where('status', $data->status))
            ->latest()
            ->paginate($data->perPage);
    }
}
```

Bad example:

```php
final class SearchInvoicesQuery
{
    public function handle(Request $request): LengthAwarePaginator
    {
        return Invoice::query()->paginate($request->integer('per_page'));
    }
}
```
