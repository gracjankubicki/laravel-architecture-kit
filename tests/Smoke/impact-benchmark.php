<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$files = new Filesystem;
if (($argv[1] ?? '') === '--query') {
    $base = $argv[2];
    $mode = $argv[3];
    $cache = $mode === 'disabled' ? null : new ProjectGraphCache($files, $base);
    if ($mode === 'cold') {
        $files->deleteDirectory($base.'/storage/framework/cache/architecture-kit');
    }
    $start = hrtime(true);
    $result = (new ArchitectureImpact($files, $base, new AuditScope, $cache))->inspect('Payment::charge', limit: 100, depth: 8, change: 'signature', signature: 'public static function charge(int $amount, string $currency): void');
    if (! $result['ok'] || $result['signature']['total']['breaking'] !== 40) {
        throw new RuntimeException('Benchmark fixture contract failed.');
    }
    echo json_encode(['php' => PHP_VERSION, 'mode' => $mode, 'files' => 41, 'elapsed_ms' => (hrtime(true) - $start) / 1000000, 'peak_bytes' => memory_get_peak_usage(true), 'cache' => $result['cache'], 'breaking' => $result['signature']['total']['breaking']], JSON_THROW_ON_ERROR)."\n";
    exit;
}
$base = sys_get_temp_dir().'/architecture-impact-benchmark-'.bin2hex(random_bytes(6));
$files->ensureDirectoryExists($base.'/app');
$files->put($base.'/composer.json', '{}');
$files->put($base.'/app/Payment.php', '<?php namespace App; class Payment { public static function charge(int $amount): void {} }');
for ($i = 0; $i < 40; $i++) {
    $files->put($base.'/app/Caller'.$i.'.php', '<?php namespace App; class Caller'.$i.' { public function run() { Payment::charge(1); } }');
}
try {
    foreach (['cold' => 3, 'warm' => 10, 'disabled' => 3] as $mode => $runs) {
        for ($i = 0; $i < $runs; $i++) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--query', $base, $mode]);
            $process->mustRun();
            echo $process->getOutput();
        }
    }
} finally {
    $files->deleteDirectory($base);
}
