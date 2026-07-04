# Architecture Pattern Seeds

## Status

Seed / not approved for implementation.

This file captures candidate Architecture Kit patterns discovered during real refactor planning work. These are not enabled architectures yet. Do not add enum cases, generated resources, guard rules, or install defaults from this file without a separate accepted implementation plan.

## Why This Exists

Some Laravel refactors need architectural guidance that is broader than one existing Architecture Kit pattern but still should not become ad hoc project-specific advice.

The motivating case was a legacy document processing flow that needed a hard separation between:

```text
OCR -> document type detection -> data extraction
```

That discussion surfaced several reusable architecture seeds:

- staged workflow boundaries,
- ports and adapters,
- strategy/resolver based dispatch,
- capability catalogs,
- typed intermediate results.

The goal is to keep these ideas available in the package repo without prematurely turning them into generated rules.

## Seed 1. Staged Workflow Boundaries

### Problem

Legacy Laravel workflows often combine several responsibilities in one Job, Service, pipeline callable, or driver:

- reading input,
- detecting the domain type,
- extracting data,
- calculating derived values,
- persisting results,
- dispatching callbacks.

This makes the workflow hard to test because each step requires the previous step and often a real file, external service, or database state.

### Candidate Architecture

Add a future Architecture Kit pattern for staged workflows.

The pattern should require explicit intermediate boundaries:

```text
Input -> Step A Result -> Step B Result -> Step C Result -> Output
```

Example for document processing:

```text
Document
  -> OcrResultData
  -> DetectedDocumentTypeData
  -> ExtractedDocumentData
  -> Legacy response/storage shape
```

### Rules To Consider

- Each stage owns one responsibility.
- A stage result is a typed Data object, not an untyped array.
- Later stages may consume earlier stage results, but must not redo earlier work.
- Detection stages must not extract data.
- Extraction stages must not decide the type being extracted.
- Persistence/callback formatting stays at the adapter boundary.
- Existing Jobs or pipelines may remain as orchestration until a later cleanup step.

### Likely Generated Resources

```text
resources/architectures/staged-workflows/guideline.md
resources/architectures/staged-workflows/SKILL.md
```

### Guard Ideas

- Warn when a pipeline callable or Job both detects a type and maps fields.
- Warn when workflow stage output is stored as a raw array while Data Objects are enabled.
- Warn when a stage resolves collaborators through `app()` instead of explicit dependencies.

### Do Not

- Do not force every simple workflow into staged architecture.
- Do not create a workflow engine.
- Do not require new interfaces for every stage.
- Do not replace existing Actions or Services; this pattern composes with them.

## Seed 2. Ports And Adapters

### Problem

Some application code depends directly on concrete infrastructure:

- OCR providers,
- AI providers,
- payment clients,
- external APIs,
- file parsers,
- legacy SDKs.

That makes the application flow hard to test and hard to switch when a second implementation appears.

### Candidate Architecture

Add a future Architecture Kit pattern for explicit ports and adapters.

The port is the application-owned contract. The adapter is the infrastructure implementation.

Example:

```text
Port:
  DocumentTypeDetector

Adapters:
  RegexDocumentTypeDetector
  LegacyAiDocumentTypeDetector
  LaravelAiDocumentTypeDetector
```

### Rules To Consider

- Add a port only for a real boundary:
  - multiple real implementations,
  - swappable provider,
  - external system,
  - package/public boundary,
  - concrete testability need.
- Do not add interfaces by default for every Service or Action.
- Ports use typed Data objects and Value Objects at their boundary.
- Adapters map provider-specific payloads into project-owned results.
- Provider exceptions should be mapped before crossing the port boundary.

### Likely Generated Resources

```text
resources/architectures/ports-and-adapters/guideline.md
resources/architectures/ports-and-adapters/SKILL.md
```

### Guard Ideas

- Warn when controllers, resources, or models instantiate integration adapters directly.
- Warn when application code consumes raw provider arrays outside the adapter.
- Warn when a new interface has only one implementation and no external/package boundary.

### Do Not

- Do not promote repository interfaces by default.
- Do not add one-interface-per-class rules.
- Do not hide simple local collaborators behind speculative contracts.

## Seed 3. Strategy And Resolver Dispatch

### Problem

As supported document types, providers, or workflows grow, code often accumulates large `match` or `if` chains across several classes. The same decision may exist in:

- seeders,
- driver registries,
- field mappers,
- result resolvers,
- callback builders.

Adding one new type then requires remembering several unrelated files.

### Candidate Architecture

Add a future Architecture Kit pattern for strategy/resolver dispatch.

The resolver maps a stable key to a strategy:

```text
document type -> field extractor
provider -> adapter
event type -> handler
payment method -> processor
```

