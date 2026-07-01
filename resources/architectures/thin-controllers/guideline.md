Purpose:
Controllers are HTTP adapters. They translate routes and requests into application calls, then translate results into HTTP responses.

Default placement:
- `app/Http/Controllers`
- Follow existing project structure if it is more specific.

Rules:
- A controller method should call at most one Action by default.
- Use typed Form Requests when the Form Requests architecture is enabled.
- Pass `$request->data()` to Actions or Query Objects when Data Objects are enabled.
- Keep response decisions in the controller: resource, redirect, status code, or JSON wrapper.
- Do not put business rules, transactions, persistence orchestration, external API workflows, or domain loops in controllers.
- If a controller needs several write operations, create one larger Action that names the workflow.
- Simple read-only `index` or `show` endpoints may use Eloquent queries and API Resources directly when no read use case logic exists.

Good example:

```php
final class InvoiceController
{
    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): InvoiceResource
    {
        $invoice = $createInvoice->handle($request->data());

        return InvoiceResource::make($invoice);
    }
}
```

Bad example:

```php
final class InvoiceController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['amount' => ['required', 'integer']]);

        return DB::transaction(function () use ($validated): JsonResponse {
            $invoice = Invoice::create($validated);
            Mail::to($invoice->customer)->send(new InvoiceCreatedMail($invoice));

            return response()->json(['id' => $invoice->id], 201);
        });
    }
}
```
