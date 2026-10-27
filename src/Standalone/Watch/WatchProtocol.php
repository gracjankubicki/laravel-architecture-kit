<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use RuntimeException;

final class WatchProtocol
{
    public const PROTOCOL = 'architecture-kit-watch-v1';

    public const VERSION = '0.1.0';

    public const FRAME_BYTES = 65536;

    public const PATH_BYTES = 16384;

    public const MAX_SEQUENCE = '9223372036854775807';

    public const CAPABILITIES = ['events-v1', 'continuity-loss-v1'];

    /** @return array<string, mixed> */
    public static function frame(string $body): array
    {
        if (strlen($body) > self::FRAME_BYTES || ! str_ends_with($body, "\n") || substr_count($body, "\n") !== 1) {
            throw new RuntimeException('protocol_error: invalid or oversized frame.');
        }
        $value = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('protocol_error: frame must be an object.');
        }

        // json_decode otherwise silently accepts duplicate object keys. Tokenize complete
        // JSON strings first so escaped quotes inside values cannot masquerade as keys.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $body, $strings, PREG_OFFSET_CAPTURE);
        $seen = [];
        foreach ($strings[0] as [$token, $offset]) {
            if (preg_match('/^\s*:/', substr($body, $offset + strlen($token)))) {
                $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                if (isset($seen[$key])) {
                    throw new RuntimeException('protocol_error: duplicate field.');
                }
                $seen[$key] = true;
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $fields
     */
    public static function fields(array $value, array $fields): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($fields);
        if ($keys !== $fields) {
            throw new RuntimeException('protocol_error: unexpected fields.');
        }
    }

    public static function sequence(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $value)
            || strlen($value) === 19 && strcmp($value, self::MAX_SEQUENCE) > 0) {
            throw new RuntimeException('protocol_error: invalid sequence.');
        }

        return $value;
    }

    public static function next(string $previous): string
    {
        if ($previous === self::MAX_SEQUENCE) {
            throw new RuntimeException('sequence_exhausted: restart and reconcile.');
        }
        // Decimal arithmetic remains exact even on a 32-bit PHP host.
        $digits = str_split($previous);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return implode('', $digits);
            }
            $digits[$i] = '0';
        }

        return '1'.implode('', $digits);
    }

    /** @param array<string, mixed> $value */
    public static function version(array $value, string $platform): void
    {
        self::fields($value, ['runtime_version', 'protocol', 'notify_version', 'platform', 'target', 'linkage', 'capabilities']);
        if (($value['runtime_version'] ?? null) !== self::VERSION || ($value['protocol'] ?? null) !== self::PROTOCOL
            || ($value['notify_version'] ?? null) !== '8.2.1' || ($value['platform'] ?? null) !== $platform
            || ($value['target'] ?? null) !== (RuntimePlatform::TARGETS[$platform] ?? null)
            || ($value['linkage'] ?? null) !== RuntimePlatform::linkage($platform)
            || ($value['capabilities'] ?? null) !== self::CAPABILITIES) {
            throw new RuntimeException('protocol_error: incompatible runtime version.');
        }
    }

    public static function path(string $encoded): string
    {
        $path = base64_decode($encoded, true);
        if ($path === false || base64_encode($path) !== $encoded) {
            throw new RuntimeException('protocol_error: path_encoding.');
        }
        WatchBoundary::absolute($path);

        return $path;
    }
}
