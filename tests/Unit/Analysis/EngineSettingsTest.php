<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\EngineSettings;
use GracjanKubicki\ArchitectureKit\Analysis\ProjectPhpVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EngineSettingsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function constraints(): iterable
    {
        yield 'caret' => ['^8.3', '8.3.0'];
        yield 'branches' => ['^7.4 || ^8.1', '7.4.0'];
        yield 'patch' => ['>=8.2.5 <8.3', '8.2.5'];
        yield 'exclusive' => ['>8.2.0 <8.3', '8.2.1'];
        yield 'excluded-patch' => ['^8.3 !=8.3.0', '8.3.1'];
        yield 'exact' => ['8.3.15', '8.3.15'];
    }

    #[DataProvider('constraints')]
    public function test_version_comes_from_composer_not_host(string $constraint, string $expected): void
    {
        $version = ProjectPhpVersion::from(['require' => ['php' => $constraint]]);
        self::assertSame($expected, $version->version);
        self::assertSame('composer.require.php', $version->origin);
    }

    public function test_explicit_version_has_precedence_and_platform_is_a_concrete_project_target(): void
    {
        $composer = ['require' => ['php' => '^8.2'], 'config' => ['platform' => ['php' => '8.3.5']]];
        self::assertSame('8.3.5', ProjectPhpVersion::from($composer)->version);
        $explicit = ProjectPhpVersion::from($composer, '8.4.0');
        self::assertSame('8.4.0', $explicit->version);
        self::assertSame('analysis.php_version', $explicit->origin);
    }

    public function test_explicit_version_cannot_hide_an_incompatible_composer_requirement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not satisfy');
        ProjectPhpVersion::from(['require' => ['php' => '^8.3']], '8.1');
    }

    public function test_absent_or_unbounded_requirement_needs_an_explicit_project_setting(): void
    {
        foreach ([[], ['require' => ['php' => '*']]] as $composer) {
            try {
                ProjectPhpVersion::from($composer);
                self::fail('Do not choose host PHP.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('analysis.php_version', $error->getMessage());
            }
            self::assertSame('8.3', ProjectPhpVersion::from($composer, '8.3')->version);
        }
    }

    public function test_mago_is_default_for_new_and_existing_settings_php_is_explicit(): void
    {
        $composer = ['require' => ['php' => '^8.3']];
        $default = EngineSettings::from(['enabled' => ['services']], $composer, 'shared');
        self::assertSame('mago', $default->engine);
        self::assertSame('default', $default->origin);
        $php = EngineSettings::from(['analysis' => ['engine' => 'php']], $composer, 'local-profile');
        self::assertSame('php', $php->engine);
        self::assertSame('local-profile', $php->origin);
        self::assertNotSame($default->fingerprint(), $php->fingerprint());
        self::assertSame('php', $php->session('/nonexistent/home')->engine->engine);
    }

    public function test_missing_default_mago_binary_does_not_trigger_php_fallback(): void
    {
        $settings = EngineSettings::from([], ['require' => ['php' => '^8.3']], 'shared');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('engine install');
        $settings->session(sys_get_temp_dir().'/kit-engine-missing-'.bin2hex(random_bytes(8)));
    }
}
