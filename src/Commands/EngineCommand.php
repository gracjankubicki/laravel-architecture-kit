<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDiagnostics;
use Illuminate\Console\Command;
use Throwable;

final class EngineCommand extends Command
{
    protected $signature = 'architecture-kit:engine {action=describe : Describe the project analysis engine}';

    protected $description = 'Show the selected analysis engine, implementation, target PHP and configuration origin.';

    public function handle(): int
    {
        try {
            if ($this->argument('action') !== 'describe') {
                throw new \InvalidArgumentException('Use engine describe. Installation uses the standalone architecture-kit engine install command.');
            }
            $this->line(json_encode(['ok' => true, ...AnalysisDiagnostics::current()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->line(json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
