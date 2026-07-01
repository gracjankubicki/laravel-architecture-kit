<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Facades\Mcp;
use Symfony\Component\Console\Application as ConsoleApplication;
use Taqie\ArchitectureKit\Install\Agents\Agent;
use Taqie\ArchitectureKit\Install\AgentsDetector;
use Taqie\ArchitectureKit\Install\Contracts\SupportsHooks;
use Taqie\ArchitectureKit\Install\Contracts\SupportsMcp;
use Taqie\ArchitectureKit\Install\Hooks\HookWriter;
use Taqie\ArchitectureKit\Install\InstallResult;
use Taqie\ArchitectureKit\Install\InstallState;
use Taqie\ArchitectureKit\Install\Mcp\McpWriter;

final readonly class AgentConfigDoctor
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private ?ConsoleApplication $console = null,
    ) {
    }

    /**
     * @return array<int, ArchitectureDoctorCheck>
     */
    public function checks(): array
    {
        $state = new InstallState($this->files, $this->basePath);
        $path = $this->basePath.'/'.$state->relativePath();

        if (! $this->files->exists($path)) {
            return [];
        }

        $stored = $state->read();

        if ($stored === null) {
            return [
                new ArchitectureDoctorCheck('agents', 'blocked', $state->relativePath(), 'Install state is invalid JSON or has an unsupported shape.'),
            ];
        }

        $detector = new AgentsDetector($this->files, $this->basePath);
        $agentsByName = $detector->agents();
        $checks = [
            new ArchitectureDoctorCheck('agents', 'current', $state->relativePath()),
        ];

        $checks[] = new ArchitectureDoctorCheck(
            'agents',
            $this->console === null || $this->console->has('architecture-kit:mcp') ? 'current' : 'missing',
            'artisan architecture-kit:mcp',
        );

        $checks[] = new ArchitectureDoctorCheck(
            'agents',
            Mcp::getLocalServer('architecture-kit') !== null ? 'current' : 'missing',
            'mcp:architecture-kit',
        );

        $agents = [];

        foreach ($stored['agents'] as $name) {
            if (! isset($agentsByName[$name])) {
                $checks[] = new ArchitectureDoctorCheck('agents', 'blocked', $state->relativePath(), "Unknown agent [{$name}].");

                continue;
            }

            $agents[] = $agentsByName[$name];
        }

        if ($stored['install']['mcp']) {
            $checks = array_merge($checks, $this->mcpChecks($agents));
        }

        if ($stored['install']['hooks']) {
            $checks = array_merge($checks, $this->hookChecks($agents));
        }

        return $checks;
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function mcpChecks(array $agents): array
    {
        $checks = [];
        $writer = new McpWriter($this->files, $this->basePath);

        foreach ($agents as $agent) {
            if (! $agent instanceof SupportsMcp) {
                continue;
            }

            $checks[] = new ArchitectureDoctorCheck(
                area: 'agents',
                status: $this->statusFor($writer->plan([$agent]), $agent->mcpConfigPath()),
                path: $agent->mcpConfigPath(),
            );
        }

        return $checks;
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function hookChecks(array $agents): array
    {
        $checks = [];
        $writer = new HookWriter($this->files, $this->basePath);

        $guardPath = '.architecture-kit/hooks/guard.sh';
        $checks[] = new ArchitectureDoctorCheck(
            area: 'agents',
            status: $this->guardStatus($writer, $guardPath),
            path: $guardPath,
        );

        foreach ($agents as $agent) {
            if (! $agent instanceof SupportsHooks) {
                continue;
            }

            $checks[] = new ArchitectureDoctorCheck(
                area: 'agents',
                status: $this->statusFor($writer->plan([$agent]), $agent->hookConfigPath()),
                path: $agent->hookConfigPath(),
            );
        }

        return $checks;
    }

    private function guardStatus(HookWriter $writer, string $path): string
    {
        if (! $this->files->exists($this->basePath.'/'.$path)) {
            return 'missing';
        }

        return $writer->guardScriptCurrent() ? 'current' : 'outdated';
    }

    private function statusFor(InstallResult $result, string $path): string
    {
        if (in_array($path, $result->blocked, true)) {
            return 'blocked';
        }

        if (in_array($path, $result->creates, true)) {
            return 'missing';
        }

        if (in_array($path, $result->updates, true)) {
            return 'outdated';
        }

        return 'current';
    }
}
