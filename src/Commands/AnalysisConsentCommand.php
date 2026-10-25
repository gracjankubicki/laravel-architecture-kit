<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Console\Command;
use Throwable;

final class AnalysisConsentCommand extends Command
{
    protected $signature = 'architecture-kit:analysis-consent {task : Task whose human consent was obtained} {--confirm : Record prior human consent for Deep analysis}';

    protected $description = 'Record previously obtained task-scoped Deep consent, without runtime or custom PHP permission.';

    public function handle(): int
    {
        try {
            if (! $this->option('confirm')) {
                throw new \RuntimeException('Ask the human before Deep analysis, then record their consent with --confirm.');
            }
            $result = ['ok' => true, 'consent' => (new AnalysisConsent(RepositoryContext::consentContext()))->grantDeep((string) $this->argument('task'))];
        } catch (Throwable $error) {
            $result = ['ok' => false, 'reason' => $error->getMessage()];
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
