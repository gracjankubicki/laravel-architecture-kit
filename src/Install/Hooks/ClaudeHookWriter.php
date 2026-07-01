<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Hooks;

final readonly class ClaudeHookWriter
{
    public function command(): string
    {
        return '${CLAUDE_PROJECT_DIR}/.architecture-kit/hooks/guard.sh claude';
    }
}
