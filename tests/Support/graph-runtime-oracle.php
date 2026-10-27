<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\JsonResponseChunks;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;

$context = new RepositoryContext($argv[1], $argv[2]);
RepositoryContext::activate($context);
AnalysisRuntime::activate(null);
Host::create($argv[2]);
$source = (new GraphSource(new Filesystem, $argv[1], mode: AnalysisMode::Basic))->load(fullComposition: true);
$file = fopen($argv[3], 'wb');
foreach (JsonResponseChunks::encode($source['index']->exportState()) as $chunk) {
    fwrite($file, $chunk);
}
fclose($file);
fwrite(STDOUT, json_encode(['cache' => $source['cache'], 'analysis' => $source['analysis'], 'memory_limit' => ini_get('memory_limit'),
    'used' => memory_get_usage(), 'allocated' => memory_get_usage(true), 'peak' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n");
