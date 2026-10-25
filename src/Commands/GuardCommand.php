<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuard;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class GuardCommand extends Command
{
    protected $signature = 'architecture-kit:guard
        {--changed : Audit only changed and untracked application files when git is available}
        {--base= : Git base ref for changed-file audit, for example origin/main}
        {--target : Include the separate desired architecture gate after Deep consent}
        {--mode=basic : Analysis mode for optional target assessment; audit stays Basic}
        {--task= : Consented task for Deep target assessment}
        {--enforce : Explicitly block on unsuccessful analysis}
        {--strict : Treat architecture warnings as failures}
        {--json : Output machine-readable JSON for hooks and MCP}
        {--agent : Output token-efficient JSON for AI agents}
        {--limit=20 : Maximum findings shown in --agent output}
        {--full : Include full finding messages in --agent output}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Run Architecture Kit generated-resource checks and application audit as one gate.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;
        $policy = $this->option('enforce') ? ControlPolicy::Enforce : ControlPolicy::Advisory;

        if ((bool) $this->option('schema')) {
            $this->writeJson($agent->schema('guard'));

            return self::SUCCESS;
        }

        try {
            if ($this->option('target')) {
                RepositoryContext::requiredDeep($this->option('mode'), $this->option('task'));
            } elseif ($this->option('mode') !== 'basic') {
                throw new \RuntimeException('Guard audit uses Basic; Deep mode is available only with --target.');
            }
            $result = (new ArchitectureGuard($files, dirname(__DIR__, 2), RepositoryContext::root(), $this->getApplication()))->run(
                changedOnly: (bool) $this->option('changed'),
                baseRef: $this->option('base') !== null ? (string) $this->option('base') : null,
                strict: (bool) $this->option('strict'),
                includeTarget: (bool) $this->option('target'),
                policy: $this->option('enforce') ? ControlPolicy::Enforce : null,
            );
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent') || (bool) $this->option('json')) {
                $this->writeJson($agent->error('guard', $exception->getMessage(), policy: $policy));

                return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
            }

            $this->error($exception->getMessage());

            return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
        }

        if ((bool) $this->option('agent')) {
            $this->writeJson($agent->guard(
                result: $result,
                limit: $agent->limit($this->option('limit')),
                full: (bool) $this->option('full'),
            ));

            return $result->control()['blocking'] ? self::FAILURE : self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '{}', OutputInterface::OUTPUT_RAW);

            return $result->control()['blocking'] ? self::FAILURE : self::SUCCESS;
        }

        $policy = $result->policy;
        $this->info('Architecture Kit Guard');
        $this->line('Basic audit; '.($this->option('target') ? 'Deep target assessment' : 'Deep not run').'. Policy: '.$policy->value);
        $this->line('Doctor: '.($result->doctor->ok() ? 'ok' : 'failed'));

        if ($result->audit === null) {
            $this->line('Audit: skipped because config is invalid.');
        } else {
            if ($result->target !== null) {
                $this->line('Target: '.json_encode($result->target['gate'] ?? $result->target, JSON_THROW_ON_ERROR));
            }
            $this->line('Audit: '.$result->audit->scope);
            $this->line(sprintf('Findings: %d error(s), %d warning(s)', $result->audit->errors(), $result->audit->warnings()));
            $this->line(sprintf('Suppressed: %d inline, %d baseline', $result->audit->suppressedInline, $result->audit->suppressedBaseline));

            if ($result->audit->hasAnalysisFailure()) {
                $this->warn('Required runtime route analysis failed; the guard cannot pass. Inspect audit --agent.');
            } elseif ($result->audit->incomplete()) {
                $this->warn($result->blockIncompleteAnalysis
                    ? 'Analysis is partial; audit.incomplete_analysis=block requires completeness.'
                    : 'Analysis is partial; this check covers recognized violations only. Inspect audit --agent for unresolved code.');
            }

            if ($result->audit->cacheNote() !== null) {
                $this->warn($result->audit->cacheNote());
            }
        }

        if ($result->audit !== null && $result->audit->suggestions !== []) {
            $this->line('Architectural suggestions');
            foreach ($result->audit->suggestions as $suggestion) {
                $this->line(sprintf('%s:%d  %s %s', $suggestion->path, $suggestion->line, $suggestion->architecture, $suggestion->message));
            }
            $this->newLine();
        }
        if ($result->audit !== null && $result->audit->notices !== []) {
            $this->line('Incomplete analysis');
            foreach ($result->audit->notices as $notice) {
                $this->line(sprintf('%s:%d  %s', $notice->path, $notice->line, $notice->message));
            }
            $this->newLine();
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

            return $policy === ControlPolicy::Enforce ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Architecture guard passed.');

        return self::SUCCESS;
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
