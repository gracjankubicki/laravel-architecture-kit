# Version-independent Laravel AI architecture policy

Architecture Kit owns the application boundary around Laravel AI: project-owned Gateways, Prompt Data, Result Data, Tool-to-Action delegation, transaction/after-commit rules, authorization, observability, human review and tests. Exact SDK APIs belong to the official `ai-sdk-development` skill shipped by the installed `laravel/ai` package.
