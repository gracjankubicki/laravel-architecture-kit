<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use Illuminate\Console\Command;

class InstallHooksCommand extends Command
{
    protected $signature = 'architecture-kit:install-hooks
        {--codex : Generate Codex hook configuration}
        {--claude : Generate Claude Code hook configuration}
        {--force : Kept for backwards compatibility; hook files are merged safely}';

    protected $description = 'Generate Architecture Kit hook configuration for AI coding agents.';

    public function handle(): int
    {
        return $this->call('architecture-kit:install-agents', [
            '--codex' => (bool) $this->option('codex'),
            '--claude' => (bool) $this->option('claude'),
            '--hooks' => true,
        ]);
    }
}
