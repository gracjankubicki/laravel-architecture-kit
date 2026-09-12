<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;

/**
 * An architecture element that no test depends on.
 *
 * The question is answered from the dependency graph rather than from a naming or
 * folder convention, so it holds for every architecture wherever its elements live, and
 * a test that exercises an element indirectly still counts. Skipping tests is a
 * systematic weakness of coding agents, and guidance written in prose does not change a
 * gate result, so it does not change behaviour either.
 */
final readonly class MissingTestRule implements ProjectAuditRule
{
    /**
     * Kinds that cannot be tested on their own: an interface declares no behaviour, a
     * trait is exercised through the class that uses it, and the stand-in symbol for a
     * classless file is not an element anyone writes a test for.
     *
     * An enum is not on this list, because the answer depends on the symbol rather than
     * the kind: see isTestable() below. Recorded as an annex to the target state on
     * 2026-09-12.
     *
     * @var array<int, string>
     */
    private const UNTESTABLE_KINDS = ['interface', 'trait', 'file'];

    public function __construct(private MissingTestLevel $level = MissingTestLevel::Off) {}

    /**
     * @param  array<int, mixed>  $enabled
     * @param  array<int, string>|null  $focusPaths
     * @return array<int, AuditFinding>
     */
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array
    {
        if (! $this->level->isEnabled()) {
            return [];
        }

        $tested = $this->testedSymbols($graph);
        $findings = [];

        foreach ($graph->symbols as $symbol) {
            if (! $this->isTestable($symbol) || isset($tested[strtolower($symbol->name)])) {
                continue;
            }

            if ($focusPaths !== null && ! in_array($symbol->path, $focusPaths, true)) {
                continue;
            }

            $findings[] = new AuditFinding(
                severity: $this->level->severity(),
                rule: 'missing-test',
                path: $symbol->path,
                line: $symbol->line,
                message: "No test depends on {$symbol->name}; add one, for example ".$this->suggestedTestPath($symbol).'.',
                code: $this->level === MissingTestLevel::Error ? 'E_MISSING_TEST' : 'W_MISSING_TEST',
            );
        }

        return $findings;
    }

    /**
     * Symbols reachable from a test file. A test that goes through a service still
     * covers what that service uses, so reachability is transitive.
     *
     * @return array<string, true>
     */
    private function testedSymbols(ProjectGraphSnapshot $graph): array
    {
        // Outgoing edges indexed once. Asking the snapshot per symbol would rescan every
        // edge each time: measured 15s on a graph the size of a large application, which
        // is not a price an audit run can pay.
        $outgoing = [];

        foreach ($graph->edges as $edge) {
            $outgoing[strtolower($edge->from)][] = $edge->to;
        }

        $queue = [];
        $seen = [];

        foreach ($graph->edges as $edge) {
            if (! RoleClassifier::isTestPath($edge->path)) {
                continue;
            }

            $target = strtolower($edge->to);

            if (! isset($seen[$target])) {
                $seen[$target] = true;
                $queue[] = $edge->to;
            }
        }

        while ($queue !== []) {
            $current = strtolower((string) array_pop($queue));

            foreach ($outgoing[$current] ?? [] as $next) {
                $target = strtolower($next);

                if (isset($seen[$target])) {
                    continue;
                }

                $seen[$target] = true;
                $queue[] = $next;
            }
        }

        return $seen;
    }

    private function isTestable(ProjectSymbol $symbol): bool
    {
        if (RoleClassifier::isTestPath($symbol->path) || in_array($symbol->kind, self::UNTESTABLE_KINDS, true)) {
            return false;
        }

        // An enum that only lists cases has nothing to assert beyond the language itself;
        // one with methods holds behaviour and is treated like any other element.
        return $symbol->kind !== 'enum' || $symbol->hasMethods;
    }

    /**
     * Where a test for this element would conventionally live. It is a hint, not a
     * requirement: the rule accepts a test anywhere, because it reads the graph.
     */
    private function suggestedTestPath(ProjectSymbol $symbol): string
    {
        $relative = preg_replace('#^app/#', '', $symbol->path) ?? $symbol->path;

        return 'tests/Feature/'.preg_replace('#\.php$#', 'Test.php', $relative);
    }
}
