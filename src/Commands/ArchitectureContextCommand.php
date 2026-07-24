<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContextException;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class ArchitectureContextCommand extends Command
{
    protected $signature = 'architecture-kit:context
        {subject? : Exact project FQCN or app-relative PHP path}
        {--agent : Output deterministic JSON for AI agents}
        {--limit=20 : Maximum dependencies and dependents returned per direction}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Show bounded static architecture context for one project symbol without changing files.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('architecture-context')));

            return self::SUCCESS;
        }

        $subject = $this->argument('subject');

        if (! is_string($subject) || trim($subject) === '') {
            return $this->failContext($agent, 'E_CONTEXT_SUBJECT_REQUIRED', 'Provide an exact project FQCN or app-relative PHP path.');
        }

        try {
            $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());
            $context = (new ArchitectureContext($files, base_path()))->inspect(
                subject: $subject,
                enabled: $state->enabled,
                exclude: $state->exclude,
                limit: $agent->limit($this->option('limit')),
            );
        } catch (ArchitectureContextException $exception) {
            return $this->failContext($agent, $exception->errorCode, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->failContext($agent, 'E_CONTEXT_FAILED', $exception->getMessage());
        }

        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->architectureContext($context)));

            return self::SUCCESS;
        }

        $this->info('Architecture Kit Project Context');
        $this->line("Subject: {$context->subject->name}");
        $this->line("Role:    {$context->subject->role}");
        $this->line("Path:    {$context->subject->path}:{$context->subject->line}");
        $this->newLine();
        $this->line('Dependencies:');
        $this->relationships($context->dependencies);
        $this->newLine();
        $this->line('Dependents:');
        $this->relationships($context->dependents);
        $this->newLine();
        $this->line('Violations: '.count($context->violations));

        foreach ($context->violations as $violation) {
            $this->line("  {$violation['severity']} {$violation['code']} {$violation['path']}:{$violation['line']}  {$violation['message']}");
        }

        $this->line('Inspect: '.($context->inspect === [] ? 'none' : implode(', ', $context->inspect)));

        if ($context->truncated) {
            $this->warn('Context was truncated by --limit.');
        }

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $relationships */
    private function relationships(array $relationships): void
    {
        if ($relationships === []) {
            $this->line('  none');

            return;
        }

        foreach ($relationships as $relationship) {
            $this->line(sprintf(
                '  %-14s %-8s %s  %s:%d',
                $relationship['role'],
                $relationship['kind'],
                $relationship['symbol'],
                $relationship['evidence']['path'],
                $relationship['evidence']['line'],
            ));
        }
    }

    private function failContext(AgentOutput $agent, string $code, string $message): int
    {
        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->architectureContextError($code, $message)));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
