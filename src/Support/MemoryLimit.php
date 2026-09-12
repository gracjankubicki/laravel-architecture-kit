<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Support;

/**
 * The process memory ceiling, in bytes, or null when there is none.
 *
 * The audit has always refused to start work it cannot finish rather than dying on an
 * allocation. The graph cache needs the same number for the same reason, so the two read
 * it the same way instead of each having an opinion about what `memory_limit` means.
 */
final readonly class MemoryLimit
{
    public static function bytes(?int $override = null): ?int
    {
        if ($override !== null) {
            return $override;
        }

        $limit = ini_get('memory_limit');

        if ($limit === false || trim($limit) === '' || trim($limit) === '-1') {
            return null;
        }

        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]?)\s*$/i', $limit, $matches)) {
            return null;
        }

        $multipliers = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];

        return (int) round((float) $matches[1] * $multipliers[strtolower($matches[2])]);
    }
}
