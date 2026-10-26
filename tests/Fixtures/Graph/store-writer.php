<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use Illuminate\Filesystem\Filesystem;

[$script, $directory, $stage, $input] = $argv;
$barrier = static function (string $point) use ($stage): void {
    if ($point === $stage) {
        fwrite(STDOUT, "BARRIER\n");
        fflush(STDOUT);
        if (fgets(STDIN) === false) {
            throw new RuntimeException('Barrier input closed before release');
        }
    }
};
$store = new GraphSegmentStore(new Filesystem, $directory, barrier: $barrier);
$state = json_decode(file_get_contents($input), true, 64, JSON_THROW_ON_ERROR);
echo json_encode($store->write(str_repeat('a', 64), $state, []), JSON_THROW_ON_ERROR), "\n";
