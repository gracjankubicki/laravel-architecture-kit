<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\ProcessSamples;

// Private author-test observer, 30 seconds / 4 MiB hard bounds, no signals or data mutations.
[$script, $root, $output, $stop] = $argv;
$begin = hrtime(true);
$known = [];
$prior = null;
$bytes = 0;
CaptureStore::writePrivate($output, '');
$stream = fopen($output, 'ab');
while (! is_file($stop) && hrtime(true) - $begin < 30000000000) {
    $at = hrtime(true);
    $sample = ProcessSamples::observe((int) $root, $known, getmypid());
    $known = $sample['known'];
    unset($sample['known']);
    $sample['actual_interval_ns'] = $prior === null ? null : $at - $prior;
    $sample['root_role'] = 'php-native-graph-test';
    $sample['coverage']['phase_peak'] = 'short phases may fall between samples; OS runtime lifetime peak recorded separately';
    $prior = $at;
    $line = TypedValue::json($sample)."\n";
    if ($bytes + strlen($line) > 4194304) {
        exit(2);
    }
    CaptureStore::writeStream($stream, $line);
    $bytes += strlen($line);
    usleep((int) max(0, (50000000 - (hrtime(true) - $at)) / 1000));
}
fclose($stream);
