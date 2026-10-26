<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\ConfigurationResolver;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationResolverTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $home;

    protected function setUp(): void
    {
        mkdir($this->directory = sys_get_temp_dir().'/kit-config-selection-'.bin2hex(random_bytes(8)), 0700);
        $this->directory = realpath($this->directory);
        mkdir($this->root = $this->directory.'/repo');
        file_put_contents($this->root.'/composer.json', '{}');
        $this->home = $this->directory.'/home';
        (new Projects($this->home))->register($this->root, ['actions']);
        mkdir($this->root.'/.architecture-kit');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_own_json_wins_without_merging_registered_settings(): void
    {
        file_put_contents($this->root.'/.architecture-kit/settings.json', '{"enabled":["services"]}');
        $selection = (new ConfigurationResolver($this->home))->resolve($this->root);
        self::assertSame(['enabled' => ['services']], $selection->settings);
        self::assertSame('repository-json', $selection->kind);
        self::assertFalse($selection->inherited());
        self::assertSame($this->root, $selection->ownerRoot);
    }

    public function test_named_profile_is_explicit_and_exact(): void
    {
        (new Projects($this->home))->register($this->root, ['services'], 'personal');
        $resolver = new ConfigurationResolver($this->home);
        self::assertSame(['actions'], $resolver->resolve($this->root)->settings['enabled']);
        self::assertSame(['services'], $resolver->resolve($this->root, 'personal')->settings['enabled']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requested profile');
        $resolver->resolve($this->root, 'missing');
    }

    public function test_invalid_selected_json_does_not_fall_back(): void
    {
        file_put_contents($this->root.'/.architecture-kit/settings.json', 'null');
        $this->expectException(RuntimeException::class);
        (new ConfigurationResolver($this->home))->resolve($this->root);
    }

    public function test_dynamic_php_is_not_executed(): void
    {
        mkdir($this->root.'/config');
        $marker = $this->directory.'/executed';
        file_put_contents($this->root.'/config/architectures.php', '<?php file_put_contents('.var_export($marker, true).', "executed"); return [];');
        try {
            (new ConfigurationResolver($this->home))->resolve($this->root);
            self::fail('Dynamic configuration must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist($marker);
        }
    }

    public function test_dangling_link_is_invalid_not_missing(): void
    {
        symlink($this->directory.'/missing', $this->root.'/.architecture-kit/settings.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        (new ConfigurationResolver($this->home))->resolve($this->root);
    }

    public function test_source_size_boundary_and_identity(): void
    {
        $path = $this->root.'/.architecture-kit/settings.json';
        file_put_contents($path, '{}'.str_repeat(' ', 99998));
        $resolver = new ConfigurationResolver($this->home);
        $first = $resolver->resolve($this->root);
        self::assertSame(hash_file('sha256', $path), $first->sourceHash);
        self::assertSame($first->identity(), $resolver->resolve($this->root)->identity());
        file_put_contents($path, '{}'.str_repeat(' ', 99999));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('100 KB');
        $resolver->resolve($this->root);
    }

    public static function unsearchableDirectories(): array
    {
        return ['no permissions' => [0000], 'read without search' => [0600]];
    }

    #[DataProvider('unsearchableDirectories')]
    public function test_unsearchable_configuration_directory_never_falls_back(int $permissions): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory search permissions require a non-root user.');
        }
        $directory = $this->root.'/.architecture-kit';
        file_put_contents($directory.'/settings.json', '{"enabled":["services"]}');
        chmod($directory, $permissions);
        clearstatcache();
        try {
            self::assertFalse(is_executable($directory));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not searchable');
            (new ConfigurationResolver($this->home))->resolve($this->root);
        } finally {
            chmod($directory, 0700);
            clearstatcache();
        }
    }
}
