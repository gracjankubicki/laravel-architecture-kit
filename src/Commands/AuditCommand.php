<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Support\AgentOutput;
use Taqie\ArchitectureKit\Support\ApplicationAudit;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureConfigPath;
use Throwable;

class AuditCommand extends Command
{
    protected $signature = 'architecture-kit:audit
        {--changed : Audit only changed and untracked application files when git is available}
        {--base= : Git base ref for changed-file audit, for example origin/main}
        {--strict : Treat warnings as failures}
        {--update-baseline : Write the current findings to .architecture-kit/baseline.json before applying baseline suppression}
        {--no-baseline : Ignore .architecture-kit/baseline.json for this audit run}
        {--agent : Output token-efficient JSON for AI agents}
        {--limit=20 : Maximum findings shown in --agent output}
        {--full : Include full finding messages in --agent output}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Audit application code against enabled Architecture Kit rules.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('audit')));

            return self::SUCCESS;
        }

        $config = new ArchitectureConfig(ArchitectureConfigPath::resolve($files, base_path()), $files);

        try {
            $enabled = $config->read();
            $exclude = $config->auditExcludes();
            $customRules = $config->customRules();
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent')) {
                $this->line($this->json($agent->error('audit', $exception->getMessage())));

                return self::FAILURE;
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $audit = new ApplicationAudit($files, base_path());
        try {
            $result = $audit->run(
                enabled: $enabled,
                changedOnly: (bool) $this->option('changed'),
                baseRef: $this->option('base') !== null ? (string) $this->option('base') : null,
                exclude: $exclude,
                customRules: $customRules,
                useBaseline: ! (bool) $this->option('no-baseline'),
                updateBaseline: (bool) $this->option('update-baseline'),
            );
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent')) {
                $this->line($this->json($agent->error('audit', $exception->getMessage())));

                return self::FAILURE;
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $ok = $result->errors() === 0
            && (! (bool) $this->option('strict') || $result->warnings() === 0);

        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->audit(
                result: $result,
                ok: $ok,
                limit: $agent->limit($this->option('limit')),
                full: (bool) $this->option('full'),
                baselineUpdated: (bool) $this->option('update-baseline'),
            )));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Architecture Kit Application Audit');
        $this->line('Scope: '.$result->scope);
        $this->newLine();

        if ($result->findings === []) {
            $this->line('No architecture violations found.');
            $this->line(sprintf('Suppressed: %d inline, %d baseline', $result->suppressedInline, $result->suppressedBaseline));

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
        $this->line(sprintf('Suppressed: %d inline, %d baseline', $result->suppressedInline, $result->suppressedBaseline));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
