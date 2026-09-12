<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
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

        if ((bool) $this->option('changed') && (bool) $this->option('update-baseline')) {
            $message = 'The --changed and --update-baseline options cannot be used together. Use --update-baseline without --changed, or run --changed without --update-baseline.';

            if ((bool) $this->option('agent')) {
                $this->line($this->json($agent->error('audit', $message)));

                return self::FAILURE;
            }

            $this->error($message);

            return self::FAILURE;
        }

        try {
            $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());
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
                enabled: $state->enabled,
                changedOnly: (bool) $this->option('changed'),
                baseRef: $this->option('base') !== null ? (string) $this->option('base') : null,
                exclude: $state->exclude,
                customRules: $state->customRules,
                useBaseline: ! (bool) $this->option('no-baseline'),
                updateBaseline: (bool) $this->option('update-baseline'),
                scope: $state->auditScope,
                missingTestLevel: $state->missingTestLevel,
                cache: $state->graphCache,
                cacheConfiguration: $state->graphConfiguration(),
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

        if ($result->cacheNote() !== null) {
            $this->warn($result->cacheNote());
        }

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
