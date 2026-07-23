<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Upgrades\UpgradePathPlanner;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class UpgradePlanCommand extends Command
{
    protected $signature = 'architecture-kit:upgrade-plan
        {package? : Direct Composer package name}
        {--to= : Required target major.minor version line}
        {--agent : Output deterministic JSON for AI agents and CI}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Plan the next atomic package upgrade step without changing files.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('upgrade-plan')));

            return self::SUCCESS;
        }

        $package = $this->argument('package');
        $target = $this->option('to');

        if (! is_string($package) || trim($package) === '' || ! is_string($target) || trim($target) === '') {
            return $this->failCommand($agent, 'Provide a Composer package and --to=<major.minor>.');
        }

        try {
            $plan = (new UpgradePathPlanner(
                files: $files,
                packagePath: dirname(__DIR__, 2),
                basePath: base_path(),
            ))->plan(trim($package), trim($target));
        } catch (Throwable $exception) {
            return $this->failCommand($agent, $exception->getMessage());
        }

        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->upgradePlan($plan)));

            return $plan->ok() ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Architecture Kit Upgrade Plan');
        $this->line('Package:    '.$plan->package->name);
        $this->line('Declared:   '.($plan->package->declaredConstraint ?? 'missing'));
        $this->line('Locked:     '.($plan->package->lockedVersion ?? 'missing'));
        $this->line('Installed:  '.($plan->package->installedVersion ?? 'missing'));
        $this->line('Target:     '.$plan->target);
        $this->line('Status:     '.$plan->status);

        if ($plan->route !== []) {
            $this->newLine();
            $this->line('Route:');

            foreach ($plan->route as $step) {
                $this->line(sprintf(
                    '  %-7s %s -> %s  %s',
                    strtoupper($step->status),
                    $step->guide->from->value,
                    $step->guide->to->value,
                    $step->guide->name,
                ));
            }
        }

        $this->newLine();
        $this->line($plan->message);
        $this->line('No files were changed.');

        return $plan->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function failCommand(AgentOutput $agent, string $message): int
    {
        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->error('upgrade-plan', $message)));
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
