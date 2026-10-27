<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class NativeSnapshotStorageTest extends TestCase
{
    public function test_epoch_clear_counts_native_entries_and_does_not_follow_links_or_other_repository(): void
    {
        $root = realpath(sys_get_temp_dir()).'/kit-r2-storage-'.bin2hex(random_bytes(6));
        $home = $root.'-home';
        mkdir($root.'/.architecture-kit', 0700, true);
        file_put_contents($root.'/composer.json', '{"name":"fixture/clear"}');
        file_put_contents($root.'/.architecture-kit/settings.json', '{"analysis":{"engine":"php"},"graph_runtime":{"representation":"rust"}}');
        try {
            $context = new RepositoryContext($root, $home);
            RepositoryContext::activate($context);
            $product = $context->storage.'/runtime/'.str_repeat('a', 32).'/native-'.str_repeat('b', 64);
            $snapshot = $product.'/snapshot-'.str_repeat('c', 64);
            mkdir($snapshot, 0700, true);
            file_put_contents($product.'/current.json', '{}');
            file_put_contents($snapshot.'/payload', str_repeat('bytes', 100));
            mkdir($home.'/protected', 0700);
            file_put_contents($home.'/protected/sentinel', 'unchanged');
            symlink($home.'/protected', $product.'/snapshot-'.str_repeat('d', 64));
            symlink($home.'/protected', dirname($product).'/native-'.str_repeat('e', 64));
            $foreign = $home.'/repositories/'.str_repeat('f', 64).'/runtime/'.str_repeat('a', 32).'/native-'.str_repeat('b', 64);
            mkdir($foreign, 0700, true);
            file_put_contents($foreign.'/current.json', '{}');
            $cache = new ProjectGraphCache(new Filesystem, $root);
            self::assertSame(2, $cache->clear(), 'One native pointer and one native snapshot are two removed entries.');
            self::assertFileDoesNotExist($product.'/current.json');
            self::assertDirectoryDoesNotExist($snapshot);
            self::assertSame(0, $cache->clear());
            self::assertFileExists($foreign.'/current.json');
            self::assertSame('unchanged', file_get_contents($home.'/protected/sentinel'));
            self::assertCount(1, glob($home.'/protected/*'));
        } finally {
            RepositoryContext::activate(null);
            (new Filesystem)->deleteDirectory($root);
            (new Filesystem)->deleteDirectory($home);
        }
    }
}
