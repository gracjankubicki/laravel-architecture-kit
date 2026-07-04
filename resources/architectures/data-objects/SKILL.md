---
name: architecture-kit-data-objects
description: Use immutable Data Objects for typed Laravel boundary payloads.
---

# Data Objects

Use this skill when typed payloads should cross application boundaries.

## Workflow

1. Name the payload after the use case or boundary and use the `Data` suffix.
2. Use `final readonly class`.
3. Add typed promoted constructor properties.
4. Add boundary factories only when useful.
5. Keep behavior limited to mapping or simple structural helpers.

## Rules

- No setters.
- No Eloquent inheritance.
- No business workflow.
- Prefer Value Objects for domain values when enabled.
- Data folders MUST contain Data Objects, DTOs, and Result objects only.
- Do not put Data Objects or Result objects under `app/Actions/**` or `app/Queries/**`.
- Result objects returned by Actions or Query Objects belong in the Data folder unless the project already has a stricter convention.
- If Ports And Adapters are enabled, Port boundaries should prefer immutable Data/Result objects over raw arrays.
- Data Objects used at Port boundaries must be project-owned and provider-neutral.

## Folder Purity

Pattern-first:

```text
app/Data/Documents/DownloadOriginalDocumentResult.php
app/Data/Documents/StartDocumentPseudonymizationData.php
```

Domain-first:

```text
app/Documents/Data/DownloadOriginalDocumentResult.php
app/Documents/Data/StartDocumentPseudonymizationData.php
```
