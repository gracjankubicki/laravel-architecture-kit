---
name: architecture-kit-modern-php-85
description: Use modern PHP 8.5 language features in Laravel code when the project runtime supports PHP 8.5+.
---

# Modern PHP 8.5

Use this skill when implementing or refactoring code in a project that has enabled PHP 8.5 guidance.

## Workflow

1. Check that the project requires PHP 8.5+ before using PHP 8.5-only syntax.
2. Prefer strong typing, readonly immutability, enums, named arguments, and `match` expressions.
3. Use PHP 8.5 features only where they improve readability or correctness.
4. Avoid older PHP idioms when a modern language feature expresses the intent better.
5. Keep compatibility with the project's declared PHP version.

## Rules

- Do not generate PHP 8.5-only syntax unless the project is configured for PHP 8.5+.
- Do not use new syntax as decoration.
- Use `final readonly class` for immutable boundary and domain values.
- Use `#[\NoDiscard]` when ignoring a return value would be a bug.
- Prefer built-in URL/URI handling over ad hoc parsing when PHP 8.5 URI support is available.
