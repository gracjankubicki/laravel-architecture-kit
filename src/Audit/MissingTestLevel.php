<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/**
 * How strictly a project wants the audit to treat an architecture element that no test
 * depends on.
 *
 * The rule is off unless a project asks for it. Measured on a mature application with
 * 6305 files under app/ and 5153 test files, enabling it by default would have produced
 * roughly 700 findings on the first run after an upgrade, which would change a gate
 * result without anyone deciding to.
 */
enum MissingTestLevel: string
{
    case Off = 'off';
    case Warn = 'warn';
    case Error = 'error';

    public function isEnabled(): bool
    {
        return $this !== self::Off;
    }

    /**
     * Severity of the findings this level produces. Off has none, so asking for it is a
     * programming error rather than a configuration one.
     */
    public function severity(): string
    {
        return match ($this) {
            self::Off => throw new \LogicException('MissingTestLevel::Off produces no findings, so it has no severity.'),
            self::Warn => 'warn',
            self::Error => 'error',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
