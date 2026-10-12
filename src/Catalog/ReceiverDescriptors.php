<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Decode one receiver link at a time; never expand the whole chain. */
final class ReceiverDescriptors
{
    public const PREFIX = '@descriptor:';

    /** @param array<string, string> $receivers
     * @return array{string, string}|null
     */
    public static function property(string $receiver, array $receivers = []): ?array
    {
        $pair = substr($receiver, 10);
        $separator = strrpos($pair, '#');
        if ($separator === false) {
            return null;
        }
        $owner = substr($pair, 0, $separator);
        if (str_starts_with($owner, self::PREFIX)) {
            $owner = $receivers[substr($owner, strlen(self::PREFIX))] ?? null;
        }

        return $owner === null ? null : [$owner, substr($pair, $separator + 1)];
    }

    /** @param array<string, string> $receivers
     * @return array<mixed>|null
     */
    public static function decode(string $json, array $receivers = []): ?array
    {
        $value = json_decode($json, true, 32);
        if (! is_array($value)) {
            return null;
        }
        // Return/callable descriptors have one receiver; argument flow has two.
        foreach (count($value) === 7 ? [0, 1] : [0] as $position) {
            if (is_string($value[$position] ?? null) && str_starts_with($value[$position], self::PREFIX)) {
                $receiver = $receivers[substr($value[$position], strlen(self::PREFIX))] ?? null;
                if ($receiver === null) {
                    return null;
                }
                $value[$position] = $receiver;
            }
        }

        return $value;
    }
}
