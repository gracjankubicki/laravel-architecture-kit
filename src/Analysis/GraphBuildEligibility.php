<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

/** Build-local observations. Never starts a session, loads project PHP or retains facts. */
final class GraphBuildEligibility
{
    private static ?self $active = null;

    /** @var array<string, true> */
    private array $reasons = [];

    private bool $closed = false;

    private function __construct(private readonly ?self $parent) {}

    public static function begin(): self
    {
        return self::$active = new self(self::$active);
    }

    public static function report(AnalysisLimitReason $reason): void
    {
        if (self::$active !== null) {
            self::$active->reasons[$reason->value] = true;
        }
    }

    /** @return array{v: int, cacheable: bool, reasons: list<string>} */
    public static function current(): array
    {
        return self::$active?->snapshot() ?? ['v' => 1, 'cacheable' => false, 'reasons' => ['unknown']];
    }

    /** @return array{v: int, cacheable: bool, reasons: list<string>} */
    public function snapshot(): array
    {
        $reasons = array_keys($this->reasons);
        sort($reasons, SORT_STRING);

        return ['v' => 1, 'cacheable' => ! isset($this->reasons['memory']) && ! isset($this->reasons['unknown']), 'reasons' => $reasons];
    }

    public function close(bool $complete = true): void
    {
        if ($this->closed) {
            return;
        }
        if (self::$active !== $this) {
            throw new \LogicException('Eligibility scopes must close in reverse order.');
        }
        if (! $complete) {
            $this->reasons['unknown'] = true;
        }
        if (self::$active === $this) {
            self::$active = $this->parent;
        }
        $this->closed = true;
    }

    /** @return string|null Null is a valid, reusable private policy. */
    public static function rejection(mixed $data): ?string
    {
        if (! is_array($data) || array_keys($data) !== ['v', 'cacheable', 'reasons'] || $data['v'] !== 1
            || ! is_bool($data['cacheable']) || ! is_array($data['reasons']) || ! array_is_list($data['reasons'])
            || count($data['reasons']) > 3 || count(array_unique($data['reasons'], SORT_REGULAR)) !== count($data['reasons'])) {
            return 'eligibility_missing_or_invalid';
        }
        foreach ($data['reasons'] as $reason) {
            if (! is_string($reason) || AnalysisLimitReason::tryFrom($reason) === null) {
                return 'eligibility_missing_or_invalid';
            }
        }
        $expected = ! in_array('memory', $data['reasons'], true) && ! in_array('unknown', $data['reasons'], true);
        if ($data['cacheable'] !== $expected) {
            return 'eligibility_missing_or_invalid';
        }

        return $expected ? null : (in_array('memory', $data['reasons'], true) ? 'analysis_memory' : 'analysis_unknown');
    }
}
