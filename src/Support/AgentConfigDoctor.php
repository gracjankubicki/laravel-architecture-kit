<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Facades\Mcp;
use Symfony\Component\Console\Application as ConsoleApplication;
use Taqie\ArchitectureKit\Install\AgentsDetector;
use Taqie\ArchitectureKit\Install\InstallState;

final readonly class AgentConfigDoctor
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private ?ConsoleApplication $console = null,
    ) {}

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

        foreach ($stored['agents'] as $name) {
            if (! isset($agentsByName[$name])) {
                $checks[] = new ArchitectureDoctorCheck('agents', 'blocked', $state->relativePath(), "Unknown agent [{$name}].");

                continue;
            }
        }

        return $checks;
    }
}
