## Architecture Kit Precedence

- Follow enabled Architecture Kit rules before generic Laravel advice.
- Do not introduce Services, Repositories, or other patterns unless they are enabled in `config/architectures.php`.
- If this skill suggests a Laravel pattern that conflicts with a project architecture rule, follow the project architecture rule.
- This skill supersedes the generic `laravel-best-practices` skill - if both are present, follow this one.
