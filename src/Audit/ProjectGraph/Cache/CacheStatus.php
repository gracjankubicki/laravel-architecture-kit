<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

/**
 * Why a run did or did not answer from the stored graph.
 *
 * A rebuild after a rejected entry produces the right answer, so nothing fails, but the
 * run takes twenty seconds instead of one and the reason is invisible. That is the
 * difference between a cache that is off and a cache that is quietly broken, and an agent
 * waiting on the command has no other way to tell them apart.
 */
enum CacheStatus: string
{
    case Disabled = 'disabled';

    case Missing = 'missing';

    case Fresh = 'fresh';

    /** Written under a different configuration or package version. */
    case Incompatible = 'incompatible';

    /** Present but not readable as a stored graph. */
    case Corrupt = 'corrupt';

    /** Readable, but too large to restore inside the remaining memory budget. */
    case TooLarge = 'too-large';

    /**
     * Whether the project should be told. A miss on a first run is ordinary; the rest
     * mean a rebuild that the project may be paying for on every single run.
     */
    public function isNoteworthy(): bool
    {
        return $this === self::Corrupt || $this === self::TooLarge;
    }

    public function note(): ?string
    {
        return match ($this) {
            self::Corrupt => 'The stored project graph could not be read and was rebuilt. Run architecture-kit:cache-clear if this repeats.',
            self::TooLarge => 'The stored project graph did not fit in the remaining memory budget and was rebuilt. Raise PHP memory_limit or narrow the audited scope.',
            default => null,
        };
    }
}
