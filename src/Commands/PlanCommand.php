<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Planning\ArchitecturePlanner;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class PlanCommand extends Command
{
    protected $signature = 'architecture-kit:plan
        {--agent : Output deterministic JSON for AI agents and CI}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Recommend Architecture Kit patterns and preview generated changes without writing files.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('plan')));

            return self::SUCCESS;
        }

        try {
            $plan = (new ArchitecturePlanner($files, dirname(__DIR__, 2), base_path()))->plan();
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent')) {
                $this->line($this->json($agent->error('plan', $exception->getMessage())));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->plan($plan)));

            return self::SUCCESS;
        }

        $this->info('Architecture Kit Plan');
        $this->line('Configuration: '.($plan->configured ? 'existing' : 'not installed'));

        foreach ($plan->recommendations as $recommendation) {
            $this->line(sprintf('  recommend  %-28s %s', $recommendation->slug, implode(', ', $recommendation->evidence)));
        }

        foreach ($plan->requirements as $requirement) {
            $this->line(sprintf(
                '  requirement %-28s %s',
                $requirement['name'],
                $requirement['satisfied'] ? 'satisfied' : 'blocked: '.$requirement['message'],
            ));
        }

        foreach ($plan->changes->toArray() as $status => $paths) {
            foreach ($paths as $path) {
                $this->line(sprintf('  %-10s %s', $status, $path));
            }
        }

        $this->line('No files were changed.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
