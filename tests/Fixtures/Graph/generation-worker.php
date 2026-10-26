<?php

declare(strict_types=1);

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\FreshGraphProcessRequired;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use Illuminate\Filesystem\Filesystem;

require dirname(__DIR__, 3).'/vendor/autoload.php';
[$script, $root, $action, $output] = $argv;
$parses = [];
$stages = [];
$observer = static function (string $stage) use (&$stages, $action): void {
    $stages[] = $stage;
    if ($action === 'restore') {
        throw new RuntimeException('Restore ran a composition/resolver stage: '.$stage);
    }
};
$onParse = static function (string $path) use (&$parses, $action): void {
    $parses[] = $path;
    if ($action === 'restore') {
        throw new RuntimeException('Restore parsed '.$path);
    }
};
$backend = getenv('GRAPH_TEST_ENGINE') ?: (getenv('ARCHITECTURE_KIT_SUITE_ENGINE') === 'mago'
    ? getenv('ARCHITECTURE_KIT_TEST_ENGINE') : 'php');
if (! is_string($backend) || $backend === '') {
    throw new RuntimeException('The Mago graph worker requires ARCHITECTURE_KIT_TEST_ENGINE.');
}
$engine = $backend === 'php' ? new PhpAnalysisEngine : new MagoAnalysisEngine($backend);
AnalysisRuntime::activate(new AnalysisSession($engine, getenv('GRAPH_TEST_PHP') ?: '8.5', AnalysisMode::Basic, $observer));
$files = new Filesystem;
$started = hrtime(true);
if ($action === 'oracle') {
    $settings = CatalogSettings::load($files, $root, $onParse);
    $loader = new ProjectGraphLoader($files, $root, $settings->scope, configuration: $settings->fingerprint,
        sourceOnly: true, catalog: true, onParse: $onParse);
    $plan = $loader->plan($settings->exclude);
    $facts = $loader->build($plan)->catalogFacts;
    $index = new CatalogIndex($facts, SourceManifest::sourceReader($files, $root, $plan->signature->files));
    foreach ($settings->diagnostics as $diagnostic) {
        $index->diagnostics[] = [...$diagnostic->toArray(), 'path' => 'config/architectures.php'];
    }
    $result = ['index' => $index, 'analysis' => ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => $index->checksComplete()], 'changed' => false];
} else {
    try {
        $result = (new GraphSource($files, $root, $onParse))->load($argv[4] ?? null);
    } catch (FreshGraphProcessRequired $error) {
        file_put_contents($output, json_encode(['fresh_required' => true, 'reason' => $error->reason,
            'generation' => $error->rejectedGeneration, 'store' => $error->store, 'product' => $error->product,
            'pid' => getmypid(), 'parses' => $parses, 'stages' => $stages], JSON_THROW_ON_ERROR));
        exit(75);
    }
    $index = $result['index'];
}
$elapsed = (hrtime(true) - $started) / 1e9;
$query = new GraphQuery($index);
$search = new GraphSearch($index);
$answers = [];
foreach (['App\\Child', 'App\\run', 'App\\Ghost', 'App\\UnresolvedOther', 'App\\Worker', 'App\\Worker::perform'] as $subject) {
    foreach (['context', 'impact'] as $mode) {
        $answers[$subject.':'.$mode] = $query->query($subject, $mode);
    }
}
$answers['path'] = $query->query('App\\Child', 'path', 'App\\Base');
$answers['search'] = $search->find('App');
foreach ([0, 1] as $offset) {
    $answers['page-'.($offset + 1)] = GraphPage::make('architecture-search', ['limit' => 1, 'offset' => $offset, 'snapshot' => 'query-fixture'],
        'query-fixture', ['app', 'tests'], $index->diagnostics, $answers['search'], 'candidates');
}
$contents = [];
foreach ($index->elements as $element) {
    if ($element['kind'] === 'file') {
        $contents[$element['path']] = ($index->sourceContents)($element['path']);
    }
}
$data = ['state' => $index->exportState(), 'answers' => $answers, 'contents' => $contents,
    'analysis' => $result['analysis'], 'changed' => $result['changed'], 'cache' => $result['cache'] ?? null,
    'snapshot' => $result['snapshot'] ?? null, 'pid' => getmypid(), 'parses' => $parses, 'stages' => $stages,
    'seconds' => $elapsed, 'memory' => ['used' => memory_get_usage(), 'allocated' => memory_get_usage(true), 'peak' => memory_get_peak_usage(true)]];
file_put_contents($output, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
