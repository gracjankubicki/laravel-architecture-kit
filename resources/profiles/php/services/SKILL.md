---
name: architecture-kit-services
description: Apply Services to this Composer package.
---

Services implement a coherent responsibility behind an explicit interface.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Use final classes and the Service suffix.
- Name the responsibility precisely. Avoid generic Manager, Processor or Helper classes.
- Inject collaborators through the constructor or method parameters.
- Expose only methods belonging to the named responsibility.
- Use enabled Actions or Query Objects when the operation is a complete use case.
- Keep transport concerns outside the Service and return typed results.

Choose tests for changed behaviour and verify the enabled Architecture Kit rules before finishing.
