<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditSuggestion;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class AuditCommand extends Command
{
    protected $signature = 'architecture-kit:audit
        {--changed : Audit only changed and untracked application files when git is available}
        {--base= : Git base ref for changed-file audit, for example origin/main}
        {--enforce : Explicitly block on unsuccessful analysis}
        {--strict : Treat warnings as failures}
        {--update-baseline : Write the current findings to .architecture-kit/baseline.json before applying baseline suppression}
        {--no-baseline : Ignore .architecture-kit/baseline.json for this audit run}
        {--agent : Output token-efficient JSON for AI agents}
        {--limit=20 : Maximum findings shown in --agent output}
        {--full : Include full finding messages in --agent output}
        {--schema : Output the JSON Schema for --agent output} {--mode=basic : Analysis scope basic or deep; deep needs prior task consent} {--task= : Task with recorded Deep consent}';

    protected $description = 'Audit application code against enabled Architecture Kit rules.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;
        $policy = $this->option('enforce') ? ControlPolicy::Enforce : ControlPolicy::Advisory;

        if ((bool) $this->option('schema')) {
            $this->writeJson($agent->schema('audit'));

            return self::SUCCESS;
        }

        if ((bool) $this->option('changed') && (bool) $this->option('update-baseline')) {
            $message = 'The --changed and --update-baseline options cannot be used together. Use --update-baseline without --changed, or run --changed without --update-baseline.';

            if ((bool) $this->option('agent')) {
                $this->writeJson($agent->error('audit', $message, policy: $policy));

                return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
            }

            $this->error($message);

            return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
        }

        try {
            $mode = RepositoryContext::requestedMode($this->option('mode'), $this->option('task'));
            $state = ProjectState::load($files, dirname(__DIR__, 2), RepositoryContext::root());
            $policy = $this->option('enforce') ? ControlPolicy::Enforce : $state->controlPolicy;
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent')) {
                $this->writeJson($agent->error('audit', $exception->getMessage(), policy: $policy));

                return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
            }

            $this->error($exception->getMessage());

            return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
        }

        $audit = new ApplicationAudit($files, RepositoryContext::root());
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
                mode: $mode,
            );
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent')) {
                $this->writeJson($agent->error('audit', $exception->getMessage(), policy: $policy));

                return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
            }

            $this->error($exception->getMessage());

            return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
        }

        $ok = $result->analysis()['checks_complete'] && ! $result->hasAnalysisFailure() && $result->errors() === 0
            && (! (bool) $this->option('strict') || $result->warnings() === 0);

        if ((bool) $this->option('agent')) {
            $this->writeJson($agent->audit(
                result: $result,
                ok: $ok,
                limit: $agent->limit($this->option('limit')),
                full: (bool) $this->option('full'),
                baselineUpdated: (bool) $this->option('update-baseline'),
                policy: $policy,
            ));

            return $policy->result($ok)['blocking'] ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Architecture Kit Application Audit');
        $this->line('Scope: '.$result->scope.'; analysis '.$mode->value.'; deep '.($mode->value === 'deep' ? 'run' : 'not run').'; policy '.$policy->value);

        if ($result->cacheNote() !== null) {
            $this->warn($result->cacheNote());
        }

        $this->newLine();
        $this->writeSuggestions($result->suggestions);
        $this->writeAnalysis($result->notices);

        if ($result->findings === []) {
            $this->line('No architecture violations found.');
            $this->line(sprintf('Suppressed: %d inline, %d baseline', $result->suppressedInline, $result->suppressedBaseline));

            return $policy->result($ok)['blocking'] ? self::FAILURE : self::SUCCESS;
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

        return $policy->result($ok)['blocking'] ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<int, AuditSuggestion> $suggestions */
    private function writeSuggestions(array $suggestions): void
    {
        if ($suggestions === []) {
            return;
        }
        $this->line('Architectural suggestions');
        foreach ($suggestions as $suggestion) {
            $this->line(sprintf('%s:%d  %s %s', $suggestion->path, $suggestion->line, $suggestion->architecture, $suggestion->message));
        }
        $this->newLine();
    }

    /** @param array<int, AnalysisNotice> $notices */
    private function writeAnalysis(array $notices): void
    {
        if ($notices === []) {
            return;
        }
        $this->line('Incomplete analysis');
        foreach ($notices as $notice) {
            $this->line(sprintf('%s:%d  %s', $notice->path, $notice->line, $notice->message));
        }
        $this->newLine();
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(array $payload): void
    {
        $json = $this->json($payload);
        unset($payload);
        $output = $this->output->getOutput();
        for ($offset = 0, $length = strlen($json); $offset < $length; $offset += 65536) {
            $output->write(substr($json, $offset, 65536), false, OutputInterface::OUTPUT_RAW);
        }
        $output->writeln('', OutputInterface::OUTPUT_RAW);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
