<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install;

use GracjanKubicki\ArchitectureKit\Install\Agents\Agent;
use GracjanKubicki\ArchitectureKit\Install\Agents\ClaudeCode;
use GracjanKubicki\ArchitectureKit\Install\Agents\Codex;
use Illuminate\Filesystem\Filesystem;

final readonly class AgentsDetector
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * @return array<string, Agent>
     */
    public function agents(): array
    {
        return [
            'codex' => new Codex($this->files, $this->basePath),
            'claude_code' => new ClaudeCode($this->files, $this->basePath),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function detectedAgentNames(): array
    {
        return array_values(array_map(
            fn (Agent $agent): string => $agent->name(),
            array_filter($this->agents(), fn (Agent $agent): bool => $agent->detectInProject()),
        ));
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, Agent>
     */
    public function resolve(array $names): array
    {
        $agents = $this->agents();

        return array_values(array_filter(array_map(
            fn (string $name): ?Agent => $agents[$name] ?? null,
            $names,
        )));
    }
}
