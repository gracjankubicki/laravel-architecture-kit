<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogProductSet;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;

[$script, $mode, $root, $home, $profile, $query, $diagnosticsPath] = $argv;
$context = new RepositoryContext($root, $home, $profile);
RepositoryContext::activate($context);
Host::create($home);
GraphRuntime::enterWorker();
$files = new Filesystem;
if ($mode === 'oracle') {
    // A fresh external home has no usable contributions/ready product. No resident fast path.
    $source = (new GraphSource($files, $root, mode: AnalysisMode::Basic))->load(fullComposition: true);
    if ($source['changed']) {
        throw new RuntimeException('Oracle inputs changed');
    }
    $index = $source['index'];
    $snapshot = $source['snapshot'];
    GraphRuntime::resident($source);
} elseif ($mode === 'inspect') {
    $settings = CatalogSettings::load($files, $root);
    if ($settings->discovery->cache === null) {
        throw new RuntimeException('No stored state for a complete comparison');
    }
    $pointers = glob((new GraphRuntime($context))->directory().'/product-*/active-*.json') ?: [];
    if (count($pointers) !== 1) {
        throw new RuntimeException('Expected one complete observed product set');
    }
    $pointer = $pointers[0];
    $store = new GraphSegmentStore($files, dirname($pointer));
    $read = $store->read(substr(basename($pointer), 7, -5), CatalogState::FIELDS,
        manifestFields: [...CatalogState::FIELDS, ...CatalogProductSet::DERIVED]);
    if ($read['status'] !== 'hit') {
        throw new RuntimeException('Measured generation has no usable ready state: '.json_encode($read));
    }
    array_push($read['state']['diagnostics'], ...($read['metadata']['settings_diagnostics'] ?? []));
    $index = CatalogIndex::fromState($read['state']);
    $snapshot = $read['metadata']['generation'];
} else {
    throw new RuntimeException('Unknown state measurement action');
}
$digests = [];
foreach ($index->exportState() as $field => $records) {
    $hash = hash_init('sha256');
    foreach ($records as $key => $record) {
        hash_update($hash, serialize([$key, $record]));
    }
    $digests[$field] = hash_final($hash);
}
$diagnosticsFile = fopen($diagnosticsPath, 'x');
foreach ($index->diagnostics as $ordinal => $diagnostic) {
    fwrite($diagnosticsFile, json_encode([$ordinal, $diagnostic], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
fclose($diagnosticsFile);
$result = ['diagnostics_file' => $diagnosticsPath, 'diagnostics_sha256' => hash_file('sha256', $diagnosticsPath), 'snapshot_id' => $snapshot, 'counts' => array_map('count', $index->exportState()), 'full_record_digests' => $digests,
    'diagnostics_by_code' => array_count_values(array_column($index->diagnostics, 'code')), 'php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true)];
if ($mode === 'inspect') {
    $result['storage_generation'] = $read['generation'];
    $result['negative_full_record_controls'] = [];
    foreach (['remove-fact' => 'elements', 'remove-diagnostic' => 'diagnostics', 'alter-position' => 'elements'] as $control => $field) {
        $hash = hash_init('sha256');
        $first = true;
        foreach ($index->exportState()[$field] as $key => $record) {
            if ($first) {
                $first = false;
                if ($control !== 'alter-position') {
                    continue;
                }
                $record['line']++;
            }
            hash_update($hash, serialize([$key, $record]));
        }
        $result['negative_full_record_controls'][] = ['control' => $control, 'field' => $field, 'digest' => hash_final($hash)];
    }
}
if ($mode === 'oracle') {
    $result['queries'] = [
        'cli' => (new ArchitectureDiscovery($files, $root))->search($query),
        'mcp' => GraphPage::make('architecture-search', ['query' => $query], $argv[7] ?? $snapshot, $source['scope'], $index->diagnostics,
            [...(new GraphSearch($index))->find($query), 'analysis' => $source['analysis']], 'candidates'),
    ];
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
