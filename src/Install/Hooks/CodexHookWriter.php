<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Hooks;

final readonly class CodexHookWriter
{
    public function command(): string
    {
        // Resolve the guard from the project directory first, then from the repository
        // root. Run it directly so its own shebang picks the interpreter: the generated
        // script needs bash, while a developer-owned POSIX script keeps working under sh.
        return 'GUARD=".architecture-kit/hooks/guard.sh"; '
            .'[ -f "$GUARD" ] || GUARD="$(git rev-parse --show-toplevel)/.architecture-kit/hooks/guard.sh"; '
            .'if [ -x "$GUARD" ]; then "$GUARD" codex; else bash "$GUARD" codex; fi';
    }
}
