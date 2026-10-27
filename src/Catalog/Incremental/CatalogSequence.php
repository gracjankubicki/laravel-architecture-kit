<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;

/** Exact adapter to the observer's decimal-string watermark; zero means before observation. */
final class CatalogSequence
{
    public static function validate(mixed $value): string
    {
        return $value === '0' ? '0' : WatchProtocol::sequence($value);
    }

    public static function next(string $previous): string
    {
        self::validate($previous);

        return $previous === '0' ? '1' : WatchProtocol::next($previous);
    }

    public static function compare(string $left, string $right): int
    {
        self::validate($left);
        self::validate($right);

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
