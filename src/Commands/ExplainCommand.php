<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Audit\FindingOccurrence;
use GracjanKubicki\ArchitectureKit\Audit\FindingOccurrenceResolver;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ExplainCommand extends Command
{
    protected $signature = 'architecture-kit:explain
        {code? : Finding code, for example E_THIN_CONTROLLER_MODEL_WRITE}
        {--path= : Application-relative path of the file the finding was reported for}
        {--line= : Line the finding was reported on}
        {--agent : Output agent-optimized JSON}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Explain an Architecture Kit finding, optionally for one reported occurrence.';

    public function handle(FindingCodeRegistry $codes): int
    {
        if ((bool) $this->option('schema')) {
            $this->line($this->json((new AgentOutput)->schema('explain')));

            return self::SUCCESS;
        }

        $code = strtoupper((string) $this->argument('code'));

        if ($code === '') {
            $payload = [
                'v' => 1,
                'ok' => false,
                'cmd' => 'explain',
                'code' => '',
                'm' => 'E_MISSING_FINDING_CODE',
                'next' => ['rerun:audit --agent', 'use_known_finding_code'],
            ];

            if ((bool) $this->option('agent')) {
                $this->line($this->json($payload));

                return self::FAILURE;
            }

            $this->error('Missing Architecture Kit finding code.');

            return self::FAILURE;
        }

        $explanation = $codes->explain($code, $this->occurrence());

        if ($explanation === null) {
            $payload = [
                'v' => 1,
                'ok' => false,
                'cmd' => 'explain',
                'code' => $code,
                'm' => 'E_UNKNOWN_FINDING_CODE',
                'next' => ['rerun:audit --agent', 'use_known_finding_code'],
            ];

            if ((bool) $this->option('agent')) {
                $this->line($this->json($payload));

                return self::FAILURE;
            }

            $this->error("Unknown Architecture Kit finding code [{$code}].");

            return self::FAILURE;
        }

        $payload = [
            'v' => 1,
            'ok' => true,
            'cmd' => 'explain',
            ...$explanation,
        ];

        if ((bool) $this->option('agent')) {
            $this->line($this->json($payload));

            return self::SUCCESS;
        }

        $this->info($payload['title']);
        $this->line('Code: '.$payload['code']);
        $this->line('Rule: '.$payload['rule']);
        $this->newLine();
        $this->line('Why: '.$payload['why']);
        $this->line('Fix: '.$payload['fix']);

        if (isset($payload['proposal']) && is_array($payload['proposal'])) {
            $this->newLine();
            $this->line('Proposed change: '.(is_string($payload['proposal']['summary']) ? $payload['proposal']['summary'] : ''));
            $this->line('Apply it or reject it; Architecture Kit does not edit your code.');
        }

        return self::SUCCESS;
    }

    private function occurrence(): ?FindingOccurrence
    {
        $path = $this->option('path');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $line = $this->option('line');

        return (new FindingOccurrenceResolver(new Filesystem, dirname(__DIR__, 2), base_path()))
            ->resolve(trim($path), is_numeric($line) ? (int) $line : null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
