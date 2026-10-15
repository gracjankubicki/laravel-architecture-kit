Query Objects implement named read use cases.

Follow the project placement and namespace shown by Architecture Kit. Existing declared roles take precedence over examples.

- Use a final class with the Query suffix and one public handle(...) entry method.
- Accept explicit typed criteria or immutable Data Objects.
- Keep query construction and fetching inside the read boundary.
- Return a typed read result without transport formatting.
- Keep writes and unrelated orchestration outside Query Objects.
- Inject the existing persistence abstraction instead of introducing a framework dependency.
