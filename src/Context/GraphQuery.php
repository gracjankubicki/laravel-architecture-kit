<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogKinds;

/** Directed queries share the catalog's adjacency indexes, never a second source scan. */
final readonly class GraphQuery
{
    public function __construct(private CatalogIndex $index, private int $maxNodes = 5000, private int $maxEdges = 25000) {}

    /** @return array<string, mixed> */
    public function query(string $subject, string $mode = 'context', ?string $target = null, int $depth = 4): array
    {
        $base = ['status' => 'invalid_input', 'records' => [], 'mode' => $mode, 'traversal_complete' => true,
            'counts' => ['basis' => 'exact_in_analyzed_graph', 'direct' => 0, 'indirect' => 0],
            'budgets' => ['nodes' => $this->maxNodes, 'edges' => $this->maxEdges, 'depth' => $mode === 'context' ? 1 : $depth]];
        if (trim($subject) === '' || ! in_array($mode, ['context', 'path', 'impact'], true) || $depth < 1 || $depth > 20
            || ($mode === 'path' ? $target === null || trim($target) === '' : $target !== null)) {
            return $base;
        }
        $subjects = $this->select($subject);
        if (count($subjects) !== 1) {
            return [...$base, 'status' => $subjects === [] ? 'not_found' : 'ambiguous',
                'records' => array_map(fn ($id) => ['candidate' => $this->index->elements[$id], 'selector' => 'subject'], $subjects)];
        }
        $id = $subjects[0];
        $base['subject'] = $this->index->elements[$id];
        if ($mode === 'context') {
            $records = [];
            $neighbors = [];
            foreach (array_unique([...($this->index->out[$id] ?? []), ...($this->index->in[$id] ?? [])]) as $position) {
                if (count($records) >= $this->maxEdges) {
                    $base['traversal_complete'] = false;
                    break;
                }
                $edge = $this->index->relations[$position];
                $neighbor = $edge['from'] === $id ? $edge['to'] : $edge['from'];
                if (! isset($neighbors[$neighbor]) && count($neighbors) >= $this->maxNodes) {
                    $base['traversal_complete'] = false;
                    break;
                }
                $neighbors[$neighbor] = true;
                $records[] = ['direction' => $edge['from'] === $id ? 'outgoing' : 'incoming', 'relation' => $edge,
                    'element' => $this->index->elements[$edge['from'] === $id ? $edge['to'] : $edge['from']] ?? null,
                    'semantic_transition' => $this->semantic($edge)];
            }
            usort($records, static fn ($a, $b) => [$a['direction'], $a['relation']['kind'], $a['relation']['from'], $a['relation']['to'], $a['relation']['path'], $a['relation']['line']]
                <=> [$b['direction'], $b['relation']['kind'], $b['relation']['from'], $b['relation']['to'], $b['relation']['path'], $b['relation']['line']]);

            return [...$base, 'status' => $records === [] ? 'empty' : 'found', 'records' => $records,
                'counts' => ['basis' => $base['traversal_complete'] ? 'exact_in_analyzed_graph' : 'lower_bound', 'direct' => count($neighbors), 'indirect' => 0]];
        }
        $destination = null;
        if ($mode === 'path') {
            $targets = $this->select($target ?? '');
            if (count($targets) !== 1) {
                return [...$base, 'status' => $targets === [] ? 'not_found' : 'ambiguous',
                    'records' => array_map(fn ($id) => ['candidate' => $this->index->elements[$id], 'selector' => 'target'], $targets)];
            }
            $destination = $targets[0];
            $base['target'] = $this->index->elements[$destination];
        }
        $seedResult = $mode === 'impact' ? $this->changeSeeds($id) : ['seeds' => [$id], 'complete' => true];
        $seeds = $seedResult['seeds'];
        $base['traversal_complete'] = $seedResult['complete'];
        $seen = [];
        $queue = [];
        foreach ($seeds as $seed) {
            if (count($seen) >= $this->maxNodes) {
                $base['traversal_complete'] = false;
                break;
            }
            $seen[$seed] = ['distance' => 0, 'previous' => null, 'edge' => null, 'seed' => $seed];
            $queue[] = $seed;
        }
        $edges = 0;
        $found = $destination === $id;
        for ($cursor = 0; $cursor < count($queue) && ! $found; $cursor++) {
            $current = $queue[$cursor];
            $distance = $seen[$current]['distance'];
            $positions = $mode === 'impact' ? ($this->index->in[$current] ?? []) : ($this->index->out[$current] ?? []);
            usort($positions, fn ($a, $b) => $this->edgeKey($this->index->relations[$a]) <=> $this->edgeKey($this->index->relations[$b]));
            foreach ($positions as $position) {
                if (++$edges > $this->maxEdges) {
                    $base['traversal_complete'] = false;
                    break 2;
                }
                $edge = $this->index->relations[$position];
                if ($mode === 'path' && ! $this->semantic($edge) || $mode === 'impact' && $edge['kind'] === 'contains') {
                    continue;
                }
                $next = $mode === 'impact' ? $edge['from'] : $edge['to'];
                if (! isset($this->index->elements[$next]) || isset($seen[$next])) {
                    continue;
                }
                if ($distance >= $depth || count($seen) >= $this->maxNodes) {
                    $base['traversal_complete'] = false;

                    continue;
                }
                $seen[$next] = ['distance' => $distance + 1, 'previous' => $current, 'edge' => $edge, 'seed' => $seen[$current]['seed']];
                $queue[] = $next;
                if ($next === $destination) {
                    $found = true;
                    break;
                }
            }
        }
        if ($mode === 'path') {
            return [...$base, 'status' => $found ? 'found' : ($base['traversal_complete'] ? 'no_path_in_analyzed_graph' : 'unavailable'),
                'records' => $found ? [['nodes' => $this->pathNodes($destination ?? $id, $seen), 'relations' => $this->pathEdges($destination ?? $id, $seen, false),
                    'execution_proven' => false]] : []];
        }
        $records = [];
        $direct = 0;
        foreach ($seen as $dependent => $state) {
            if ($state['distance'] === 0) {
                continue;
            }
            $element = $this->index->elements[$dependent];
            $isDirect = $state['distance'] === 1;
            $direct += (int) $isDirect;
            $records[] = ['element' => $element, 'distance' => $state['distance'], 'direct' => $isDirect,
                'changed_seed' => $state['seed'], 'explanation' => $this->pathEdges($dependent, $seen, true),
                'entrypoint' => in_array($element['kind'], ['route', 'scheduled-task', 'console-command', 'broadcast-subscription', 'database-seeder', 'database-migration'], true),
                'test_candidate' => ($element['metadata']['test_registration_resolved'] ?? true) && (in_array($element['kind'], ['pest-test', 'test-method'], true) || in_array('test', $element['roles'], true) || in_array('test-method', $element['roles'], true)),
                'execution_proven' => false, 'test_executed' => false];
        }
        usort($records, static fn ($a, $b) => [$a['distance'], $a['element']['kind'], $a['element']['name'], $a['element']['path'], $a['element']['line'], $a['element']['id']]
            <=> [$b['distance'], $b['element']['kind'], $b['element']['name'], $b['element']['path'], $b['element']['line'], $b['element']['id']]);

        return [...$base, 'status' => $records === [] ? 'empty' : 'found', 'records' => $records,
            'change_seeds' => $seeds, 'counts' => ['basis' => $base['traversal_complete'] ? 'exact_in_analyzed_graph' : 'lower_bound',
                'direct' => $direct, 'indirect' => count($records) - $direct]];
    }

    /** @return list<string> */
    private function select(string $selector): array
    {
        if (isset($this->index->elements[$selector])) {
            return [$selector];
        }
        $matches = [];
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] === 'file' && $element['path'] === $selector
                || in_array($element['kind'], ['class', 'interface', 'trait', 'enum'], true) && strcasecmp($element['name'], ltrim($selector, '\\')) === 0) {
                $matches[] = $id;
            }
        }
        sort($matches, SORT_STRING);

        return $matches;
    }

    /** @param array<string, mixed> $edge */
    private function semantic(array $edge): bool
    {
        return ! in_array($edge['kind'], [...CatalogKinds::STRUCTURAL_RELATIONS, 'callable-reference'], true)
            && ! preg_match('/^(returned-|registers-|declares-|references-)/', $edge['kind']);
    }

    /** @param array<string, mixed> $edge
     * @return list<mixed>
     */
    private function edgeKey(array $edge): array
    {
        return [$edge['to'], $edge['from'], $edge['kind'], $edge['path'], $edge['line']];
    }

    /** @return array{seeds: list<string>, complete: bool} */
    private function changeSeeds(string $id): array
    {
        $seeds = [$id];
        $known = [$id => true];
        $visitedEdges = 0;
        $element = $this->index->elements[$id];
        if (in_array($element['kind'], ['file', 'class', 'interface', 'trait', 'enum'], true)) {
            for ($cursor = 0; $cursor < count($seeds); $cursor++) {
                foreach ($this->index->out[$seeds[$cursor]] ?? [] as $position) {
                    if (++$visitedEdges > $this->maxEdges) {
                        return ['seeds' => $seeds, 'complete' => false];
                    }
                    $edge = $this->index->relations[$position];
                    if ($edge['kind'] === 'contains' && isset($this->index->elements[$edge['to']]) && ! isset($known[$edge['to']])) {
                        if (count($seeds) >= $this->maxNodes) {
                            return ['seeds' => $seeds, 'complete' => false];
                        }
                        $known[$edge['to']] = true;
                        $seeds[] = $edge['to'];
                    }
                }
            }
        }

        return ['seeds' => $seeds, 'complete' => true];
    }

    /** @param array<string, array<string, mixed>> $seen
     * @return list<array<string, mixed>>
     */
    private function pathEdges(string $id, array $seen, bool $reverse): array
    {
        $edges = [];
        while ($seen[$id]['previous'] !== null) {
            $edges[] = $seen[$id]['edge'];
            $id = $seen[$id]['previous'];
        }

        return $reverse ? $edges : array_reverse($edges);
    }

    /** @param array<string, array<string, mixed>> $seen
     * @return list<string>
     */
    private function pathNodes(string $id, array $seen): array
    {
        $nodes = [$id];
        while ($seen[$id]['previous'] !== null) {
            $id = $seen[$id]['previous'];
            $nodes[] = $id;
        }

        return array_reverse($nodes);
    }
}
