---
name: architecture-kit-data-objects
description: Apply Data Objects to this Composer package.
---

Data Objects carry immutable typed data across boundaries.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Use a final readonly class with the Data suffix.
- Prefer promoted typed constructor properties.
- Keep setters, workflows and business decisions out of Data Objects.
- Add fromArray or toArray only when boundary mapping needs it.
- Use Value Objects for domain values when that architecture is enabled.
- Keep provider types out of project-owned Port payloads.

Choose tests for changed behaviour and verify the enabled Architecture Kit rules before finishing.
