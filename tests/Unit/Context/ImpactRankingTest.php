<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Context;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Context\ImpactRanking;
use PHPUnit\Framework\TestCase;

final class ImpactRankingTest extends TestCase
{
    public function test_inheritance_and_contracts_are_the_most_breaking(): void
    {
        foreach (['extends', 'implements', 'trait'] as $kind) {
            $this->assertSame(ImpactRanking::BREAKING, ImpactRanking::for($this->edge($kind)));
        }
    }

    public function test_a_signature_reference_ranks_below_inheritance(): void
    {
        foreach (['parameter', 'property', 'return'] as $kind) {
            $this->assertSame(ImpactRanking::SIGNATURE, ImpactRanking::for($this->edge($kind)));
        }

        $this->assertLessThan(
            ImpactRanking::rank(ImpactRanking::SIGNATURE),
            ImpactRanking::rank(ImpactRanking::BREAKING),
        );
    }

    public function test_executable_use_ranks_below_a_signature(): void
    {
        foreach (['new', 'static', 'instanceof', 'catch', 'attribute', 'class-constant'] as $kind) {
            $this->assertSame(ImpactRanking::USAGE, ImpactRanking::for($this->edge($kind)));
        }

        $this->assertLessThan(
            ImpactRanking::rank(ImpactRanking::USAGE),
            ImpactRanking::rank(ImpactRanking::SIGNATURE),
        );
    }

    public function test_a_weak_edge_is_context_whatever_its_kind(): void
    {
        // `SomeClass::class` and Eloquent relation targets are readable references that
        // do not break when the target changes, so they must never outrank real use.
        $this->assertSame(ImpactRanking::CONTEXT, ImpactRanking::for($this->edge('extends', strong: false)));
        $this->assertSame(ImpactRanking::CONTEXT, ImpactRanking::for($this->edge('eloquent-relation', strong: false)));
    }

    public function test_an_unknown_kind_falls_back_to_context(): void
    {
        // A new edge kind must not silently outrank inheritance before anyone ranks it.
        $this->assertSame(ImpactRanking::CONTEXT, ImpactRanking::for($this->edge('something-new')));
    }

    /**
     * The ranking restates knowledge that lives in the graph builder, so it drifts the
     * moment a new edge kind is added there. `class-constant` was already missing when
     * this guard was written: a strong executable reference was being ranked as context
     * and could be cut before a weak one.
     */
    public function test_every_kind_the_graph_emits_is_classified(): void
    {
        $emitted = $this->emittedKinds();

        $this->assertSame(
            [],
            array_values(array_diff($emitted, ImpactRanking::classifiedKinds())),
            'These edge kinds are recorded by the graph but carry no impact level, so they would be ranked as context by default.',
        );
    }

    public function test_the_ranking_classifies_no_kind_the_graph_never_emits(): void
    {
        // The other direction of the same drift: a kind that was renamed or dropped in
        // the builder would linger here and quietly suggest coverage that does not exist.
        $this->assertSame(
            [],
            array_values(array_diff(ImpactRanking::classifiedKinds(), $this->emittedKinds())),
            'These kinds are classified but no longer emitted by the graph.',
        );
    }

    public function test_no_kind_is_classified_twice(): void
    {
        $classified = ImpactRanking::classifiedKinds();

        $this->assertSame(
            array_values(array_unique($classified)),
            $classified,
            'A kind listed in two categories makes its level depend on the order the match is tried.',
        );
    }

    /**
     * Kinds the builder passes to the graph, read from its source.
     *
     * Two call shapes carry a kind, and the first version of this guard only knew one of
     * them: `addName(..., 'kind', true|false)` and `addType(..., 'kind')`. That blind spot
     * hid `parameter`, `property` and `return`, so removing any of them from the ranking
     * would still have passed.
     *
     * @return array<int, string>
     */
    private function emittedKinds(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Audit/ProjectGraph/ProjectGraphBuilder.php',
        );
        $kinds = [];

        foreach (["/addName\([^;]*?'([a-z][a-z-]*)',\s*(?:true|false)\)/s", "/addType\([^;]*?'([a-z][a-z-]*)'\)/s"] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            array_push($kinds, ...$matches[1]);
        }

        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        // Anchors proving both call shapes were matched. Without them a broken pattern
        // would report an empty difference and look like a passing guard.
        $this->assertContains('extends', $kinds, 'The guard is not reading addName() calls.');
        $this->assertContains('parameter', $kinds, 'The guard is not reading addType() calls.');

        return $kinds;
    }

    public function test_every_level_has_a_distinct_rank(): void
    {
        $ranks = array_map(ImpactRanking::rank(...), ImpactRanking::levels());

        $this->assertSame($ranks, array_unique($ranks));
        $this->assertSame($ranks, array_values(array_filter($ranks, 'is_int')));
    }

    private function edge(string $kind, bool $strong = true): DependencyEdge
    {
        return new DependencyEdge('App\\A', 'App\\B', 'app/A.php', 10, $kind, $strong);
    }
}
