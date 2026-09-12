<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\LayerPolicy;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use Illuminate\Filesystem\Filesystem;

final readonly class ArchitectureContext
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private LayerPolicy $policy = new LayerPolicy,
        private FindingCodeRegistry $codes = new FindingCodeRegistry,
        private AuditScope $scope = new AuditScope,
        private ?ProjectGraphCache $cache = null,
        /** @var array<int, string> */
        private array $cacheConfiguration = [],
    ) {}

    /**
     * @param  array<int, mixed>  $enabled
     * @param  array<int, string>  $exclude
     */
    public function inspect(string $subject, array $enabled, array $exclude = [], int $limit = 20): ArchitectureContextResult
    {
        // The same scope the audit reads. Answering from a narrower graph would report no
        // dependents for a symbol the audit already reports findings about.
        $loader = new ProjectGraphLoader($this->files, $this->basePath, $this->scope, $this->cache, $this->cacheConfiguration);
        $plan = $loader->plan($exclude);
        $graph = $loader->build($plan);
        $resolved = $this->resolve($graph, trim($subject));
        $limit = max(0, $limit);
        $dependencies = array_map(
            fn (DependencyEdge $edge): array => $this->relationship($graph, $resolved, $edge, outgoing: true),
            $graph->dependenciesOf($resolved->name),
        );
        $dependents = array_map(
            fn (DependencyEdge $edge): array => $this->relationship($graph, $resolved, $edge, outgoing: false),
            $graph->dependentsOf($resolved->name),
        );
        $this->sortRelationships($dependencies);
        $this->sortRelationships($dependents);
        $totalRelationships = count($dependencies) + count($dependents);
        $dependencies = $limit === 0 ? [] : array_slice($dependencies, 0, $limit);
        $dependents = $limit === 0 ? [] : array_slice($dependents, 0, $limit);
        $allViolations = $this->violations($graph, $resolved, $enabled);
        $violations = $limit === 0 ? [] : array_slice($allViolations, 0, $limit);
        // Which tests exercise the symbol, so the agent can run a narrow relevant set
        // before the full suite instead of learning the effect from a red run.
        $allTests = (new TestCoverageLookup($this->files, $this->basePath))->for($resolved->name);
        $tests = $limit === 0 ? [] : array_slice($allTests, 0, $limit);
        $allInspect = $this->inspectPaths($resolved, $dependencies, $dependents);
        $inspect = $limit === 0 ? [] : array_slice($allInspect, 0, $limit);
        $next = [];

        if ($inspect !== []) {
            $next[] = 'inspect:'.implode(',', $inspect);
        }

        if ($allViolations !== []) {
            $next[] = 'fix_context_violations';
        }

        if ($tests !== []) {
            $next[] = 'run_tests:'.implode(',', array_column($tests, 'path'));
        }

        $next[] = 'run:architecture-kit:guard --changed --agent';

        return new ArchitectureContextResult(
            subject: $resolved,
            dependencies: $dependencies,
            dependents: $dependents,
            violations: $violations,
            tests: $tests,
            inspect: $inspect,
            next: $next,
            truncated: count($dependencies) + count($dependents) < $totalRelationships
                || count($violations) < count($allViolations)
                || count($tests) < count($allTests)
                || count($inspect) < count($allInspect),
            cacheStatus: $plan->cacheStatus,
        );
    }

    private function resolve(ProjectGraphSnapshot $graph, string $subject): ProjectSymbol
    {
        if ($subject === '') {
            throw new ArchitectureContextException('E_CONTEXT_SUBJECT_REQUIRED', 'Provide an exact project FQCN or app-relative PHP path.');
        }

        $matches = [];

        if (str_ends_with(strtolower($subject), '.php') || str_contains($subject, '/')) {
            $path = str_replace('\\', '/', $subject);

            if (str_starts_with($path, str_replace('\\', '/', $this->basePath).'/')) {
                $path = substr($path, strlen(str_replace('\\', '/', $this->basePath)) + 1);
            }

            $matches = $graph->symbolsAt(ltrim($path, './'));
        } else {
            $fqcn = ltrim($subject, '\\');
            $matches = array_values(array_filter(
                $graph->symbols,
                fn (ProjectSymbol $symbol): bool => strcasecmp($symbol->name, $fqcn) === 0,
            ));
        }

        if ($matches === []) {
            throw new ArchitectureContextException('E_CONTEXT_SUBJECT_NOT_FOUND', "Architecture context subject [{$subject}] was not found in the analyzed app graph.");
        }

        if (count($matches) > 1) {
            throw new ArchitectureContextException('E_CONTEXT_SUBJECT_AMBIGUOUS', "Architecture context subject [{$subject}] resolves to multiple PHP symbols; use an exact FQCN.");
        }

        return $matches[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationship(ProjectGraphSnapshot $graph, ProjectSymbol $subject, DependencyEdge $edge, bool $outgoing): array
    {
        $related = $graph->symbol($outgoing ? $edge->to : $edge->from);
        $source = $outgoing ? $subject : $related;
        $target = $outgoing ? $related : $subject;
        $allowed = ! $edge->strong
            || $source === null
            || $target === null
            || $this->policy->allows($source->role, $target->role);

        return [
            'symbol' => $outgoing ? $edge->to : $edge->from,
            'path' => $related?->path,
            'role' => $related !== null ? $related->role : 'external',
            'kind' => $edge->kind,
            'strength' => $edge->strong ? 'strong' : 'weak',
            // What a change to the subject would do to this relationship. Used to order
            // the answer, so a truncated result keeps what breaks first.
            'impact' => ImpactRanking::for($edge),
            'allowed' => $allowed,
            'evidence' => [
                'path' => $edge->path,
                'line' => $edge->line,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $relationships
     */
    private function sortRelationships(array &$relationships): void
    {
        usort($relationships, fn (array $left, array $right): int => [
            ImpactRanking::rank(is_string($left['impact']) ? $left['impact'] : ImpactRanking::CONTEXT),
            $left['symbol'],
            $left['kind'],
            $left['evidence']['path'],
            $left['evidence']['line'],
        ] <=> [
            ImpactRanking::rank(is_string($right['impact']) ? $right['impact'] : ImpactRanking::CONTEXT),
            $right['symbol'],
            $right['kind'],
            $right['evidence']['path'],
            $right['evidence']['line'],
        ]);
    }

    /**
     * @param  array<int, mixed>  $enabled
     * @return array<int, array<string, mixed>>
     */
    private function violations(ProjectGraphSnapshot $graph, ProjectSymbol $subject, array $enabled): array
    {
        $findings = [];

        foreach ((new ProjectRuleSet)->rules() as $rule) {
            foreach ($rule->check($graph, $enabled) as $finding) {
                $code = $this->codes->codeFor($finding);
                $mentionsSubject = str_contains($finding->message, $subject->name)
                    || ($code === 'W_NAMESPACE_CYCLE' && str_contains($finding->message, $subject->namespace));

                if ($finding->path !== $subject->path && ! $mentionsSubject) {
                    continue;
                }

                $findings[] = [
                    'code' => $code,
                    'severity' => $finding->severity,
                    'path' => $finding->path,
                    'line' => $finding->line,
                    'message' => $finding->message,
                ];
            }
        }

        usort($findings, fn (array $left, array $right): int => [$left['severity'], $left['path'], $left['line'], $left['code']] <=> [$right['severity'], $right['path'], $right['line'], $right['code']]);

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dependencies
     * @param  array<int, array<string, mixed>>  $dependents
     * @return array<int, string>
     */
    private function inspectPaths(ProjectSymbol $subject, array $dependencies, array $dependents): array
    {
        $paths = [$subject->path => $subject->path];

        foreach (array_merge($dependencies, $dependents) as $relationship) {
            if (is_string($relationship['path'] ?? null)) {
                $paths[$relationship['path']] = $relationship['path'];
            }
        }

        $paths = array_values($paths);
        sort($paths);

        return $paths;
    }
}
