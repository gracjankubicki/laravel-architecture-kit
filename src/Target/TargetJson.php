<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use InvalidArgumentException;

/** JSON's last-key-wins behavior must not silently discard a target declaration. */
final readonly class TargetJson
{
    public static function decode(string $source): mixed
    {
        $data = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]/s', $source, $matches);
        $stack = [];
        foreach ($matches[0] as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'keys' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($matches[0][$index + 1] ?? null) === ':' && $stack !== []) {
                $top = count($stack) - 1;
                $key = json_decode($token, true, flags: JSON_THROW_ON_ERROR);
                if (isset($stack[$top]['keys'][$key])) {
                    throw new InvalidArgumentException('Duplicate JSON declaration key: '.$key);
                }
                $stack[$top]['keys'][$key] = true;
            }
        }

        return $data;
    }
}
