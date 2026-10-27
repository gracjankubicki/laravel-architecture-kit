<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use Illuminate\Filesystem\Filesystem;

/** Explicit cache clear only. Caller holds the repository GraphEpoch publication lock. */
final class NativeSnapshotStorage
{
    public static function clear(RepositoryContext $context): int
    {
        $removed = 0;
        $files = new Filesystem;
        $runtime = $context->storage.'/runtime';
        StateStore::assertBoundary($runtime, $context->home);
        foreach (glob($runtime.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || ! preg_match('/^[a-f0-9]{32}$/D', basename($directory))) {
                continue;
            }
            StateStore::assertBoundary($directory, $context->home);
            foreach (glob($directory.'/native-*', GLOB_ONLYDIR) ?: [] as $product) {
                if (is_link($product) || ! preg_match('/^native-[a-f0-9]{64}$/D', basename($product))) {
                    continue;
                }
                StateStore::assertBoundary($product, $context->home);
                $pointer = $product.'/current.json';
                if ((is_file($pointer) || is_link($pointer)) && $files->delete($pointer)) {
                    $removed++;
                }
                foreach (glob($product.'/snapshot-*', GLOB_ONLYDIR) ?: [] as $snapshot) {
                    if (is_link($snapshot) || ! preg_match('/^snapshot-[a-f0-9]{64}$/D', basename($snapshot))) {
                        continue;
                    }
                    StateStore::assertBoundary($snapshot, $context->home);
                    if ($files->deleteDirectory($snapshot)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }
}
