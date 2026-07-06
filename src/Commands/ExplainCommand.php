<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Taqie\ArchitectureKit\Audit\FindingCodeRegistry;
use Taqie\ArchitectureKit\Output\AgentOutput;

class ExplainCommand extends Command
{
    protected $signature = 'architecture-kit:explain
        {code? : Finding code, for example E_THIN_CONTROLLER_MODEL_WRITE}
        {--agent : Output agent-optimized JSON}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Explain an Architecture Kit finding code.';

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

        $explanation = $codes->explain($code);

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

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
