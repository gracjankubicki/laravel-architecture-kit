<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Hooks;

use GracjanKubicki\ArchitectureKit\Install\Agents\Agent;
use GracjanKubicki\ArchitectureKit\Install\Contracts\SupportsHooks;
use GracjanKubicki\ArchitectureKit\Install\InstallResult;
use GracjanKubicki\ArchitectureKit\Install\RuntimeResolver;
use Illuminate\Filesystem\Filesystem;

final readonly class HookWriter
{
    private const MARKER = 'Bootstrapped by Laravel Architecture Kit. Developer-owned after creation.';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private RuntimeResolver $runtime = new RuntimeResolver,
    ) {}

    /**
     * @param  array<int, Agent&SupportsHooks>  $agents
     */
    public function plan(array $agents): InstallResult
    {
        $creates = [];
        $updates = [];
        $blocked = [];

        foreach ($this->targets($agents) as $path => $contents) {
            if ($contents === null) {
                $blocked[] = $path;

                continue;
            }

            $absolutePath = $this->absolute($path);

            if (! $this->files->exists($absolutePath)) {
                $creates[] = $path;

                continue;
            }

            if ($this->files->get($absolutePath) !== $contents) {
                $updates[] = $path;
            }
        }

        return new InstallResult($creates, $updates, $blocked);
    }

    /**
     * @param  array<int, Agent&SupportsHooks>  $agents
     */
    public function write(array $agents): void
    {
        foreach ($this->targets($agents) as $path => $contents) {
            if ($contents === null) {
                continue;
            }

            $absolutePath = $this->absolute($path);

            if ($this->files->exists($absolutePath) && $this->files->get($absolutePath) === $contents) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($absolutePath));
            $this->files->put($absolutePath, $contents);

            if ($path === '.architecture-kit/hooks/guard.sh') {
                $this->files->chmod($absolutePath, 0755);
            }
        }
    }

    /**
     * @param  array<int, Agent&SupportsHooks>  $agents
     * @return array<string, string|null>
     */
    private function targets(array $agents): array
    {
        $targets = [
            '.architecture-kit/hooks/guard.sh' => $this->existingOrDefault(
                '.architecture-kit/hooks/guard.sh',
                $this->guardScript(),
            ),
            '.architecture-kit/hooks/README.md' => $this->existingOrDefault(
                '.architecture-kit/hooks/README.md',
                $this->readme(),
            ),
        ];

        foreach ($agents as $agent) {
            $targets[$agent->hookConfigPath()] = $this->renderHookConfig($agent);
        }

        return $targets;
    }

    private function renderHookConfig(Agent&SupportsHooks $agent): ?string
    {
        $path = $this->absolute($agent->hookConfigPath());
        $decoded = [];
        $content = null;

        if ($this->files->exists($path)) {
            $content = $this->files->get($path);
            $decoded = json_decode($content, true);

            if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
        }

        $hooks = $decoded['hooks'] ?? [];

        if (! is_array($hooks)) {
            return null;
        }

        $stopGroups = $hooks['Stop'] ?? [];

        if (! is_array($stopGroups)) {
            return null;
        }

        if ($this->hasArchitectureKitHook($stopGroups)) {
            return $content;
        }

        $stopGroups[] = [
            'hooks' => [
                [
                    'type' => 'command',
                    'command' => $this->commandFor($agent),
                    'timeout' => 120,
                    'statusMessage' => self::MARKER,
                ],
            ],
        ];

        $hooks['Stop'] = $stopGroups;
        $decoded['hooks'] = $hooks;

        return (json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')."\n";
    }

    /**
     * @param  array<int, mixed>  $groups
     */
    private function hasArchitectureKitHook(array $groups): bool
    {
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $hooks = $group['hooks'] ?? [];

            if (! is_array($hooks)) {
                continue;
            }

            foreach ($hooks as $hook) {
                if ($this->isArchitectureKitHook($hook)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isArchitectureKitHook(mixed $hook): bool
    {
        if (! is_array($hook)) {
            return false;
        }

        $command = $hook['command'] ?? '';
        $message = $hook['statusMessage'] ?? '';

        return (is_string($command) && str_contains($command, '.architecture-kit/hooks/guard.sh'))
            || (is_string($message) && str_contains($message, 'Laravel Architecture Kit'));
    }

    private function commandFor(Agent&SupportsHooks $agent): string
    {
        if ($agent->hookMode() === 'claude') {
            return (new ClaudeHookWriter)->command();
        }

        return (new CodexHookWriter)->command();
    }

    private function guardScript(): string
    {
        $runner = $this->guardRunner();
        $invocation = $runner['invocation'];
        $label = $runner['label'];

        return <<<SH
#!/usr/bin/env bash
# Bootstrapped by Laravel Architecture Kit. Developer-owned after creation.
set -u

MODE="\${1:-codex}"
ROOT="\$(git rev-parse --show-toplevel 2>/dev/null || pwd)"

cd "\$ROOT" || exit 1

RUNTIME_LABEL="$label"
$invocation
STATUS=\$?

if [ "\$STATUS" -eq 0 ]; then
    exit 0
fi

if ! printf '%s' "\$OUTPUT" | grep -q '"ok"'; then
    echo "architecture-kit: runtime unavailable (\$RUNTIME_LABEL failed)." >&2
    echo "Start the project runtime and retry, e.g. docker compose up -d." >&2
fi

printf '%s\\n' "\$OUTPUT" >&2

if [ "\$MODE" = "claude" ]; then
    exit 2
fi

exit "\$STATUS"
SH;
    }

    private function readme(): string
    {
        return <<<'MD'
# Architecture Kit Hooks

Bootstrapped by Laravel Architecture Kit. These files are developer-owned after creation.

Edit them when the project needs a different runtime or guard behavior. Re-running
`php artisan architecture-kit:install-agents` preserves existing files.
MD;
    }

    private function existingOrDefault(string $path, string $default): string
    {
        $absolutePath = $this->absolute($path);

        return $this->files->exists($absolutePath)
            ? $this->files->get($absolutePath)
            : $default;
    }

    private function absolute(string $path): string
    {
        return $this->basePath.'/'.$path;
    }

    /**
     * @return array{label: string, invocation: string}
     */
    private function guardRunner(): array
    {
        return [
            'label' => addcslashes(implode(' ', $this->runtime->commandPrefix()), '"\\$`'),
            'invocation' => $this->runtime->shellArray('RUNNER')."\n".'OUTPUT="$("${RUNNER[@]}" artisan architecture-kit:guard --changed --strict --json 2>&1)"',
        ];
    }
}
