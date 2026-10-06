<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use GracjanKubicki\ArchitectureKit\Impact\DataAnalysis;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteDiscovery;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/** Existing graph and Laravel channels run against one immutable source state. */
final readonly class RevisionFacts
{
    /** @param array<string, array<string, mixed>> $symbols
     * @param array<string, mixed> $http
     * @param array<string, array{fingerprint: string, entry: FileGraphEntry, shapes: array<string, string>}> $reuse
     * @param list<array<string, mixed>> $notices
     * @param array<string, bool> $channels
     * @param array<string, array<string, mixed>> $ruleSources
     * @param array<string, int|float> $metrics */
    private function __construct(
        public SourceSnapshot $source,
        public RevisionConfiguration $configuration,
        public ProjectGraphSnapshot $graph,
        public array $symbols,
        public array $http,
        public DataAnalysis $data,
        public array $reuse,
        public array $notices,
        public array $metrics,
        public array $channels,
        public array $ruleSources,
    ) {}

    /** @param array<string, array{fingerprint: string, entry: FileGraphEntry, shapes: array<string, string>}> $reuse */
    public static function collect(SourceSnapshot $source, RevisionConfiguration $configuration, array $reuse = []): self
    {
        $started = hrtime(true);
        $scope = $configuration->scope();
        $roles = new RoleClassifier($configuration->mappings() ?? new ClassificationMappings);
        $builder = new ProjectGraphBuilder($roles, true, preserveOccurrences: true);
        $notices = [...$source->notices, ...$configuration->notices];
        $entries = [];
        $parsed = $reused = 0;
        foreach ($source->files as $path => $contents) {
            if (! str_ends_with(strtolower($path), '.php') || ($scope !== null && ! $scope->covers($path))) {
                continue;
            }
            if (Str::is($configuration->excludes(), $path) && ! ($scope?->isTestPath($path) ?? false)) {
                continue;
            }
            if (($reason = ImpactExtractor::sourceLimit(strlen($contents))) !== null) {
                $notices[] = ['path' => $path, 'line' => 1, 'reason' => $reason];
                break;
            }
            $fingerprint = hash('sha256', serialize([$path, $contents, $configuration->fingerprint]));
            if (($reuse[$path]['fingerprint'] ?? null) === $fingerprint) {
                $entry = $reuse[$path]['entry'];
                $shapes = $reuse[$path]['shapes'];
                $reused++;
            } else {
                try {
                    $file = new FileContext($path, $contents);
                    $shapes = RevisionIdentity::declarations($file);
                    $entry = $builder->collect($file);
                } catch (Throwable $error) {
                    $notices[] = ['path' => $path, 'line' => 1, 'reason' => 'Revision graph extraction unresolved: '.$error->getMessage()];

                    continue;
                }
                $parsed++;
            }
            $entries[$path] = ['fingerprint' => $fingerprint, 'entry' => $entry, 'shapes' => $shapes];
            $builder->addEntry($entry);
            array_push($notices, ...($entry->impact->notices ?? []));
        }
        $graph = $builder->finish();
        $symbols = [];
        foreach ($graph->symbols as $symbol) {
            if (self::symbolBudgetReached(count($symbols))) {
                $notices[] = ['path' => $symbol->path, 'line' => $symbol->line, 'reason' => 'Revision symbol count or memory budget reached.'];
                break;
            }
            try {
                $description = $roles->describe($symbol->path, $symbol->name, $symbol->kind, $symbol->hasMethods);
            } catch (Throwable $error) {
                $description = ['role' => null, 'application_kind' => null, 'module' => null, 'php_kind' => $symbol->kind,
                    'module_parents' => [], 'provenance' => ['classification' => 'conflict', 'module' => 'conflict']];
                $notices[] = ['path' => $symbol->path, 'line' => $symbol->line, 'reason' => $error->getMessage()];
            }
            if ($configuration->values === null) {
                $description['role'] = $description['application_kind'] = $description['module'] = null;
                $description['provenance'] = ['classification' => 'unresolved_configuration', 'module' => 'unresolved_configuration'];
            }
            $id = strtolower($symbol->name);
            if (isset($symbols[$id])) {
                $symbols[$id]['ambiguous'] = true;
                $notices[] = ['path' => $symbol->path, 'line' => $symbol->line, 'reason' => 'Duplicate symbol declaration: '.$symbol->name];

                continue;
            }
            $symbols[$id] = ['name' => $symbol->name, 'path' => $symbol->path, 'line' => $symbol->line, ...$description,
                'shape' => $entries[$symbol->path]['shapes'][$id] ?? RevisionIdentity::shape($source->files[$symbol->path]),
                'auto_pair' => $symbol->hasMethods && $symbol->kind !== 'file'];
        }
        foreach ($entries as $path => $entry) {
            if (self::symbolBudgetReached(count($symbols))) {
                $notices[] = ['path' => $path, 'line' => 1, 'reason' => 'Revision symbol count or memory budget reached.'];
                break;
            }
            $fileId = strtolower('(file) '.$path);
            $symbols[$fileId] ??= ['name' => '(file) '.$path, 'path' => $path, 'line' => 1, 'php_kind' => 'file',
                'role' => 'unknown', 'application_kind' => null, 'module' => null, 'module_parents' => [],
                'shape' => RevisionIdentity::shape($source->files[$path]), 'auto_pair' => true];
            foreach ($entry['entry']->impact->classes ?? [] as $name => $class) {
                $owner = $symbols[strtolower($name)] ?? null;
                if ($owner === null) {
                    continue;
                }
                foreach ($class['methods'] as $method) {
                    if (self::symbolBudgetReached(count($symbols))) {
                        $notices[] = ['path' => $path, 'line' => $method['line'], 'reason' => 'Revision symbol count or memory budget reached.'];
                        break 3;
                    }
                    $methodId = strtolower($name.'::'.$method['name']);
                    $symbols[$methodId] = [...$owner, 'name' => $name.'::'.$method['name'], 'line' => $method['line'],
                        'php_kind' => 'method', 'signature' => $method['signature'], 'shape' => hash('sha256', serialize($method['signature'])), 'auto_pair' => false];
                }
            }
        }
        $structureComplete = $notices === [];
        $inputs = new SnapshotInputs($source);
        $files = new Filesystem;
        // The virtual root normalizes __DIR__/base_path in declarations. It is never read or written.
        $base = '/__architecture_revision__';
        $http = (new HttpRouteDiscovery($files, $base, true, $inputs))->discover($graph, $configuration->excludes());
        $data = DataAnalysis::collect($files, $base, $graph, $configuration->excludes(), $http, $inputs);
        $ruleSources = self::ruleSources($configuration->values['rules'] ?? [], $data, $source);
        foreach ($ruleSources as $rule) {
            if ($rule['status'] !== 'declared') {
                $notices[] = ['path' => $rule['path'] ?? '', 'line' => 1, 'reason' => 'Custom rule source unresolved: '.$rule['name']];
            }
        }
        $commonComplete = $source->notices === [] && $configuration->notices === [];
        $channels = [
            'structure' => $structureComplete, 'transitions' => $structureComplete,
            'http' => $commonComplete && $http['notices'] === [] && ! $http['limited'],
            'execution' => $commonComplete && $data->facts['notices'] === [] && $data->links->notices === [] && $data->links->unknown === [] && ! $data->limited,
            'data' => $commonComplete && $data->facts['notices'] === [] && $data->catalog->notices === [] && $data->extractor->notices === [] && ! $data->limited,
            'rule_sources' => $commonComplete && count(array_filter($ruleSources, static fn (array $rule): bool => $rule['status'] !== 'declared')) === 0,
        ];
        foreach ($data->links->unknown as $from => $unknown) {
            foreach ($unknown as $witness) {
                $notices[] = ['code' => 'E_REVISION_EXECUTION_UNKNOWN', 'from' => $from, ...$witness];
            }
        }
        array_push($notices, ...$http['notices'], ...$data->facts['notices'], ...$data->catalog->notices, ...$data->extractor->notices, ...$data->links->notices);
        if ($http['limited'] || $data->limited) {
            $notices[] = ['path' => '', 'line' => 1, 'reason' => 'Revision execution or DATA budget reached; known facts are partial.'];
        }

        return new self($source, $configuration, $graph, $symbols, $http, $data, $entries, $notices,
            ['parsed_files' => $parsed, 'reused_files' => $reused, 'elapsed_ms' => (hrtime(true) - $started) / 1_000_000], $channels, $ruleSources);
    }

    /** @phpstan-impure Memory availability changes as symbols are allocated. */
    private static function symbolBudgetReached(int $count): bool
    {
        return $count >= 10000 || ImpactExtractor::sourceLimit(0) !== null;
    }

    /** @param array<int|string, mixed> $rules
     * @return array<string, array<string, mixed>> */
    private static function ruleSources(array $rules, DataAnalysis $data, SourceSnapshot $source): array
    {
        $names = [];
        foreach ($rules as $value) {
            foreach (is_array($value) ? $value : [$value] as $rule) {
                if (is_string($rule)) {
                    $names[strtolower($rule)] = $rule;
                }
            }
        }
        $result = [];
        foreach ($names as $key => $name) {
            $class = $data->facts['classes'][$key] ?? null;
            $path = $class['path'] ?? null;
            $contents = is_string($path) ? ($source->files[$path] ?? null) : null;
            $result[$key] = ['name' => $name, 'path' => $path, 'line' => $class['line'] ?? null,
                'shape' => $contents === null ? null : RevisionIdentity::shape($contents),
                'status' => $contents === null ? 'unresolved' : 'declared'];
        }

        return $result;
    }
}
