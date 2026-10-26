<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use Illuminate\Filesystem\Filesystem;

[$script, $directory, $action] = $argv;
$store = new GraphSegmentStore(new Filesystem, $directory);
$state = array_fill_keys(CatalogState::FIELDS, []);
$state['diagnostics'][] = ['code' => 'literal', 'message' => 'E:22:"CacheProbeEnum:Example"; O:8:"stdClass":0:{}'];
if ($action === 'write') {
    $result = $store->write(str_repeat('a', 64), $state, []);
    if ($result['status'] !== 'success') {
        throw new RuntimeException(json_encode($result));
    }
} else {
    $result = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
    if ($result['status'] !== 'hit' || CatalogIndex::fromState($result['state'])->exportState() !== $state) {
        throw new RuntimeException('Plain JSON roundtrip failed');
    }
    unset($result['state']);
}
echo json_encode(['zlib_available' => function_exists('gzdeflate') && function_exists('gzinflate'), 'result' => $result], JSON_THROW_ON_ERROR), "\n";
