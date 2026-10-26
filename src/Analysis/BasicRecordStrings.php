<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

/** Bounded sharing while decoding one batch, without retaining source facts in a session. */
final class BasicRecordStrings
{
    public const MAX_STRINGS = 16384;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> */
    private array $strings = [];

    private int $bytes = 0;

    public function share(string $value): string
    {
        if (isset($this->strings[$value])) {
            return $this->strings[$value];
        }
        $bytes = strlen($value);
        if ($bytes <= 1024 && count($this->strings) < self::MAX_STRINGS && $this->bytes + $bytes <= self::MAX_BYTES) {
            $this->strings[$value] = $value;
            $this->bytes += $bytes;
        }

        return $value;
    }
}
