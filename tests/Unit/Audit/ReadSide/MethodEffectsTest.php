<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\MethodEffects;
use PHPUnit\Framework\TestCase;

final class MethodEffectsTest extends TestCase
{
    public function test_later_effect_does_not_replace_an_earlier_write_after_unknowns(): void
    {
        $effects = new MethodEffects;
        for ($i = 0; $i < 20; $i++) {
            $effects->addAt('unknown', 'probe.php', $i + 1, 'unknown', ['Probe::run']);
        }
        $effects->addAt('write', 'probe.php', 21, 'Model::save()', ['Probe::run']);
        $effects->addAt('effect', 'probe.php', 22, 'dispatch()', ['Probe::run']);

        $concrete = array_values(array_filter($effects->observations, fn ($row) => $row['kind'] !== 'unknown'));
        $this->assertSame(['write', 'effect'], array_column($concrete, 'kind'));
        $this->assertSame([21, 22], array_column($concrete, 'line'));
        $this->assertSame(['Probe::run'], $concrete[0]['trace']);
        $this->assertCount(20, $effects->observations);
        $this->assertSame(22, $effects->totalObservations);
        $this->assertTrue($effects->truncated);
    }

    public function test_exhausted_concrete_budget_preserves_existing_witnesses_and_reports_truncation(): void
    {
        $effects = new MethodEffects;
        for ($i = 0; $i < 20; $i++) {
            $effects->addAt($i % 2 === 0 ? 'write' : 'effect', 'probe.php', $i + 1, 'concrete', []);
        }
        $before = $effects->observations;
        $this->assertFalse($effects->truncated);
        $effects->addAt('write', 'probe.php', 21, 'later write', []);
        $effects->addAt('unknown', 'probe.php', 22, 'later unknown', []);
        $this->assertSame($before, $effects->observations);
        $this->assertSame(22, $effects->totalObservations);
        $this->assertTrue($effects->truncated);
    }
}
