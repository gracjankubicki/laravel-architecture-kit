<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Generator;
use JsonException;
use stdClass;

/** Serialize a complete JSON response without allocating a second report-sized string. */
final class JsonResponseChunks
{
    /** @param array<string, mixed> $message
     * @return Generator<int, string>
     */
    public static function encode(array $message): Generator
    {
        // Fail before writing any bytes, so an invalid response cannot corrupt
        // the JSON-RPC line used for the subsequent SDK error response.
        self::validate($message, 1);
        yield from self::value($message);
    }

    private static function validate(mixed $value, int $depth): void
    {
        if ($depth > 512) {
            throw new JsonException('Maximum stack depth exceeded');
        }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('//u', $key) !== 1) {
                    throw new JsonException('Malformed UTF-8 key');
                }
                self::validate($item, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new JsonException('Malformed UTF-8 value');
            }
        } else {
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    }

    /** @return Generator<int, string> */
    private static function value(mixed $value): Generator
    {
        if (is_string($value)) {
            yield '"';
            for ($offset = 0, $length = strlen($value); $offset < $length;) {
                $end = min($length, $offset + 65536);
                // Keep UTF-8 code points intact at the chunk boundary.
                while ($end < $length && (ord($value[$end]) & 0xC0) === 0x80) {
                    $end--;
                }
                $chunk = json_encode(substr($value, $offset, $end - $offset), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                yield substr($chunk, 1, -1);
                $offset = $end;
            }
            yield '"';
        } elseif (is_array($value) || $value instanceof stdClass) {
            $list = is_array($value) && array_is_list($value);
            yield $list ? '[' : '{';
            $first = true;
            foreach ($value as $key => $item) {
                if (! $first) {
                    yield ',';
                }
                $first = false;
                if (! $list) {
                    yield json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).':';
                }
                yield from self::value($item);
            }
            yield $list ? ']' : '}';
        } else {
            yield json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    }
}
