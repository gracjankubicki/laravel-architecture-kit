<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

final readonly class ArchitectureImpact
{
    /** @param list<string> $cacheConfiguration */
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private AuditScope $scope = new AuditScope,
        private ?ProjectGraphCache $cache = null,
        private array $cacheConfiguration = [],
    ) {}

    /** @param list<string> $exclude
     * @return array<string, mixed>
     */
    public function inspect(string $subject, array $exclude = [], int $limit = 20, int $depth = 4, ?string $change = null, ?string $signature = null): array
    {
        if ($signature !== null) {
            $change ??= 'signature';
        }
        if ($change !== null && $change !== 'signature') {
            return self::error('E_IMPACT_CHANGE_INVALID', 'Supported change mode: signature.');
        }
        if ($change === 'signature' && ! str_contains($subject, '::')) {
            return self::error('E_IMPACT_SIGNATURE_SUBJECT', 'Signature analysis requires Class::method.');
        }
        if (trim($subject) === '') {
            return self::error('E_IMPACT_SUBJECT_REQUIRED', 'Provide a class, path, or Class::method.');
        }
        if ($limit < 0 || $limit > 500 || $depth < 1 || $depth > 32) {
            return self::error('E_IMPACT_LIMIT_INVALID', 'Use limit 0..500 and depth 1..32.');
        }
        $loader = new ProjectGraphLoader($this->files, $this->basePath, $this->scope, $this->cache, $this->cacheConfiguration, impact: true);
        $plan = $loader->plan($exclude);
        $graph = $loader->build($plan);
        [$selector, $method] = array_pad(explode('::', trim($subject), 2), 2, null);
        $matches = $this->matches($graph, $selector);
        if ($matches === []) {
            foreach ($graph->impactFacts as $facts) {
                foreach ($facts->notices as $notice) {
                    if (str_contains(strtolower($notice['reason']), 'limit')) {
                        return self::error('E_IMPACT_ANALYSIS_LIMIT', 'Subject resolution is incomplete: '.$facts->path.' '.$notice['reason']);
                    }
                }
            }
            $path = str_replace('\\', '/', $selector);
            $outOfScope = str_starts_with(ltrim($selector, '\\'), 'Illuminate\\') || str_starts_with($path, 'vendor/') ||
                ((str_ends_with($path, '.php') || str_contains($path, '/')) && (! $this->scope->covers($path) || ! isset($plan->files[$path])) && $this->files->isFile($this->basePath.'/'.$path));

            return self::error($outOfScope ? 'E_IMPACT_OUT_OF_SCOPE' : 'E_IMPACT_SUBJECT_NOT_FOUND', 'Subject is not present in the analyzed project scope.');
        }
        if (count($matches) > 1) {
            return [...self::error('E_IMPACT_SUBJECT_AMBIGUOUS', 'Choose an exact FQCN from candidates.'), 'candidates' => array_slice($matches, 0, $limit), 'trunc' => count($matches) > $limit];
        }
        $resolved = $matches[0];
        $root = $resolved['name'];
        $index = new ImpactIndex($graph, buildCalls: $method !== null || $resolved['kind'] === 'file');
        $overrides = [];
        $declaration = null;
        if ($method !== null) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $method)) {
                return self::error('E_IMPACT_METHOD_INVALID', 'Use Class::method with a PHP method identifier.');
            }
            $declaration = $index->method($root, $method);
            if ($declaration === null) {
                if ($index->limitReached()) {
                    return self::error('E_IMPACT_ANALYSIS_LIMIT', 'Method indexing exceeded its memory/dispatch limit. Narrow the configured scope or increase the process memory limit.');
                }
                $class = $index->classes[strtolower($root)] ?? null;
                $incomplete = $class === null || $class['adaptations'] || $class['traits'] !== [] || $class['parents'] !== [];

                return self::error($incomplete ? 'E_IMPACT_METHOD_UNRESOLVED' : 'E_IMPACT_METHOD_NOT_FOUND', 'Method is absent or its inherited/trait declaration cannot be resolved.');
            }
            $overrides = $index->overrides($root, $method, $declaration['symbol']);
            $resolved = [...$resolved, 'requested_method' => $method, 'declaration' => $declaration];
            $root = $declaration['symbol'];
        }
        $signatureReport = null;
        if ($change === 'signature' && $declaration !== null) {
            try {
                $signatureReport = (new SignatureImpact)->inspect($index, $declaration, $signature, $limit);
            } catch (InvalidArgumentException $error) {
                return self::error('E_IMPACT_SIGNATURE_INVALID', $error->getMessage());
            }
        }
        $classIndexLimited = false;
        $classEdges = $this->classEdges($graph, $classIndexLimited, $method !== null ? $resolved['name'] : null);
        $methodChannel = $method !== null || $resolved['kind'] === 'file';
        $incoming = ! $methodChannel ? $this->walk($root, $classEdges, false, $limit, $depth) : $this->walk($root, $index, false, $limit, $depth);
        $outgoing = ! $methodChannel ? $this->walk($root, $classEdges, true, $limit, $depth) : $this->walk($root, $index, true, $limit, $depth);
        $notices = $index->unresolved($root, $method);
        if ($classIndexLimited) {
            $notices[] = ['path' => '(project)', 'line' => 1, 'reason' => 'Class impact index memory limit reached.'];
        }
        foreach ([...$incoming['resolved'], ...$incoming['possible'], ...$outgoing['resolved'], ...$outgoing['possible']] as $row) {
            if ($method !== null) {
                $parts = explode('::', $row['symbol'], 2);
                array_push($notices, ...$index->unresolved($row['symbol'], $parts[1] ?? null, false));
            }
        }
        $uniqueNotices = [];
        foreach ($notices as $notice) {
            $uniqueNotices[json_encode($notice, JSON_THROW_ON_ERROR)] = $notice;
        }
        $notices = array_values($uniqueNotices);
        $candidates = $resolved['kind'] === 'file' ? ['tests' => [], 'notices' => []] : (new ImpactTestCandidates($this->files, $this->basePath))->for($resolved['name'], $graph);
        $tests = $candidates['tests'];
        array_push($notices, ...$candidates['notices']);
        foreach ([...$incoming['resolved'], ...$incoming['possible']] as $row) {
            if ($this->scope->isTestPath($row['path'])) {
                $tests[] = ['path' => $row['path'], 'basis' => $method === null ? 'class_reference' : 'method_call', 'via' => $row['via'], 'proves_coverage' => false];
            }
        }
        $tests = array_values(array_reduce($tests, static function (array $carry, array $test): array {
            $carry[$test['path'].'|'.$test['basis']] = $test;

            return $carry;
        }, []));
        $context = $method !== null ? ['dependents' => $this->classContext($classEdges, $resolved['name'], false, $limit), 'dependencies' => $this->classContext($classEdges, $resolved['name'], true, $limit)] : ['dependents' => [], 'dependencies' => []];
        // Stat-based freshness is the same contract as the shared graph cache. A
        // concurrent edit makes this report incomplete and requires a fresh query.
        $current = $loader->currentSignature($exclude);
        if ($current->files !== $plan->signature->files) {
            $notices[] = ['path' => $resolved['path'], 'line' => $resolved['line'], 'reason' => 'Project files changed during analysis. Rerun impact.'];
        }
        $analysisBounded = count(array_filter($notices, static fn (array $notice): bool => str_contains(strtolower($notice['reason']), 'limit'))) > 0;
        $truncated = $analysisBounded || $incoming['limited'] || $outgoing['limited'] || count($notices) > $limit || count($tests) > $limit || count($overrides) > $limit;
        foreach (['dependents' => false, 'dependencies' => true] as $key => $direction) {
            if ($method !== null && count($classEdges[$direction ? 'out' : 'in'][strtolower($resolved['name'])] ?? []) > $limit) {
                $truncated = true;
            }
        }
        $hasRelationships = $incoming['resolved'] !== [] || $outgoing['resolved'] !== [] || $incoming['possible'] !== [] || $outgoing['possible'] !== [] || $incoming['references'] !== [] || $outgoing['references'] !== [];

        $result = [
            'v' => 1, 'ok' => true, 'cmd' => 'impact', 'subject' => $resolved,
            'dependents' => $incoming['resolved'], 'dependencies' => $outgoing['resolved'],
            'possible' => ['dependents' => $incoming['possible'], 'dependencies' => $outgoing['possible'], 'overrides' => array_slice($overrides, 0, $limit)],
            'references' => ['dependents' => $incoming['references'], 'dependencies' => $outgoing['references']],
            'class_context' => $context, 'tests' => array_slice($tests, 0, $limit),
            'analysis' => ['status' => $truncated ? 'limit' : ($notices !== [] ? 'incomplete' : ($hasRelationships ? 'complete' : 'none')),
                'notices' => array_slice($notices, 0, $limit), 'notice_total' => count($notices),
                'limitations' => ['Static relationships do not prove runtime execution or absence of callers.', 'Framework dispatch, container bindings, macros, magic calls and callback bodies are not fully resolved.', 'Test candidates are not coverage or PASS.'],
                'limit' => $limit, 'depth' => $depth, 'truncated' => $truncated,
                'expand' => 'Rerun impact with a larger limit/depth within 500/32, or inspect the boundary symbols. No continuation pages.'],
            'cache' => $plan->cacheStatus->value, 'snapshot' => hash('xxh128', serialize($plan->signature->toArray())),
            'scope' => ['paths' => $this->scope->directories, 'exclude' => $exclude],
            'next' => ['inspect_relationship_evidence', 'resolve_uncertain_calls_before_dependent_decisions', 'run_selected_tests', 'run:architecture-kit:guard --changed --agent'],
        ];
        if ($signatureReport !== null) {
            foreach ($notices as $notice) {
                if (count($signatureReport['check']) < $limit) {
                    $signatureReport['check'][] = ['symbol' => $notice['from'] ?? $root, 'path' => $notice['path'], 'line' => $notice['line'], 'kind' => 'uncertain', 'certainty' => 'unresolved', 'reasons' => [$notice['reason']]];
                }
                $signatureReport['total']['check']++;
            }
            $signatureReport['truncated'] = $signatureReport['truncated'] || $truncated || $signatureReport['total']['check'] > $limit;
            if ($signatureReport['truncated']) {
                $signatureReport['status'] = 'limit';
            } elseif ($notices !== [] && $signatureReport['status'] === 'no_proven_breaking') {
                $signatureReport['status'] = 'check';
            }
            $signatureReport['safe_to_change'] = false;
            $result['signature'] = $signatureReport;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function matches(ProjectGraphSnapshot $graph, string $selector): array
    {
        $path = str_replace('\\', '/', $selector);
        $base = str_replace('\\', '/', $this->basePath).'/';
        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $isPath = str_contains($selector, '/') || str_ends_with(strtolower($selector), '.php');
        $result = [];
        foreach ($graph->symbols as $symbol) {
            $short = substr($symbol->name, (int) strrpos('\\'.$symbol->name, '\\'));
            if (($isPath && $symbol->path === $path) || (! $isPath && (strcasecmp($symbol->name, ltrim($selector, '\\')) === 0 || (! str_contains(ltrim($selector, '\\'), '\\') && strcasecmp($short, $selector) === 0)))) {
                $result[] = ['name' => $symbol->name, 'path' => $symbol->path, 'line' => $symbol->line, 'kind' => $symbol->kind, 'role' => $symbol->role];
            }
        }
        if ($result === [] && $isPath) {
            foreach ($graph->impactFacts as $facts) {
                if ($facts->path === $path && $facts->classes === [] && ! array_filter($facts->notices, static fn (array $notice): bool => str_contains(strtolower($notice['reason']), 'limit') || $notice['reason'] === 'Unparseable source.')) {
                    $result[] = ['name' => '(file) '.$path, 'path' => $path, 'line' => 1, 'kind' => 'file', 'role' => 'unknown'];
                }
            }
        }

        return $result;
    }

    /** @return array<string, array<string, list<array<string, mixed>>>> */
    private function classEdges(ProjectGraphSnapshot $graph, bool &$limited, ?string $subject = null): array
    {
        $indices = ['in' => [], 'out' => []];
        foreach ($graph->edges as $edge) {
            if ($subject !== null && strcasecmp($edge->from, $subject) !== 0 && strcasecmp($edge->to, $subject) !== 0) {
                continue;
            }
            $memoryLimit = MemoryLimit::bytes();
            if ($memoryLimit !== null && memory_get_usage(true) + 65536 > $memoryLimit * 0.8) {
                $limited = true;
                break;
            }
            $row = ['from' => $edge->from, 'to' => $edge->to, 'kind' => $edge->kind, 'certainty' => 'resolved', 'path' => $edge->path, 'line' => $edge->line, 'strong' => $edge->strong];
            $indices['in'][strtolower($edge->to)][] = $row;
            $indices['out'][strtolower($edge->from)][] = $row;
        }

        return $indices;
    }

    /** @param ImpactIndex|array<string, array<string, list<array<string, mixed>>>> $index
     * @return array<string, mixed>
     */
    private function walk(string $root, ImpactIndex|array $index, bool $outgoing, int $limit, int $depth): array
    {
        $queue = [[$root, [], false]];
        $visited = [strtolower($root).'|resolved' => true];
        $groups = ['resolved' => [], 'possible' => [], 'references' => []];
        $limited = false;
        $visits = 0;
        for ($i = 0; $i < count($queue); $i++) {
            [$current, $via, $possible] = $queue[$i];
            $edges = $index instanceof ImpactIndex ? $index->edges($current, $outgoing) : ($index[$outgoing ? 'out' : 'in'][strtolower($current)] ?? []);
            if (count($via) >= $depth) {
                if ($edges !== []) {
                    $limited = true;
                }

                continue;
            }
            foreach ($edges as $edge) {
                if (++$visits > 10000 || count($queue) > 1000) {
                    $limited = true;
                    break 2;
                }
                $symbol = $edge[$outgoing ? 'to' : 'from'];
                $category = $edge['kind'] === 'reference' ? 'references' : ($possible || $edge['certainty'] === 'possible' ? 'possible' : 'resolved');
                $key = strtolower($symbol).'|'.$category;
                if (isset($visited[$key]) || strcasecmp($symbol, $root) === 0) {
                    continue;
                }
                $visited[$key] = true;
                $trace = [...$via, $edge];
                if (count($groups[$category]) >= $limit) {
                    $limited = true;

                    continue;
                }
                $groups[$category][] = ['symbol' => $symbol, 'path' => $edge['path'], 'line' => $edge['line'], 'kind' => $edge['kind'], 'certainty' => $category, 'via' => $trace];
                // References are not invocations; weak class edges stop at one hop.
                if ($category !== 'references' && ($edge['strong'] ?? true)) {
                    $queue[] = [$symbol, $trace, $category === 'possible'];
                }
            }
        }

        return [...$groups, 'limited' => $limited];
    }

    /** @param array<string, array<string, list<array<string, mixed>>>> $indices
     * @return list<array<string, mixed>>
     */
    private function classContext(array $indices, string $class, bool $outgoing, int $limit): array
    {
        return array_map(static fn (array $edge): array => [...$edge, 'basis' => 'class_only_not_method_call'], array_slice($indices[$outgoing ? 'out' : 'in'][strtolower($class)] ?? [], 0, $limit));
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'ok' => false, 'cmd' => 'impact', 'm' => $code, 'msg' => $message, 'next' => ['fix_subject_or_limits', 'rerun:impact']];
    }
}
