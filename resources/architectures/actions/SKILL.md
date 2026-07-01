---
name: architecture-kit-actions
description: Use Actions for named Laravel application write use cases.
---

# Actions

Use this skill when implementing or refactoring write/application use cases.

## Workflow

1. Name the business operation with an imperative class name.
2. Create a `final` class under the project's Action namespace.
3. Add one public `handle(...)` method.
4. Accept a Data Object or explicit typed arguments.
5. Keep request and response concerns in the adapter.
6. Put transaction boundaries in the Action when atomicity is required.
7. Test the Action by calling `handle(...)` directly.

## Rules

- Do not add an `Action` suffix.
- Do not create repositories merely to hide Eloquent.
- Do not split one use case into artificial micro-Actions.
- Orchestrator Actions are allowed only when the class names a larger workflow.
- Do not inject or accept `Request`, `FormRequest`, `JsonResponse`, `Response`, `RedirectResponse`, or `StreamedResponse`.
- Do not return HTTP responses from Actions. Return a model, Data/Result object, enum, scalar, or domain/application result.
- Map uploaded/request data in the controller/FormRequest layer before calling the Action.
- Action folders MUST contain Actions only.
- Do not put Data Objects, Result objects, Enums, Exceptions, API Resources, or Value Objects under `app/Actions/**`.
- Put supporting classes in the matching architecture folder, for example `app/Data/Documents`, `app/Enums/Documents`, or `app/Exceptions/Documents`.

## Folder Purity

Use pattern-first placement when the project uses pattern-first folders:

```text
app/
  Actions/Documents/ApproveDocument.php
  Data/Documents/ApproveDocumentData.php
  Enums/Documents/DocumentStatus.php
  Exceptions/Documents/DocumentApprovalFailed.php
```

Use domain-first placement when the project already does that:

```text
app/
  Documents/Actions/ApproveDocument.php
  Documents/Data/ApproveDocumentData.php
  Documents/Enums/DocumentStatus.php
  Documents/Exceptions/DocumentApprovalFailed.php
```

Do not mix them like this:

```text
app/
  Actions/Documents/ApproveDocument.php
  Actions/Documents/ApproveDocumentData.php
  Actions/Documents/DocumentStatus.php
  Actions/Documents/DocumentApprovalFailed.php
```

## Adapter Boundary

Bad:

```php
final class ReceiveChunkedDocumentUpload
{
    public function handle(Request $request): JsonResponse
    {
        // HTTP input and HTTP output leaked into the Action.
    }
}
```

Good:

```php
final class ReceiveChunkedDocumentUpload
{
    public function handle(StoreChunkedDocumentData $data): ChunkedDocumentUploadResult
    {
        // Application workflow only.
    }
}
```

The controller owns `Request` access and converts the result into JSON.
