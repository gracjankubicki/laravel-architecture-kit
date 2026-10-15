---
name: architecture-kit-ports-and-adapters
description: Apply Ports And Adapters to this Composer package.
---

Ports describe real boundaries between domain operations and external implementations.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Start with existing project interfaces and maintained Composer packages.
- Add a Port only for a named provider, infrastructure, legacy or testability boundary.
- Keep interfaces near the owning domain and concrete Adapters outside domain logic.
- Use project-owned typed inputs and results in Ports; keep SDK types and exceptions inside Adapters.
- Inject Ports explicitly and compose implementations at the package integration boundary.
- Do not create interfaces or repositories for every class.
- Test domain behaviour with fake Ports and each Adapter against its actual integration boundary.

Choose tests for changed behaviour and verify the enabled Architecture Kit rules before finishing.
