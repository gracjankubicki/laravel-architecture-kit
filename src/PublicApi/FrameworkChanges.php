<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

final class FrameworkChanges
{
    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{string, list<string>} */
    public static function changed(array $before, array $after): array
    {
        $breaking = $checks = [];
        if ($before['kind'] === 'command') {
            foreach ($before['arguments'] as $i => $argument) {
                $actual = $after['arguments'][$i] ?? null;
                if ($actual === null || $actual['name'] !== $argument['name'] || $actual['array'] !== $argument['array']) {
                    $breaking[] = 'Command argument removed, renamed, reordered or changed array mode: '.$argument['name'].'.';
                } elseif (! $argument['required'] && $actual['required']) {
                    $breaking[] = 'Command argument became required: '.$argument['name'].'.';
                } elseif ($actual['default'] !== $argument['default']) {
                    $checks[] = 'Command argument default changed: '.$argument['name'].'.';
                }
            }
            foreach ($after['arguments'] as $i => $argument) {
                if (! isset($before['arguments'][$i]) && $argument['required']) {
                    $breaking[] = 'Required command argument added: '.$argument['name'].'.';
                }
            }
            $options = array_column($after['options'], null, 'name');
            foreach ($before['options'] as $option) {
                $actual = $options[$option['name']] ?? null;
                if ($actual === null) {
                    $breaking[] = 'Command option removed: '.$option['name'].'.';
                } elseif (array_diff_key($actual, ['default' => true]) !== array_diff_key($option, ['default' => true])) {
                    $breaking[] = 'Command option invocation changed: '.$option['name'].'.';
                } elseif ($actual['default'] !== $option['default']) {
                    $checks[] = 'Command option default changed: '.$option['name'].'.';
                }
            }
        } elseif ($before['kind'] === 'mcp') {
            if (! is_array($before['input']) || ! is_array($after['input'])) {
                $checks[] = 'Dynamic MCP input schema changed.';
            } else {
                foreach ($before['input'] as $name => $descriptor) {
                    if (! isset($after['input'][$name])) {
                        $breaking[] = 'MCP parameter removed: '.$name.'.';
                    } elseif ($descriptor !== $after['input'][$name]) {
                        if (! self::required($descriptor) && self::required($after['input'][$name])) {
                            $breaking[] = 'MCP parameter became required: '.$name.'.';
                        } else {
                            $checks[] = 'MCP parameter constraints changed: '.$name.'.';
                        }
                    }
                }
                foreach ($after['input'] as $name => $descriptor) {
                    if (! isset($before['input'][$name]) && self::required($descriptor)) {
                        $breaking[] = 'Required MCP parameter added: '.$name.'.';
                    }
                }
            }
            foreach ([...($before['input'] ?? []), ...($after['input'] ?? [])] as $descriptor) {
                if (($descriptor['form'] ?? '') === 'unresolved' || ($descriptor['known'] ?? true) === false) {
                    $checks[] = 'An MCP schema expression is unresolved; compatible extension is not proved.';
                }
            }
            if ($before['output'] !== $after['output']) {
                $checks[] = 'Declared MCP response schema changed; inspect consumer promises.';
            }
        } else {
            $checks[] = match ($before['kind']) {
                'published_migration' => 'Published migration changed; existing installations and database behaviour require inspection.',
                'published_config' => 'Published configuration keys or values changed; inspect consumer configuration.',
                'event' => 'Event identity or payload changed; inspect producers and listeners.',
                default => 'Published file changed; inspect consumer usage.',
            };
        }
        if ($breaking !== []) {
            return ['breaking', array_values(array_unique([...$breaking, ...$checks]))];
        }
        if ($checks !== []) {
            return ['check', $checks];
        }

        return ['compatible', ['Recognized command or MCP input contract was extended without a proved incompatibility.']];
    }

    /** @param array<string, mixed> $descriptor */
    private static function required(array $descriptor): bool
    {
        foreach ($descriptor['chain'] ?? [] as $call) {
            if ($call['method'] === 'required' && (! isset($call['arguments'][0]) || ($call['arguments'][0]['known'] && $call['arguments'][0]['value'] === true))) {
                return true;
            }
        }

        return false;
    }
}
