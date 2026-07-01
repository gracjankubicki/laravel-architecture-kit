<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Support\ArchitectureGuard;

class GuardCommand extends Command
{
    protected $signature = 'architecture-kit:guard
        {--changed : Audit only changed and untracked application files when git is available}
        {--base= : Git base ref for changed-file audit, for example origin/main}
        {--strict : Treat warnings as failures}
        {--json : Output machine-readable JSON for hooks and MCP}';

    protected $description = 'Run Architecture Kit generated-resource checks and application audit as one gate.';

    public function handle(Filesystem $files): int
    {
        $result = (new ArchitectureGuard($files, dirname(__DIR__, 2), base_path(), $this->getApplication()))->run(
            changedOnly: (bool) $this->option('changed'),
            baseRef: $this->option('base') !== null ? (string) $this->option('base') : null,
            strict: (bool) $this->option('strict'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '{}');

            return $result->ok() ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Architecture Kit Guard');
        $this->line('Doctor: '.($result->doctor->ok() ? 'ok' : 'failed'));

        if ($result->audit === null) {
            $this->line('Audit: skipped because config is invalid.');
        } else {
            $this->line('Audit: '.$result->audit->scope);
            $this->line(sprintf('Findings: %d error(s), %d warning(s)', $result->audit->errors(), $result->audit->warnings()));
        }

        if (! $result->ok()) {
            $this->newLine();

            foreach ($result->doctor->checks as $check) {
                if (! $check->failed()) {
                    continue;
                }

                $this->line(sprintf('%-8s %-12s %s%s', $check->status, $check->area, $check->path, $check->message !== null ? '  '.$check->message : ''));
            }

            if ($result->audit !== null) {
                foreach ($result->audit->findings as $finding) {
                    $this->line(sprintf(
                        '%-5s %-24s %s:%d  %s',
                        $finding->severity,
                        $finding->rule,
                        $finding->path,
                        $finding->line,
                        $finding->message,
                    ));
                }
            }

            return self::FAILURE;
        }

        $this->line('Architecture guard passed.');

        return self::SUCCESS;
    }
}
