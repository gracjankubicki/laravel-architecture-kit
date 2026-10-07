<?php

declare(strict_types=1);

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['worker:', 'root:', 'stage:', 'scope:', 'subject:', 'sizes:', 'trials:', 'memory-limit:', 'output:']);
$files = new Filesystem;

/** Each measurement runs in its own process, including cache reads and peak memory. */
if (isset($options['worker'])) {
    $root = $options['root'];
    $catalog = $options['worker'] === 'catalog';
    $scope = new AuditScope(explode(',', $options['scope']));
    $cache = $options['stage'] === 'disabled' ? null : new ProjectGraphCache($files, $root);
    $parseCalls = [];
    $onParse = static function (string $path) use (&$parseCalls): void {
        $parseCalls[$path] = ($parseCalls[$path] ?? 0) + 1;
    };
    $loader = new ProjectGraphLoader($files, $root, $scope, $cache, sourceOnly: true, catalog: $catalog, onParse: $onParse);
    $start = hrtime(true);
    $plan = $loader->plan();
    $planMs = (hrtime(true) - $start) / 1e6;
    $graph = $loader->build($plan);
    $buildMs = (hrtime(true) - $start) / 1e6;
    $row = ['stage' => $options['stage'], 'plan_ms' => $planMs, 'build_ms' => $buildMs,
        'file_count' => count($plan->files), 'planned_php_parses' => count(array_filter($plan->toParse, fn ($path) => str_ends_with($path, '.php'))),
        'actual_php_parses' => array_sum(array_filter($parseCalls, fn ($path) => str_ends_with($path, '.php'), ARRAY_FILTER_USE_KEY)),
        'max_parses_per_file' => $parseCalls === [] ? 0 : max($parseCalls),
        'reused_files' => count($plan->reusable), 'cache_status' => $plan->cacheStatus->value];
    $row['planned_files'] = $plan->toParse;
    if ($catalog && $options['stage'] === 'warm') {
        foreach ($graph->catalogFacts as $facts) {
            if (in_array($facts->path, $plan->toParse, true)) {
                $row['reparse_diagnostics'][$facts->path] = array_map(fn ($diagnostic) => $diagnostic->toArray(), $facts->diagnostics);
            }
        }
    }
    if ($catalog) {
        $composeStart = hrtime(true);
        $index = new CatalogIndex($graph->catalogFacts);
        $row['compose_ms'] = (hrtime(true) - $composeStart) / 1e6;
        $row['total_ms'] = (hrtime(true) - $start) / 1e6;
        $row['nodes'] = count($index->elements);
        $row['edges'] = count($index->relations);
        $canonical = [$index->elements, $index->relations, $index->diagnostics];
        $row['facts_sha256'] = hash('sha256', serialize($canonical));
        unset($canonical);
        $snapshot = hash('sha256', serialize($plan->signature->toArray()));
        $subjectName = $options['subject'];
        $targetName = str_contains($subjectName, 'Bench\\') ? str_replace('C00000', 'C00010', $subjectName)
            : 'GracjanKubicki\\ArchitectureKit\\Catalog\\CatalogKinds::all';
        $subjectIds = $index->names[strtolower($subjectName)] ?? [];
        $targetIds = $index->names[strtolower($targetName)] ?? [];
        if (count($subjectIds) !== 1 || count($targetIds) !== 1) {
            throw new RuntimeException('Benchmark requires unambiguous source method IDs.');
        }
        $row['query_selectors'] = ['subject' => $subjectIds[0], 'target' => $targetIds[0]];
        $queries = [
            'search' => fn () => (new GraphSearch($index))->find(str_contains($subjectName, 'Bench\\') ? 'C00' : 'Graph'),
            'path' => fn () => (new GraphQuery($index))->query($subjectIds[0], 'path', $targetIds[0], 20),
            'impact' => fn () => (new GraphQuery($index))->query($subjectIds[0], 'impact', null, 20),
        ];
        foreach ($queries as $name => $query) {
            $queryStart = hrtime(true);
            $result = $query();
            $envelope = GraphPage::make($name === 'search' ? 'architecture-search' : 'architecture-graph', ['limit' => 20], $snapshot,
                $scope->directories, $index->diagnostics, $result, $name === 'search' ? 'candidates' : 'records');
            $bytes = strlen(json_encode($envelope, JSON_THROW_ON_ERROR));
            if ($bytes > GraphPage::MAX_BYTES) {
                throw new RuntimeException('Query output exceeded the hard byte budget.');
            }
            $row['queries'][$name] = ['ms' => (hrtime(true) - $queryStart) / 1e6, 'payload_bytes' => $bytes,
                'status' => $envelope['status'], 'truncated' => $envelope['truncated'], 'limits' => $envelope['limits']];
        }
        $row['catalog_estimated_bytes'] = array_sum(array_map(fn ($facts) => $facts->estimatedBytes(), $graph->catalogFacts));
    } else {
        $row['nodes'] = count($graph->symbols);
        $row['edges'] = count($graph->edges);
        $contextStart = hrtime(true);
        $legacySubject = explode('::', $options['subject'], 2)[0];
        $result = (new ArchitectureContext($files, $root, scope: $scope, cache: $cache))->inspect($legacySubject, []);
        $row['legacy_context_ms'] = (hrtime(true) - $contextStart) / 1e6;
        $row['legacy_context_payload_bytes'] = strlen(serialize($result));
    }
    $row['peak_memory_bytes'] = memory_get_peak_usage(true);
    $row['cache_bytes'] = 0;
    foreach ($files->isDirectory($root.'/'.ProjectGraphCache::DIRECTORY) ? $files->allFiles($root.'/'.ProjectGraphCache::DIRECTORY) : [] as $file) {
        $row['cache_bytes'] += $file->getSize();
    }
    echo json_encode($row, JSON_THROW_ON_ERROR)."\n";
    exit;
}

