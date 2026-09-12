<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules;

use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\MissingTestRule;
use PHPUnit\Framework\TestCase;

/**
 * The rule walks the graph transitively, so it has to index outgoing edges instead of
 * asking the snapshot per symbol. Resolving each hop against the full edge list measured
 * 15 seconds on a graph this size, which an audit run cannot pay.
 */
final class MissingTestRuleScaleTest extends TestCase
{
    public function test_it_stays_fast_on_a_graph_the_size_of_a_large_application(): void
    {
        $graph = $this->graph(classes: 4000, tests: 3000);

        $start = microtime(true);
        (new MissingTestRule(MissingTestLevel::Warn))->check($graph, []);
        $elapsed = microtime(true) - $start;

        // Generous on purpose: the indexed walk runs in hundredths of a second, while a
        // per-symbol rescan takes seconds, so this separates the two without being flaky.
        $this->assertLessThan(2.0, $elapsed);
    }

    public function test_a_project_without_tests_reports_every_class_once(): void
    {
        $graph = $this->graph(classes: 500, tests: 0);

        $findings = (new MissingTestRule(MissingTestLevel::Warn))->check($graph, []);

        $this->assertCount(500, $findings);
    }

    private function graph(int $classes, int $tests): ProjectGraphSnapshot
    {
        $symbols = [];
        $edges = [];

        for ($i = 0; $i < $classes; $i++) {
            $name = "App\\Domain\\Class{$i}";
            $symbols[] = new ProjectSymbol($name, "app/Domain/Class{$i}.php", 5, 'App\\Domain', 'class', 'application');

            for ($j = 1; $j <= 4; $j++) {
                $edges[] = new DependencyEdge(
                    $name,
                    'App\\Domain\\Class'.(($i + $j * 7) % $classes),
                    "app/Domain/Class{$i}.php",
                    10,
                    'param',
                    true,
                );
            }
        }

        for ($t = 0; $t < $tests; $t++) {
            $name = "Tests\\Feature\\Test{$t}";
            $symbols[] = new ProjectSymbol($name, "tests/Feature/Test{$t}.php", 5, 'Tests\\Feature', 'class', 'test');
            $edges[] = new DependencyEdge($name, 'App\\Domain\\Class'.($t % $classes), "tests/Feature/Test{$t}.php", 10, 'param', true);
        }

        return new ProjectGraphSnapshot($symbols, $edges);
    }
}
