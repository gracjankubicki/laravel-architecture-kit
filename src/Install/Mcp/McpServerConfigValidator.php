<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Mcp;

final readonly class McpServerConfigValidator
{
    /** @param array<string, mixed> $config */
    public function jsonInvokesArchitectureKit(array $config): bool
    {
        if (! is_string($config['command'] ?? null) || trim($config['command']) === '') {
            return false;
        }

        $args = $config['args'] ?? [];

        if (! is_array($args) || array_filter($args, fn (mixed $argument): bool => ! is_string($argument)) !== []) {
            return false;
        }

        return $this->invokes([$config['command'], ...$args]);
    }

    public function tomlInvokesArchitectureKit(string $section): bool
    {
        $command = $this->tomlCommand($section);

        if ($command === null || $command === '') {
            return false;
        }

        $args = $this->tomlArguments($section);

        return $args !== null && $this->invokes([$command, ...$args]);
    }

    /** @param array<int, string> $tokens */
    private function invokes(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (preg_match('/(?<![A-Za-z0-9:_-])architecture-kit:mcp(?![A-Za-z0-9:_-])/', $token) === 1) {
                return true;
            }
        }

        return false;
    }

    private function tomlCommand(string $section): ?string
    {
        if (! preg_match('/^\s*command\s*=\s*("(?:\\\\.|[^"\\\\])*"|\'[^\']*\')\s*(?:#.*)?$/m', $section, $match)) {
            return null;
        }

        return $this->tomlString($match[1]);
    }

    /** @return array<int, string>|null */
    private function tomlArguments(string $section): ?array
    {
        if (! preg_match('/^\h*args\h*=\h*\[(.*?)\]\h*(?:#.*)?$/ms', $section, $match)) {
            return preg_match('/^\h*args\h*=/m', $section) === 1 ? null : [];
        }

        if (trim($match[1]) === '') {
            return [];
        }

        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'[^\']*\'/', $match[1], $strings);
        $arguments = array_map($this->tomlString(...), $strings[0]);

        return in_array(null, $arguments, true) ? null : array_values(array_filter(
            $arguments,
            fn (?string $argument): bool => $argument !== null,
        ));
    }

    private function tomlString(string $value): ?string
    {
        if (str_starts_with($value, "'")) {
            return substr($value, 1, -1);
        }

        $decoded = json_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }
}
