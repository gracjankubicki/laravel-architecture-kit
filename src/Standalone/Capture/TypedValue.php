<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use InvalidArgumentException;

/** Lossless PHP value wire: arrays carry ordered, typed keys; floats stay floats. */
final class TypedValue
{
    /**
     * @return array<array-key, mixed>
     */
    public static function encode(mixed $value, int $depth = 0): array
    {
        if ($depth > 128) {
            throw new InvalidArgumentException('capture_value_depth');
        }
        if (is_array($value)) {
            $entries = [];
            foreach ($value as $key => $item) {
                $entries[] = [is_int($key) ? 'int' : 'string', $key, self::encode($item, $depth + 1)];
            }

            return [array_is_list($value) ? 'list' : 'map', $entries];
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('capture_nonfinite_float');
        }
        $type = match (true) {
            $value === null => 'null', is_bool($value) => 'bool', is_int($value) => 'int',
            is_float($value) => 'float', is_string($value) => 'string',
            default => throw new InvalidArgumentException('capture_noncanonical_value'),
        };

        return [$type, $value];
    }

    public static function decode(mixed $wire, int $depth = 0): mixed
    {
        if ($depth > 128 || ! is_array($wire) || ! array_is_list($wire) || count($wire) !== 2) {
            throw new InvalidArgumentException('capture_value_shape');
        }
        [$type, $value] = $wire;
        if (in_array($type, ['list', 'map'], true)) {
            if (! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException('capture_array_shape');
            }
            $result = [];
            foreach ($value as $item) {
                if (! is_array($item) || ! array_is_list($item) || count($item) !== 3
                    || ! (($item[0] === 'int' && is_int($item[1])) || ($item[0] === 'string' && is_string($item[1])))
                    || array_key_exists($item[1], $result)) {
                    throw new InvalidArgumentException('capture_key_shape');
                }
                $result[$item[1]] = self::decode($item[2], $depth + 1);
                if (array_key_last($result) !== $item[1]) {
                    throw new InvalidArgumentException('capture_coerced_key');
                }
            }
            if (array_is_list($result) !== ($type === 'list')) {
                throw new InvalidArgumentException('capture_array_tag');
            }

            return $result;
        }
        $valid = match ($type) {
            'null' => $value === null, 'bool' => is_bool($value), 'int' => is_int($value),
            'float' => is_float($value) && is_finite($value), 'string' => is_string($value), default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('capture_scalar_tag');
        }

        return $value;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES, 512);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }
}
