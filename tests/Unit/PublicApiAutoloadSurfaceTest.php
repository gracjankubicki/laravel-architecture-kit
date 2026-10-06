<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\PublicApi\AutoloadSurface;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublicApiAutoloadSurfaceTest extends TestCase
{
    public function test_psr4_maps_names_and_files_without_loading_standalone_functions(): void
    {
        $surface = $this->surface(['psr-4' => ['Demo\\' => ['src', 'fallback']]], [
            'src/Api.php', 'src/Helpers/functions.php', 'fallback/Other.php', 'tests/Test.php',
        ]);
        $this->assertTrue($surface->allowsClass('src/Api.php', 'Demo\\Api'));
        $this->assertTrue($surface->allowsClass('fallback/Other.php', 'Demo\\Other'));
        $this->assertFalse($surface->allowsClass('src/Api.php', 'Other\\Api'));
        $this->assertFalse($surface->allowsClass('src/Api.php', 'Demo\\Misplaced'));
        $this->assertFalse($surface->allowsStandalone('src/Helpers/functions.php'));
        $this->assertArrayNotHasKey('tests/Test.php', $surface->files);
        $this->assertSame([], $surface->notices);
    }

    public function test_psr0_supports_namespaces_underscores_and_fallback_root(): void
    {
        $surface = $this->surface(['psr-0' => ['Demo' => 'lib', '' => 'fallback']], [
            'lib/Demo/Tools/Api.php', 'lib/Demo/Legacy/Api.php', 'fallback/Old/Api.php',
        ]);
        $this->assertTrue($surface->allowsClass('lib/Demo/Tools/Api.php', 'Demo\\Tools\\Api'));
        $this->assertTrue($surface->allowsClass('lib/Demo/Legacy/Api.php', 'Demo_Legacy_Api'));
        $this->assertTrue($surface->allowsClass('fallback/Old/Api.php', 'Old_Api'));
        $this->assertFalse($surface->allowsClass('lib/Demo/Tools/Api.php', 'Demo\\Wrong'));
    }

    public function test_classmap_globs_and_exclusions_do_not_limit_psr_loading(): void
    {
        $surface = $this->surface([
            'classmap' => ['legacy/*', 'lib/**/*.php'],
            'exclude-from-classmap' => ['/legacy/**/Tests/', 'lib/Hidden.php'],
            'psr-4' => ['Demo\\' => 'lib'],
        ], ['legacy/A/Old.php', 'legacy/A/Tests/Nested/Test.php', 'lib/Hidden.php', 'lib/deep/Extra.php']);
        $this->assertTrue($surface->allowsClass('legacy/A/Old.php', 'AnyName'));
        $this->assertArrayNotHasKey('legacy/A/Tests/Nested/Test.php', $surface->files);
        $this->assertTrue($surface->allowsClass('lib/Hidden.php', 'Demo\\Hidden'));
        $this->assertFalse($surface->allowsClass('lib/Hidden.php', 'AnyName'));
        $this->assertTrue($surface->allowsClass('lib/deep/Extra.php', 'AnyName'));
    }

    public function test_files_enable_functions_and_production_scope_ignores_dev(): void
    {
        $composer = ['autoload' => ['files' => ['helpers.php']], 'autoload-dev' => ['classmap' => ['tests']]];
        $snapshot = new SourceSnapshot('git', 'abc', 'hash', [
            'composer.json' => json_encode($composer, JSON_THROW_ON_ERROR),
            'helpers.php' => '<?php function helper() {}', 'tests/Test.php' => '<?php class Test {}',
        ], [], ['helpers.php', 'tests/Test.php', 'composer.json']);
        $surface = new AutoloadSurface($snapshot);
        $this->assertTrue($surface->allowsStandalone('helpers.php'));
        $this->assertTrue($surface->allowsClass('helpers.php', 'AnyName'));
        $this->assertArrayNotHasKey('tests/Test.php', $surface->files);
    }

    public function test_public_areas_are_literal_boundaries_and_keep_mapping_semantics(): void
    {
        $surface = $this->surface(['psr-4' => ['Demo\\' => 'src']], ['src/Public/Api.php', 'src/Private/Secret.php'], ['src/Public']);
        $this->assertTrue($surface->allowsClass('src/Public/Api.php', 'Demo\\Public\\Api'));
        $this->assertArrayNotHasKey('src/Private/Secret.php', $surface->files);
        $this->expectException(InvalidArgumentException::class);
        $this->surface(['classmap' => ['src']], ['src/Api.php'], ['../outside']);
    }

    public function test_dynamic_unsupported_missing_and_invalid_sources_remain_explicit(): void
    {
        $surface = $this->surface([
            'psr-4' => ['BadPrefix' => 'src', 'Demo\\' => '../outside'],
            'classmap' => ['missing'], 'files' => ['absent.php'], 'custom' => ['x'],
        ], ['src/Api.php']);
        $this->assertGreaterThanOrEqual(5, count($surface->notices));
        $this->assertSame([], $surface->files);
        $missing = new AutoloadSurface(new SourceSnapshot('git', 'abc', 'hash', ['composer.json' => '{'], [], ['composer.json']));
        $this->assertNotEmpty($missing->notices);
        $unread = new AutoloadSurface(new SourceSnapshot('git', 'abc', 'hash', [
            'composer.json' => '{"autoload":{"classmap":["src"]}}',
        ], [], ['composer.json', 'src/Unread.php']));
        $this->assertSame('src/Unread.php', $unread->notices[0]['path']);
        $this->assertSame([], $unread->files);
    }

    /** @param array<string, mixed> $autoload
     * @param list<string> $paths
     * @param list<string> $areas */
    private function surface(array $autoload, array $paths, array $areas = []): AutoloadSurface
    {
        $files = array_fill_keys($paths, '<?php');
        $files['composer.json'] = json_encode(['autoload' => $autoload], JSON_THROW_ON_ERROR);

        return new AutoloadSurface(new SourceSnapshot('git', 'abc', 'hash', $files, [], array_keys($files)), $areas);
    }
}
