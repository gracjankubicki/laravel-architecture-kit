<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Install\Requirements\ArchitectureKitRuntimeRequirement;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResourceManifest;
use GracjanKubicki\ArchitectureKit\Resources\ManagedResourceDeployment;
use GracjanKubicki\ArchitectureKit\Resources\ManagedResourcePlan;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class SyncCommand extends Command
{
    protected $signature = 'architecture-kit:sync
        {--dry-run : Show the complete resource plan without writing}
        {--agent : Output deterministic JSON for AI agents and CI}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'Regenerate marker-owned Architecture Kit AI resources from existing configuration.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('sync')));

            return self::SUCCESS;
        }

        $runtime = (new ArchitectureKitRuntimeRequirement(new ProjectPackageInventory($files, base_path())))->check();

        if (! $runtime->satisfied) {
            return $this->failure($agent, $runtime->message.' '.$runtime->remediation);
        }

        try {
            $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());

            if ($state->laravelAi !== null && ! $state->laravelAi->supported()) {
                return $this->failure($agent, $state->laravelAi->message.' '.$state->laravelAi->remediation, $state->laravelAi->toArray());
            }

            $state->resources->assertSourcesExist($state->enabled);
            $manifest = new ArchitectureResourceManifest($state->resources);
            $expected = $manifest->expected($state->enabled);
            $stale = $manifest->stale($state->enabled);
            $deployment = new ManagedResourceDeployment($files, $state->resources);
            $plan = $deployment->plan($expected, $stale);
        } catch (Throwable $exception) {
            return $this->failure($agent, $exception->getMessage());
        }

        if ($plan->blocked !== []) {
            return $this->failure($agent, 'Unmanaged files block generated Architecture Kit targets: '.implode(', ', $this->relativePaths($plan->blocked)).'.');
        }

        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->sync(
                changes: $this->relativePlan($plan),
                dryRun: (bool) $this->option('dry-run'),
                profile: $state->laravelAi?->toArray(),
            )));

            if ((bool) $this->option('dry-run')) {
                return self::SUCCESS;
            }
        } elseif ((bool) $this->option('dry-run')) {
            $this->showPlan($plan);
            $this->info('Dry run only. No files were changed.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('dry-run')) {
            $deployment->apply($expected, $stale);
        }

        if (! (bool) $this->option('agent')) {
            $this->showPlan($plan);
            $this->info($plan->hasChanges() ? 'Architecture Kit resources synchronized.' : 'Architecture Kit resources are already current.');
            $this->line('Next: php artisan boost:update --no-interaction');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed>|null $profile */
    private function failure(AgentOutput $agent, string $message, ?array $profile = null): int
    {
        if ((bool) $this->option('agent')) {
            $this->line($this->json($agent->syncError($message, $profile)));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function showPlan(ManagedResourcePlan $plan): void
    {
        foreach (['create', 'update', 'remove'] as $status) {
            foreach ($plan->{$status} as $path) {
                $this->line(sprintf('  %-7s %s', $status, $this->relative($path)));
            }
        }
    }

    /** @return array<string, array<int, string>> */
    private function relativePlan(ManagedResourcePlan $plan): array
    {
        return [
            'create' => $this->relativePaths($plan->create),
            'update' => $this->relativePaths($plan->update),
            'remove' => $this->relativePaths($plan->remove),
        ];
    }

    /** @param array<int, string> $paths @return array<int, string> */
    private function relativePaths(array $paths): array
    {
        return array_map($this->relative(...), $paths);
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, base_path().'/') ? substr($path, strlen(base_path()) + 1) : $path;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
