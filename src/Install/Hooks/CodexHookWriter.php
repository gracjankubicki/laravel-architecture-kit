<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Hooks;

final readonly class CodexHookWriter
{
    public function command(): string
    {
        return 'sh "$(git rev-parse --show-toplevel)/.architecture-kit/hooks/guard.sh" codex';
    }
}
