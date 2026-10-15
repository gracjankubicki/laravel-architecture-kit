---
name: architecture-kit-actions
description: Apply Actions to this Composer package.
---

Actions implement named write use cases.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Use a final class without an Action suffix.
- Expose one public entry method, handle(...). Keep the constructor for explicit dependencies.
- Accept typed arguments, Data Objects or Value Objects. Keep transport parsing and response formatting in adapters.
- Return domain results, Data Objects, enums or scalars.
- Put transaction boundaries around operations that require atomicity, using the existing persistence integration.
- Use Ports for real provider or infrastructure boundaries when Ports And Adapters are enabled.
- Keep supporting Data Objects, enums and exceptions in their own matching locations.
- Use Action-to-Action calls only for explicit orchestration of complete use cases.

Choose tests for changed behaviour and verify the enabled Architecture Kit rules before finishing.
