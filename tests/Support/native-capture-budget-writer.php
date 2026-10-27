<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\OutputBudget;

[$script, $directory, $limit, $bytes, $name, $barrier] = $argv;
$budget = new OutputBudget($directory, (int) $limit, reserve: 0);
file_put_contents($barrier.'.'.$name.'.ready', 'ready');
$deadline = hrtime(true) + 5000000000;
while (! is_file($barrier.'.go')) {
    if (hrtime(true) > $deadline) {
        exit(4);
    }
    usleep(1000);
}
try {
    CaptureStore::writePrivate($directory.'/'.$name, str_repeat('x', (int) $bytes));
    echo "written\n";
} catch (RuntimeException $error) {
    echo $error->getMessage()."\n";
} finally {
    $budget->close();
}
