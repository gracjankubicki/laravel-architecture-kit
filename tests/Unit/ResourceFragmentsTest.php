<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Taqie\ArchitectureKit\Resources\ResourceFragments;

class ResourceFragmentsTest extends TestCase
{
    public function test_it_selects_enabled_when_variant_over_default(): void
    {
        $selected = (new ResourceFragments)->select(['saloon'], [
            '10-http.default.md',
            '10-http.when-saloon.md',
        ]);

        $this->assertSame(['10-http.when-saloon.md'], $selected);
    }

    public function test_it_selects_default_when_no_when_variant_matches(): void
    {
        $selected = (new ResourceFragments)->select([], [
            '10-http.default.md',
            '10-http.when-saloon.md',
        ]);

        $this->assertSame(['10-http.default.md'], $selected);
    }

    public function test_it_omits_group_without_default_when_condition_does_not_match(): void
    {
        $selected = (new ResourceFragments)->select(['saloon'], [
            '10-actions-only.when-actions.md',
        ]);

        $this->assertSame([], $selected);
    }

    public function test_later_guideline_order_slug_wins_when_multiple_when_variants_match(): void
    {
        $selected = (new ResourceFragments)->select(['actions', 'eloquent-lifecycle'], [
            '10-dispatch.when-actions.md',
            '10-dispatch.when-eloquent-lifecycle.md',
        ]);

        $this->assertSame(['10-dispatch.when-eloquent-lifecycle.md'], $selected);
    }

    public function test_it_rejects_unknown_when_slug(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('references unknown architecture slug [missing-architecture]');

        (new ResourceFragments)->select([], [
            '10-http.when-missing-architecture.md',
        ]);
    }

    public function test_when_default_selection_architecture_uses_real_enabled_set(): void
    {
        $selected = (new ResourceFragments)->select(['actions'], [
            '10-boundary.default.md',
            '10-boundary.when-actions.md',
        ]);

        $this->assertSame(['10-boundary.when-actions.md'], $selected);
    }

    public function test_it_sorts_fragments_by_numeric_order_prefix(): void
    {
        $selected = (new ResourceFragments)->select([], [
            '10-later.md',
            '2-earlier.md',
        ]);

        $this->assertSame(['2-earlier.md', '10-later.md'], $selected);
    }

    public function test_it_rejects_fragments_without_order_prefix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must match <order>-<key>[.default|.when-<slug>].md');

        (new ResourceFragments)->select([], [
            'http.default.md',
        ]);
    }
}
