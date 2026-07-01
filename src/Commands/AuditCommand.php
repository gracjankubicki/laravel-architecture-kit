<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;
use Taqie\ArchitectureKit\Support\ApplicationAudit;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;

class AuditCommand extends Command
{
    protected $signature = 'architecture-kit:audit
        {--changed : Audit only changed and untracked application files when git is available}
        {--strict : Treat warnings as failures}';

    protected $description = 'Audit application code against enabled Architecture Kit rules.';

    public function handle(Filesystem $files): int
    {
        $config = new ArchitectureConfig(config_path('architectures.php'), $files);

        try {
            $enabled = $config->read();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $audit = new ApplicationAudit($files, base_path());
        $result = $audit->run(
            enabled: $enabled,
            changedOnly: (bool) $this->option('changed'),
        );

        $this->info('Architecture Kit Application Audit');
        $this->line('Scope: '.$result->scope);
        $this->newLine();

        if ($result->findings === []) {
            $this->line('No architecture violations found.');

            return self::SUCCESS;
        }

        foreach ($result->findings as $finding) {
            $this->line(sprintf(
                '%-5s %-24s %s:%d  %s',
                $finding->severity,
                $finding->rule,
                $finding->path,
                $finding->line,
                $finding->message,
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'Summary: %d error(s), %d warning(s)',
            $result->errors(),
            $result->warnings(),
        ));

        if ($result->errors() > 0) {
            return self::FAILURE;
        }

        return (bool) $this->option('strict') && $result->warnings() > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
