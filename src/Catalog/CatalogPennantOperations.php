<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

final class CatalogPennantOperations
{
    public const READS = ['value', 'values', 'active', 'inactive', 'allareactive', 'someareactive', 'allareinactive', 'someareinactive', 'load', 'loadmissing', 'when', 'unless'];

    public const WRITES = ['activate', 'deactivate', 'forget', 'activateforeveryone', 'deactivateforeveryone', 'purge'];

    public static function name(string $name): bool
    {
        return preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.:\\\\-]{0,499}\z/D', $name) === 1;
    }

    /** @param array<string, mixed> $metadata */
    public static function validate(array $metadata): void
    {
        if (array_keys($metadata) !== ['method', 'features', 'store', 'scope_supplied', 'class_definition', 'callbacks', 'callbacks_resolved', 'resolved']
            || ! in_array($metadata['method'], ['define', ...self::READS, ...self::WRITES], true)
            || ! is_array($metadata['features']) || ! array_is_list($metadata['features']) || count($metadata['features']) > 128
            || $metadata['store'] !== null && (! is_string($metadata['store']) || ! self::name($metadata['store']))
            || ! is_bool($metadata['scope_supplied']) || ! is_bool($metadata['class_definition'])
            || ! is_array($metadata['callbacks']) || ! array_is_list($metadata['callbacks']) || count($metadata['callbacks']) > 3
            || ! is_bool($metadata['callbacks_resolved']) || ! is_bool($metadata['resolved'])) {
            throw new InvalidArgumentException('Invalid Pennant operation.');
        }
        foreach ($metadata['features'] as $feature) {
            if (! is_array($feature) || array_keys($feature) !== ['name', 'class'] || ! is_string($feature['name']) || ! self::name($feature['name']) || ! is_bool($feature['class'])) {
                throw new InvalidArgumentException('Invalid Pennant feature selector.');
            }
        }
        foreach ($metadata['callbacks'] as $callback) {
            if (! is_array($callback) || array_keys($callback) !== ['target', 'condition'] || ! is_string($callback['target'])
                || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $callback['target']) !== 1 || ! in_array($callback['condition'], ['resolver', 'active', 'inactive'], true)) {
                throw new InvalidArgumentException('Invalid Pennant callback.');
            }
        }
    }
}
