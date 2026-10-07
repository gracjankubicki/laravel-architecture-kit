<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

final class CatalogHorizonOptions
{
    public const NUMERIC = ['minProcesses', 'maxProcesses', 'maxTime', 'maxJobs', 'memory', 'tries', 'timeout', 'nice', 'balanceMaxShift', 'balanceCooldown'];

    public const FIELDS = ['connection', 'queue', 'balance', 'autoScalingStrategy', ...self::NUMERIC];

    public static function name(string $name, bool $environment = false): bool
    {
        return preg_match($environment ? '/\A[a-zA-Z0-9_*][a-zA-Z0-9_.*:\-]{0,127}\z/D' : '/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,127}\z/D', $name) === 1;
    }

    /** @param array<string, mixed> $metadata */
    public static function validate(array $metadata): void
    {
        if (array_keys($metadata) !== ['section', 'environment', 'supervisor', 'options', 'unresolved_fields']
            || ! in_array($metadata['section'], ['defaults', 'environments'], true) || ! is_string($metadata['supervisor']) || ! self::name($metadata['supervisor'])
            || $metadata['environment'] !== null && (! is_string($metadata['environment']) || ! self::name($metadata['environment'], true))
            || ($metadata['section'] === 'defaults') !== ($metadata['environment'] === null)
            || ! is_array($metadata['options']) || count($metadata['options']) > count(self::FIELDS)
            || ! is_array($metadata['unresolved_fields']) || ! array_is_list($metadata['unresolved_fields']) || count($metadata['unresolved_fields']) > count(self::FIELDS) + 1) {
            throw new InvalidArgumentException('Invalid Horizon source definition.');
        }
        foreach ($metadata['options'] as $field => $value) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Unknown Horizon option.');
            }
            if ($value === null) {
                continue;
            }
            if ($field === 'balance' && $value === false) {
                continue;
            }
            if ($field === 'queue' && is_array($value)) {
                if (! array_is_list($value) || count($value) > 128 || count(array_filter($value, fn ($name) => is_string($name) && self::name($name))) !== count($value)) {
                    throw new InvalidArgumentException('Invalid Horizon queue list.');
                }
            } elseif (in_array($field, self::NUMERIC, true)) {
                if (! is_int($value) || $value < ($field === 'nice' ? -20 : 0) || $value > 2147483647) {
                    throw new InvalidArgumentException('Invalid Horizon numeric option.');
                }
            } elseif (! is_string($value) || ($field === 'queue' ? count(explode(',', $value)) > 128 || count(array_filter(explode(',', $value), fn ($name) => self::name($name))) !== count(explode(',', $value)) : ! self::name($value))) {
                throw new InvalidArgumentException('Invalid Horizon option selector.');
            }
        }
        foreach ($metadata['unresolved_fields'] as $field) {
            if (! in_array($field, ['definition', ...self::FIELDS], true)) {
                throw new InvalidArgumentException('Invalid unresolved Horizon option.');
            }
        }
    }
}
