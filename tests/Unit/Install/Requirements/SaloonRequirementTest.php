<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Install\Requirements;

use GracjanKubicki\ArchitectureKit\Install\Requirements\SaloonRequirement;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class SaloonRequirementTest extends TestCase
{
    public function test_it_reports_missing_or_invalid_composer_json(): void
    {
        $this->assertSame(['composer.json is missing or invalid.'], SaloonRequirement::violations(new Filesystem, $this->tempPath));

        (new Filesystem)->put($this->tempPath.'/composer.json', '{');

        $this->assertSame(['composer.json is missing or invalid.'], SaloonRequirement::violations(new Filesystem, $this->tempPath));
    }

    public function test_it_reports_missing_required_packages(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'composer.json does not require saloonphp/saloon ^4.0.',
            'composer.json does not require saloonphp/laravel-plugin.',
            'composer.json does not require saloonphp/rate-limit-plugin.',
        ], SaloonRequirement::violations(new Filesystem, $this->tempPath));

        $this->assertSame([
            'saloonphp/saloon:^4.0',
            'saloonphp/laravel-plugin:^4.0',
            'saloonphp/rate-limit-plugin:^2.5',
        ], SaloonRequirement::missingInstallPackages(new Filesystem, $this->tempPath));
    }

    public function test_it_rejects_constraints_that_allow_saloon_three(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'saloonphp/saloon' => '^3.0 || ^4.0',
                'saloonphp/laravel-plugin' => '^4.0',
                'saloonphp/rate-limit-plugin' => '^2.5',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'saloonphp/saloon must require ^4.0 and must not allow Saloon 3; Saloon 4 fixes security issues in v3.',
        ], SaloonRequirement::violations(new Filesystem, $this->tempPath));
    }

    public function test_it_accepts_saloon_four_requirements_and_detects_project_requirement(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'saloonphp/saloon' => '^4.0',
                'saloonphp/laravel-plugin' => '^4.0',
                'saloonphp/rate-limit-plugin' => '^2.5',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([], SaloonRequirement::violations(new Filesystem, $this->tempPath));
        $this->assertTrue(SaloonRequirement::projectRequiresSaloon(new Filesystem, $this->tempPath));
        $this->assertSame([], SaloonRequirement::missingInstallPackages(new Filesystem, $this->tempPath));
    }
}
