## Ports And Laravel Abstractions

- Use Laravel's native abstractions and concrete dependency injection by default.
- Before creating a custom Port, check Cache, Queue, Mail, Storage, Notifications, Events, Bus, HTTP client, Config, Logger, package fakes, and existing container bindings.
- Do not wrap Laravel abstractions unless the application needs a named domain capability above them.
- Ports And Adapters should focus mainly on outbound provider, infrastructure, package, legacy, runtime, or testability boundaries.
