<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

final class CatalogInertiaOperations
{
    public const MODES = ['ordinary', 'optional', 'defer', 'once', 'always', 'merge', 'deepmerge'];

    /** @param array<string, mixed> $metadata */
    public static function validate(array $metadata): void
    {
        if (array_keys($metadata) !== ['package', 'method', 'form', 'receiver', 'functions', 'selector', 'callbacks', 'resolved', 'callbacks_resolved', 'execution_proven']
            || $metadata['package'] !== 'inertiajs/inertia-laravel' || ! in_array($metadata['method'], ['render', 'share', 'shareonce'], true)
            || ! in_array($metadata['form'], ['static', 'helper', 'method'], true) || ! is_string($metadata['receiver']) || $metadata['receiver'] === '' || strlen($metadata['receiver']) > 1000
            || ! is_array($metadata['functions']) || ! array_is_list($metadata['functions']) || count($metadata['functions']) > 2
            || $metadata['selector'] !== null && (! is_string($metadata['selector']) || preg_match('/\A[a-zA-Z0-9_\-][a-zA-Z0-9_.\/\-]{0,255}\z/D', $metadata['selector']) !== 1 || in_array('..', explode('/', $metadata['selector']), true))
            || ! is_array($metadata['callbacks']) || ! array_is_list($metadata['callbacks']) || count($metadata['callbacks']) > 128
            || ! is_bool($metadata['resolved']) || ! is_bool($metadata['callbacks_resolved']) || $metadata['execution_proven'] !== false) {
            throw new InvalidArgumentException('Invalid Inertia operation.');
        }
        foreach ($metadata['functions'] as $function) {
            if (! is_string($function) || $function === '' || strlen($function) > 1000) {
                throw new InvalidArgumentException('Invalid Inertia helper candidate.');
            }
        }
        foreach ($metadata['callbacks'] as $callback) {
            if (! is_array($callback) || array_keys($callback) !== ['target', 'mode', 'line', 'end_line']
                || ! is_string($callback['target']) || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $callback['target']) !== 1
                || ! in_array($callback['mode'], self::MODES, true) || ! is_int($callback['line']) || $callback['line'] < 1
                || ! is_int($callback['end_line']) || $callback['end_line'] < $callback['line']) {
                throw new InvalidArgumentException('Invalid Inertia callback.');
            }
        }
    }
}
