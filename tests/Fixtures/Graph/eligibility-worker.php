<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\GraphBuildEligibility;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphContributionStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Filesystem\Filesystem;

if ($argv[1] === 'outside') {
    $autoload = [];
    spl_autoload_register(static function (string $class) use (&$autoload): void {
        $autoload[] = $class;
    });
    ImpactExtractor::sourceLimit(100001);
    ComposerCatalogExtractor::sourceLimit(4000001);
    echo json_encode(['scope_loaded' => class_exists(GraphBuildEligibility::class, false),
        'session_loaded' => class_exists(AnalysisRuntime::class, false), 'autoload' => $autoload], JSON_THROW_ON_ERROR), "\n";
    exit;
}
$scope = GraphBuildEligibility::begin();
// A deliberately small test process, not a raised product budget.
$padding = str_repeat('x', 44 * 1024 * 1024);
$impact = ImpactExtractor::sourceLimit(0);
$composer = ComposerCatalogExtractor::sourceLimit(0);
if ($impact === null || $composer === null) {
    throw new RuntimeException('The controlled process did not reach its memory guard');
}
$store = new GraphContributionStore(new GraphSegmentStore(new Filesystem, $argv[2]));
$store->write(new CachedGraph(new GraphCacheSignature('fixture', []), []));
echo json_encode(['impact' => ImpactExtractor::sourceLimitReason(0)?->value, 'composer' => ComposerCatalogExtractor::sourceLimitReason(0)?->value,
    'eligibility' => $scope->snapshot(), 'write' => $store->lastWrite, 'io_directory_exists' => is_dir($argv[2])], JSON_THROW_ON_ERROR), "\n";
$scope->close();
