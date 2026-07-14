<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\EnabledArchitecture;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class GuidelinesCommand extends Command
{
    protected $signature = 'architecture-kit:guidelines
        {architecture? : Architecture slug to expand, for example actions}
        {--agent : Output token-efficient JSON for AI agents}
        {--schema : Output the JSON Schema for --agent output}';

    protected $description = 'List or expand Architecture Kit guidelines.';

    public function handle(Filesystem $files): int
    {
        $agent = new AgentOutput;

        if ((bool) $this->option('schema')) {
            $this->line($this->json($agent->schema('guidelines')));

            return self::SUCCESS;
        }

        try {
            $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());
            $state->assertCompatibility();
            $enabled = $state->enabled;
            $resources = $state->resources;
            $known = $state->catalog->known();
        } catch (Throwable $exception) {
            if ((bool) $this->option('agent')) {
                $this->line($this->json($agent->error('guidelines', $exception->getMessage())));

                return self::FAILURE;
            }

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $slug = (string) ($this->argument('architecture') ?? '');

        if ($slug === '') {
            $payload = $this->listPayload($resources, $known, $enabled, $state->laravelAi?->toArray());

            if ((bool) $this->option('agent')) {
                $this->line($this->json($payload));

                return self::SUCCESS;
            }

            $this->table(
                ['slug', 'label', 'enabled', 'summary'],
                array_map(fn (array $architecture): array => [
                    $architecture['slug'],
                    $architecture['label'],
                    $architecture['enabled'] ? 'yes' : 'no',
                    $architecture['sum'],
                ], $payload['arch']),
            );

            return self::SUCCESS;
        }

        $architecture = $known[$slug] ?? null;

        if ($architecture === null) {
            $payload = [
                'v' => 1,
                'ok' => false,
                'cmd' => 'guidelines',
                'slug' => $slug,
                'm' => 'E_UNKNOWN_ARCHITECTURE',
                'known' => array_keys($known),
                'next' => ['rerun:guidelines --agent'],
            ];

            if ((bool) $this->option('agent')) {
                $this->line($this->json($payload));

                return self::FAILURE;
            }

            $this->error("Unknown Architecture Kit architecture [{$slug}].");
            $this->line('Known architectures: '.implode(', ', array_keys($known)));

            return self::FAILURE;
        }

        $payload = [
            'v' => 1,
            'ok' => true,
            'cmd' => 'guidelines',
            ...($state->laravelAi !== null ? ['laravel_ai' => $state->laravelAi->toArray()] : []),
            'slug' => $architecture->slug(),
            'label' => $architecture->label(),
            'enabled' => in_array($architecture->slug(), $this->enabledSlugs($enabled), true),
            'skill' => $architecture->skillName(),
            'md' => $resources->architectureGuideline($architecture, $enabled),
        ];

        if ((bool) $this->option('agent')) {
            $this->line($this->json($payload));

            return self::SUCCESS;
        }

        $this->line($payload['md']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, EnabledArchitecture>  $known
     * @param  array<int, Architecture|string>  $enabled
     * @return array<string, mixed>
     */
    private function listPayload(ArchitectureResources $resources, array $known, array $enabled, ?array $laravelAi = null): array
    {
        $enabledSlugs = $this->enabledSlugs($enabled);

        return [
            'v' => 1,
            'ok' => true,
            'cmd' => 'guidelines',
            ...($laravelAi !== null ? ['laravel_ai' => $laravelAi] : []),
            'arch' => array_map(fn (EnabledArchitecture $architecture): array => [
                'slug' => $architecture->slug(),
                'label' => $architecture->label(),
                'enabled' => in_array($architecture->slug(), $enabledSlugs, true),
                'skill' => $architecture->skillName(),
                'sum' => $resources->summaryFor($architecture, $enabled),
            ], array_values($known)),
            'next' => ['guidelines {slug} --agent'],
        ];
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    private function enabledSlugs(array $enabled): array
    {
        return array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
            $enabled,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
