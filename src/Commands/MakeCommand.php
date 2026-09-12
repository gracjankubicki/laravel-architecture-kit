<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Scaffolding\Scaffolder;
use GracjanKubicki\ArchitectureKit\Scaffolding\ScaffoldException;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class MakeCommand extends Command
{
    protected $signature = 'architecture-kit:make
        {architecture? : Enabled architecture slug, for example actions}
        {name? : Element name, for example SendInvoice or Billing/SendInvoice}
        {--agent : Return the file plan and skeleton as JSON without writing anything}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Scaffold a new element for an enabled architecture, following the project conventions.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('make')));

            return self::SUCCESS;
        }

        $architecture = $this->argument('architecture');
        $name = $this->argument('name');

        if (! is_string($architecture) || trim($architecture) === '' || ! is_string($name) || trim($name) === '') {
            return $this->failWith($agent, 'E_MAKE_ARGUMENTS_REQUIRED', 'Provide an architecture slug and a name, for example: architecture-kit:make actions SendInvoice.');
        }

        try {
            $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());
            $scaffolder = new Scaffolder($files, base_path(), $state->catalog);
            $plan = $scaffolder->plan($architecture, $name, $state->enabled);
        } catch (ScaffoldException $exception) {
            return $this->failWith($agent, $exception->errorCode, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->failWith($agent, 'E_MAKE_FAILED', $exception->getMessage());
        }

        // The agent path stays read-only on purpose: the agent decides what to write.
        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->make($plan, written: false)));

            return self::SUCCESS;
        }

        try {
            $scaffolder->write($plan);
        } catch (ScaffoldException $exception) {
            return $this->failWith($agent, $exception->errorCode, $exception->getMessage());
        }

        $this->info('Architecture Kit Scaffold');
        $this->line("Architecture: {$plan->architecture}");
        $this->line("Class:        {$plan->namespace}\\{$plan->class}");
        $this->newLine();

        foreach ($plan->paths() as $path) {
            $this->line('created  '.$path);
        }

        $this->newLine();
        $this->line('Review the skeleton, then run php artisan architecture-kit:audit --changed.');

        return self::SUCCESS;
    }

    private function failWith(AgentOutput $agent, string $code, string $message): int
    {
        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->error('make', $message, $code)));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
