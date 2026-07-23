<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Upgrades;

use GracjanKubicki\ArchitectureKit\Upgrades\VersionLine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionLineTest extends TestCase
{
    #[DataProvider('versions')]
    public function test_it_maps_stable_versions_to_major_minor_lines(string $version, string $expected): void
    {
        $this->assertSame($expected, VersionLine::from($version)->value);
    }

    /** @return array<string, array{string, string}> */
    public static function versions(): array
    {
        return [
            'line' => ['0.10', '0.10'],
            'patch' => ['0.10.1', '0.10'],
            'composer normalized' => ['0.10.1.0', '0.10'],
            'leading v' => ['v12.4.2', '12.4'],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_it_rejects_constraints_and_unstable_versions(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        VersionLine::from($version);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedVersions(): array
    {
        return [
            'constraint' => ['^0.10'],
            'dev' => ['dev-main'],
            'prerelease' => ['0.10.0-beta.1'],
            'major only' => ['13'],
        ];
    }
}