### Rules To Consider

- Use a resolver when the mapping is a real domain or integration dispatch point.
- Keep strategy classes cohesive and typed.
- Keep resolver outputs explicit; do not return ambiguous `null` without a named result.
- Resolver configuration must be testable.
- If Enums are enabled, dispatch keys should usually be enums.

### Likely Generated Resources

```text
resources/architectures/strategy-resolvers/guideline.md
resources/architectures/strategy-resolvers/SKILL.md
```

### Guard Ideas

- Warn when a resolver returns untyped callables or arrays.
- Warn when a strategy class is placed inside the wrong architecture folder.
- Warn when a resolver depends on HTTP Request objects.

### Do Not

- Do not replace a small clear `match` with a resolver.
- Do not create plugin systems unless the project truly needs runtime extensibility.
- Do not make strategies responsible for persistence unless that is their named use case.

## Seed 4. Capability Catalogs

### Problem

Some domains need to know what a type supports before choosing a workflow:

- can it be auto-detected?
- can fields be extracted?
- does it require a dedicated OCR driver?
- can it be calculated after extraction?
- does it map to a CRM or external type?
- what confidence threshold applies?

When this knowledge is spread across seeders, match expressions, and driver classes, adding a new type is risky.

### Candidate Architecture

Add a future Architecture Kit pattern for typed capability catalogs.

The catalog is a project-owned read model for stable domain capabilities. It should not become a general settings table.

Example:

```text
DocumentTypeDefinitionData
  - type
  - crmAttachmentTypeId
  - capabilities
  - detector
  - extractor
  - minimumAccuracy
  - datasetReference
```

### Rules To Consider

- Put runtime-owned capabilities in code/config when they need review and tests.
- Keep CRM/external mapping in DB when it is already business data.
- Catalog entries should use enums and Data objects, not free-form arrays.
- Catalog tests should validate that required mappings exist.
- Catalogs may describe planned capabilities separately from currently executable capabilities.

### Likely Generated Resources

```text
resources/architectures/capability-catalogs/guideline.md
resources/architectures/capability-catalogs/SKILL.md
```

### Guard Ideas

- Warn when a capability-like concept is duplicated in several match expressions.
- Warn when catalog Data objects are placed in Services or Actions folders.
- Warn when raw string capabilities are used while Enums are enabled.

### Do Not

- Do not turn every enum into a catalog.
- Do not store prompts, provider-specific rules, or test dataset paths in business tables by default.
- Do not make the catalog execute behavior; it should describe capabilities and dispatch metadata.

## Seed 5. Typed Processing Results

### Problem

Legacy flows often pass associative arrays through several steps. The shape becomes implicit and later code must know which keys exist after which stage.

### Candidate Architecture

Add a future Architecture Kit pattern or cross-rule for typed intermediate results.

This may not need a new architecture. It may be an extension of Data Objects plus staged workflows.

Example:

```text
OcrResultData
DetectedDocumentTypeData
ExtractedDocumentData
RejectedDetectionCandidateData
```

### Rules To Consider

- Intermediate results are immutable Data objects.
- Expected alternative outcomes use Result/Data objects, not `false`, `null`, or untyped arrays.
- Rejected candidates should preserve diagnostic metadata without being treated as accepted results.
- External provider payloads are normalized before entering the application workflow.

### Likely Generated Resources

No separate architecture by default. Prefer extending:

```text
resources/architectures/data-objects/guideline.md
resources/architectures/data-objects/SKILL.md
```

### Guard Ideas

- This should likely be documentation-first, not guard-first.

### Do Not

- Do not create Data objects for trivial local variables.
- Do not expose internal processing Data objects directly as API Resources.

## Suggested Implementation Order

1. Expand existing Data Objects guidance with typed intermediate result examples.
2. Add a `Staged Workflows` architecture seed as the first real candidate.
3. Only then consider `Ports And Adapters`, because it can encourage too many interfaces if introduced too early.
4. Add `Strategy Resolvers` only after there is enough repeated dispatch code in consuming projects.
5. Add `Capability Catalogs` only when a real domain has stable capabilities that are duplicated across runtime code.

## Open Questions

1. Should these become separate Architecture enum cases or remain advanced sections under existing Actions/Services/Data Objects guidance?
2. Should `Ports And Adapters` include a guard against one-implementation interfaces?
3. Should `Staged Workflows` prefer Actions, Services, or either depending on enabled architectures?
4. Should capability catalogs be code/config only, or allow DB-backed catalogs with typed read models?
5. Should these seeds produce generated skills, or only long-form guidelines?

## Out Of Scope

- No package code changes.
- No new enum cases.
- No generated resource changes.
- No guard/audit rules.
- No installer changes.
- No tests required until one seed is accepted for implementation.