$sizes = explode(',', $options['sizes'] ?? '1000,10000,repo');
$trials = (int) ($options['trials'] ?? 5);
if ($trials < 1 || $trials > 10 || array_diff($sizes, ['1000', '10000', 'repo']) !== []) {
    throw new InvalidArgumentException('Use sizes=1000,10000,repo and trials=1..10.');
}
$memory = $options['memory-limit'] ?? '1024M';
$head = new Process(['git', 'rev-parse', 'HEAD'], dirname(__DIR__, 2));
$head->mustRun();
$report = ['environment' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'memory_limit' => $memory,
    'head' => trim($head->getOutput()),
    'source_fingerprint' => PackageFingerprint::current(), 'trials' => $trials,
    'benchmark_sha256' => hash_file('sha256', __FILE__),
    'parse_count_kind' => 'Observed FileContext parser calls during graph build; planned files reported separately'], 'datasets' => []];
$work = sys_get_temp_dir().'/architecture-graph-benchmark-'.bin2hex(random_bytes(8));
$files->ensureDirectoryExists($work);
try {
    foreach ($sizes as $size) {
        $root = $work.'/'.$size;
        $files->ensureDirectoryExists($root.'/app');
        $scope = 'app';
        $subject = 'Bench\\C00000::run';
        if ($size === 'repo') {
            $files->copyDirectory(dirname(__DIR__, 2).'/src', $root.'/src');
            $scope = 'app,src';
            $subject = 'GracjanKubicki\\ArchitectureKit\\Context\\GraphSearch::find';
            $edit = $root.'/src/Context/GraphSearch.php';
        } else {
            for ($number = 0; $number < (int) $size; $number++) {
                $name = sprintf('C%05d', $number);
                $next = sprintf('C%05d', ($number + 1) % (int) $size);
                $data = $number % 10 === 0 ? "\\Illuminate\\Support\\Facades\\DB::table('records')->get();" : '';
                $files->put($root.'/app/'.$name.'.php', '<?php namespace Bench; final class '.$name.' { public function run() { (new '.$next.')->run(); '.$data.' } }');
            }
            $edit = $root.'/app/C00000.php';
        }
        $original = $files->get($edit);
        $inputs = [];
        foreach ($files->allFiles($root) as $input) {
            $inputs[$input->getRelativePathname()] = hash_file('sha256', $input->getPathname());
        }
        ksort($inputs);
        $inputHash = hash('sha256', serialize($inputs));
        $runs = [];
        for ($trial = 1; $trial <= $trials; $trial++) {
            foreach (['catalog', 'legacy'] as $mode) {
                $files->deleteDirectory($root.'/storage');
                $files->put($edit, $original);
                clearstatcache();
                foreach (['cold', 'warm', 'edit', 'disabled'] as $stage) {
                    if ($stage === 'edit') {
                        $files->append($edit, "\n// benchmark edit ".$trial);
                        clearstatcache();
                    }
                    $process = new Process([PHP_BINARY, '-d', 'memory_limit='.$memory, __FILE__, '--worker='.$mode,
                        '--root='.$root, '--stage='.$stage, '--scope='.$scope, '--subject='.$subject]);
                    $process->setTimeout(600);
                    $process->mustRun();
                    $row = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                    $row['trial'] = $trial;
                    $row['mode'] = $mode;
                    $runs[] = $row;
                    $failure = null;
                    if ($mode === 'catalog') {
                        if ($stage === 'cold' || $stage === 'edit') {
                            $referenceHash = $row['facts_sha256'];
                        } elseif ($row['facts_sha256'] !== $referenceHash) {
                            $failure = 'Cold/warm or edited/disabled catalog parity failed.';
                        }
                    }
                    if ($stage === 'warm' && $row['planned_php_parses'] !== 0 || $stage === 'edit' && $row['planned_php_parses'] !== 1) {
                        $failure = 'Cache parse gate failed.';
                    }
                    if ($row['actual_php_parses'] !== $row['planned_php_parses'] || $row['max_parses_per_file'] > 1) {
                        $failure = 'Actual parser count or single-parse gate failed.';
                    }
                    if ($failure !== null) {
                        $report['datasets'][$size] = ['input_sha256' => $inputHash, 'runs' => $runs, 'failure' => $failure];
                        if (isset($options['output'])) {
                            $files->put($options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                        }
                        throw new RuntimeException($failure.' dataset='.$size.' trial='.$trial.' mode='.$mode.' stage='.$stage
                            .' planned_php_parses='.$row['planned_php_parses'].' cache_status='.$row['cache_status']
                            .' actual_php_parses='.$row['actual_php_parses'].' max_parses_per_file='.$row['max_parses_per_file']
                            .' report='.($options['output'] ?? '(use --output to retain diagnostic details)'));
                    }
                    fwrite(STDERR, $size.' trial '.$trial.' '.$mode.' '.$stage.' '.round($row['build_ms'])."ms\n");
                }
            }
        }
        $medians = [];
        foreach (['catalog', 'legacy'] as $mode) {
            foreach (['cold', 'warm', 'edit', 'disabled'] as $stage) {
                $selected = array_values(array_filter($runs, fn ($row) => $row['mode'] === $mode && $row['stage'] === $stage));
                foreach (['build_ms', 'total_ms', 'peak_memory_bytes', 'cache_bytes', 'legacy_context_ms'] as $metric) {
                    $values = array_column($selected, $metric);
                    if ($values === []) {
                        continue;
                    }
                    sort($values);
                    $middle = intdiv(count($values), 2);
                    $medians[$mode][$stage][$metric] = count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
                }
                if ($mode === 'catalog') {
                    foreach (['search', 'path', 'impact'] as $query) {
                        foreach (['ms', 'payload_bytes'] as $metric) {
                            $values = array_map(fn ($row) => $row['queries'][$query][$metric], $selected);
                            sort($values);
                            $middle = intdiv(count($values), 2);
                            $medians[$mode][$stage]['queries'][$query][$metric] = count($values) % 2 === 0
                                ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
                        }
                    }
                }
            }
        }
        $report['datasets'][$size] = ['input_sha256' => $inputHash, 'runs' => $runs, 'medians' => $medians];
        if (isset($options['output'])) {
            $files->put($options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (isset($options['output'])) {
        $files->put($options['output'], $json);
    } else {
        echo $json;
    }
} finally {
    $files->deleteDirectory($work);
}
