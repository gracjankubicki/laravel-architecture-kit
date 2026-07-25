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

        if (! is_array($args)
            || ! array_is_list($args)
            || array_filter($args, fn (mixed $argument): bool => ! is_string($argument)) !== []) {
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
        if (! preg_match('/^\h*args\h*=\h*\[/m', $section, $match, PREG_OFFSET_CAPTURE)) {
            return preg_match('/^\h*args\h*=/m', $section) === 1 ? null : [];
        }

        $offset = $match[0][1] + strlen($match[0][0]);
        $length = strlen($section);
        $arguments = [];

        while ($offset < $length) {
            $this->skipTomlWhitespaceAndComments($section, $offset);

            if ($offset >= $length) {
                return null;
            }

            if ($section[$offset] === ']') {
                return $arguments;
            }

            if (! in_array($section[$offset], ['"', "'"], true)) {
                return null;
            }

            $argument = $this->readTomlString($section, $offset);

            if ($argument === null) {
                return null;
            }

            $arguments[] = $argument;
            $this->skipTomlWhitespaceAndComments($section, $offset);

            if ($offset >= $length) {
                return null;
            }

            if ($section[$offset] === ']') {
                return $arguments;
            }

            if ($section[$offset] !== ',') {
                return null;
            }

            $offset++;
        }

        return null;
    }

    private function skipTomlWhitespaceAndComments(string $section, int &$offset): void
    {
        $length = strlen($section);

        while ($offset < $length) {
            if (ctype_space($section[$offset])) {
                $offset++;

                continue;
            }

            if ($section[$offset] !== '#') {
                return;
            }

            $newline = strpos($section, "\n", $offset);
            $offset = $newline === false ? $length : $newline + 1;
        }
    }

    private function readTomlString(string $section, int &$offset): ?string
    {
        $quote = $section[$offset];
        $start = $offset++;
        $length = strlen($section);

        while ($offset < $length) {
            if ($section[$offset] === "\n" || $section[$offset] === "\r") {
                return null;
            }

            if ($quote === '"' && $section[$offset] === '\\') {
                $offset += 2;

                continue;
            }

            if ($section[$offset] === $quote) {
                $offset++;

                return $this->tomlString(substr($section, $start, $offset - $start));
            }

            $offset++;
        }

        return null;
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
