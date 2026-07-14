<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\ArchitectureKit;
use PHPUnit\Framework\TestCase;

final class ReleaseMetadataTest extends TestCase
{
    public function test_release_metadata_uses_composer_or_the_explicit_dev_main_fallback(): void
    {
        $version = ArchitectureKit::version();

        $this->assertNotSame('', $version);

        if (str_starts_with($version, 'dev-')) {
            $this->assertMatchesRegularExpression('/^dev-[a-zA-Z0-9._\/-]+$/', $version);

            return;
        }

        $this->assertMatchesRegularExpression('/^v?\d+\.\d+\.\d+/', $version);
    }

    public function test_current_release_tag_matches_the_latest_released_changelog_entry(): void
    {
        $changelog = file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

        $this->assertIsString($changelog);
        $this->assertMatchesRegularExpression('/^## v(\d+\.\d+\.\d+) - (?!Unreleased$).+$/m', $changelog);
        preg_match('/^## v(\d+\.\d+\.\d+) - (?!Unreleased$).+$/m', $changelog, $matches);

        exec('git -C '.escapeshellarg(dirname(__DIR__, 2)).' tag --points-at HEAD', $tags, $exitCode);

        $this->assertSame(0, $exitCode);

        if ($tags === []) {
            $this->markTestSkipped('Tag/changelog drift is checked only for a tagged release commit.');
        }

        $this->assertContains('v'.$matches[1], $tags);
    }
}
