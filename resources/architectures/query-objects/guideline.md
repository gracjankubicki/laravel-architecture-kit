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
- Move repeated read helpers and non-trivial private controller queries into Query Objects.
- If several controllers/resources need the same filtered read model, create one named Query Object instead of copying a private method.
- Do not introduce CQRS as a separate architecture. Use Query Objects for reusable or non-trivial reads and Actions for writes.
- If Ports And Adapters are enabled, add a read Port only for real external, provider, legacy, package, or non-Eloquent data boundaries.
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
