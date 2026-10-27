<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchBoundary;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WatchBoundaryTest extends TestCase
{
    public function test_separate_explicit_source_and_external_config_roots_and_missing_ancestors(): void
    {
        $scope = new WatchBoundary(['/repo/app', '/repo/Modules', '/repo/tests', '/shared/config.json', '/repo/composer.json']);
        foreach (['/repo/app/a.php', '/repo/Modules/a.php', '/repo/tests/a.php', '/shared/config.json', '/repo/composer.json', '/repo'] as $path) {
            self::assertTrue($scope->accepts($path));
        }
        foreach (['/other/app/a.php', '/repo/vendor/a.php', '/repo/runtime/routes.php', '/shared/secret', '/repo/app-two/a.php'] as $path) {
            self::assertFalse($scope->accepts($path));
        }
    }

    public function test_symlink_retarget_is_rejected_before_any_source_read(): void
    {
        $root = realpath(sys_get_temp_dir()).'/watch-boundary-'.bin2hex(random_bytes(6));
        mkdir($root.'/repo', 0700, true);
        mkdir($root.'/other', 0700, true);
        try {
            $scope = new WatchBoundary([$root.'/repo/missing/deep']);
            symlink($root.'/other', $root.'/repo/missing');
            $this->expectException(RuntimeException::class);
            $scope->accepts($root.'/repo/missing/deep/file.php');
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
