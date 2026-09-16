Purpose:
Controllers are HTTP adapters. They translate routes and requests into application calls, then translate results into HTTP responses.

Default placement:
- `app/Http/Controllers`
- Follow existing project structure if it is more specific.

Rules:
- A controller method should call at most one Action by default.
- Use typed Form Requests when the Form Requests architecture is enabled.
- Pass `$request->toData()` to Actions or Query Objects when Data Objects are enabled.
- Keep response decisions in the controller: resource, redirect, status code, or JSON wrapper.
- Do not put business rules, transactions, persistence orchestration, external API workflows, or domain loops in controllers.
- If a controller needs several write operations, create one larger Action that names the workflow.
- When Actions are enabled, do not route write use cases through `App\Services` from controllers; wrap the use case in an Action.
- Avoid service locator calls such as `app(SomeClass::class)` in controllers; use explicit dependencies or move behavior behind an enabled architecture boundary.
- Simple read-only `index` or `show` endpoints may use Eloquent queries and API Resources directly when no read use case logic exists.

Good example:

```php
final class InvoiceController
{
    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): InvoiceResource
    {
        $invoice = $createInvoice->handle($request->toData());

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

### Route-aware read checks

With Thin Controllers and Actions enabled, the audit reads a fresh Laravel route collection and associates each controller method with its HTTP verbs. GET/HEAD may call a cohesive read Service when Services are enabled; when Query Objects are enabled, use a Query Object for reusable read composition. POST/PUT/PATCH/DELETE Service calls retain the Action advisory. Imports and unused injected parameters are not dependency findings. Constructor dependencies are attributed to the methods that call them.

The audit follows reachable project method bodies without executing endpoints. Recognized model, builder, relation and DB mutations, jobs, mail and notifications produce `E_THIN_CONTROLLER_READ_SIDE_EFFECT` on a GET/HEAD endpoint, with a call chain and the operation's file/line. It examines called methods, not unrelated write methods in the same Service. A transaction alone is not a write; its callback is analysed.

`W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE` means the audit could not resolve a route/call/type, raw SQL, or a bounded traversal. It is a warning, and fails `--strict`. Absence of a finding means no effect was detected in the supported static subset, not proof that runtime hooks, model events, macros, magic dispatch, vendor SDKs or every possible branch are harmless. Dynamic SQL is never treated as a proved read. `W_THIN_CONTROLLER_READ_SERVICE` separately advises using enabled Query Objects.

Route discovery boots Laravel in a fresh child PHP process, with a process-local route-cache override. It does not dispatch a request or instantiate controllers, and does not clear the application's route cache. Normal application bootstrap code still runs. This keeps a long-lived MCP server from using routes from its initial boot. The timeout is 15 seconds; boot failure becomes an explicit incomplete-analysis finding when relevant. Programmatic callers without a Laravel bootstrap can pass a fresh `RouteMap::fromRoutes($router->getRoutes())` as the optional `routes` argument to `ApplicationAudit::run()`.

Analysis is limited to 12 method levels, 128 method visits and 20,000 AST nodes per endpoint, 100 KB per source file and 1 MB of retained source per controller, with an additional PHP memory headroom check. A cycle or exceeded budget is incomplete analysis. Source lookup stays in the configured project graph; excluded/out-of-scope dependencies are unresolved. The graph cache format is unchanged. In `--changed`, changed dependencies recheck their controller dependents; edits under routes/bootstrap/config/providers and deleted inputs recheck all controllers. Custom route-registration files outside those locations require a full audit.
