Application behavior:

- Flow-specific behavior SHOULD be orchestrated through Actions when Actions are enabled. See `architecture-kit-actions`.
- Controllers, jobs, listeners, commands, and observers should call Actions instead of owning business decisions.
- Dependencies should be injectable and testable.
