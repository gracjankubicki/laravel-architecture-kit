<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Guidance\FileGuidance;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class FileRulesCommand extends Command
{
    protected $signature = 'architecture-kit:file-rules
        {path? : Application-relative path of the file you are about to write, for example app/Actions/SendInvoice.php}
        {--agent : Output token-efficient JSON for AI agents}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Show only the architecture rules that govern one file, before writing it.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('file-rules')));

            return self::SUCCESS;
        }

        $path = $this->argument('path');

        if (! is_string($path) || trim($path) === '') {
            return $this->failWith($agent, 'Provide the application-relative path of the file, for example app/Actions/SendInvoice.php.');
        }

        try {
            $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());
            $guidance = (new FileGuidance($files, base_path(), $state->catalog, $state->auditScope))->for(
                path: trim($path),
                enabled: $state->enabled,
                customRules: $state->customRules,
            );
        } catch (Throwable $exception) {
            return $this->failWith($agent, $exception->getMessage());
        }

        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->fileRules($guidance)));

            return self::SUCCESS;
        }

        return $this->render($guidance);
    }

    /**
     * @param  array{path: string, in_scope: bool, architectures: array<int, array<string, mixed>>, rules: array<int, string>, project_rules: array<int, string>, global_rules: array<int, string>}  $guidance
     */
    private function render(array $guidance): int
    {
        $this->info('Architecture Kit File Rules');
        $this->line('Path: '.$guidance['path']);
        $this->newLine();

        if (! $guidance['in_scope']) {
            $this->line('This path is outside the audited application directory, so no rule is enforced for it.');

            return self::SUCCESS;
        }

        foreach ($guidance['architectures'] as $architecture) {
            /** @var array<int, string> $rules */
            $rules = $architecture['rules'];
            $this->line(sprintf(
                '%-9s %-9s %-26s %s',
                is_string($architecture['enforcement']) ? $architecture['enforcement'] : '',
                $architecture['governs'] === true ? 'governs' : 'shared',
                is_string($architecture['slug']) ? $architecture['slug'] : '',
                $rules === [] ? 'guidance only' : implode(', ', $rules),
            ));
        }

        $this->newLine();
        $this->line('Enforced here:  '.($guidance['rules'] === [] ? 'none' : implode(', ', $guidance['rules'])));

        if ($guidance['project_rules'] !== []) {
            $this->line('Project rules:  '.implode(', ', $guidance['project_rules']));
        }

        $this->line('Always active:  '.implode(', ', $guidance['global_rules']));

        return self::SUCCESS;
    }

    private function failWith(AgentOutput $agent, string $message): int
    {
        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->error('file-rules', $message)));

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
