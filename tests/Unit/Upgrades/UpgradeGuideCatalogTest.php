<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Upgrades;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Upgrades\UpgradeGuideCatalog;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class UpgradeGuideCatalogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-guide-catalog-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_it_returns_typed_guides_and_filters_by_enabled_architecture(): void
    {
        $this->writeGuide('laravel-ai', 'laravel/ai', 'laravel-ai', '0.8', '0.9');
        $this->writeGuide('vendor-package', 'vendor/package', 'actions', '1.0', '2.0');

        $catalog = new UpgradeGuideCatalog($this->path, new Filesystem);
        $all = $catalog->all();
        $enabled = $catalog->forArchitectures([Architecture::LaravelAi]);

        $this->assertCount(2, $all);
        $this->assertCount(1, $enabled);
        $this->assertSame('laravel/ai', $enabled[0]->package);
        $this->assertSame('0.8', $enabled[0]->from->value);
        $this->assertSame('0.9', $enabled[0]->to->value);
        $this->assertSame('upgrade:laravel-ai:0.8-to-0.9', $enabled[0]->key());
    }

    public function test_distributed_laravel_ai_guides_form_the_canonical_route_to_011(): void
    {
        $guides = (new UpgradeGuideCatalog(dirname(__DIR__, 3), new Filesystem))
            ->forArchitectures([Architecture::LaravelAi]);

        $this->assertSame(
            ['0.10->0.11', '0.8->0.9', '0.9->0.10'],
            array_map(fn ($guide): string => $guide->from->value.'->'.$guide->to->value, $guides),
        );

        $guide = collect($guides)->first(fn ($guide): bool => $guide->from->value === '0.10');
        $this->assertNotNull($guide);
        $skill = $guide->contents;
        $this->assertStringContainsString('ProviderConnectionException', $skill);
        $this->assertStringContainsString('StreamErrorException', $skill);
        $this->assertStringContainsString('StreamEnd', $skill);
        $this->assertStringContainsString('FakePendingDispatch', $skill);
        $this->assertStringContainsString('gemini-3.7-flash', $skill);
        $this->assertStringContainsString('AgentFailedOver', $skill);
        $this->assertStringContainsString('ToolInvoked', $skill);
        $this->assertStringContainsString('toolInvocationId', $skill);
        $this->assertStringContainsString('illuminate/json-schema', $skill);
        $this->assertStringContainsString('## Requirement-evidence handoff', $skill);
    }

    private function writeGuide(string $directory, string $package, string $architecture, string $from, string $to): void
    {
        $name = 'architecture-kit-upgrade-'.$directory.'-'.str_replace('.', '-', $from).'-to-'.str_replace('.', '-', $to);
        $path = $this->path."/resources/upgrades/{$directory}/{$from}-to-{$to}/SKILL.md";
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<MARKDOWN
---
name: {$name}
description: Upgrade fixture.
metadata:
  architecture: {$architecture}
  package: {$package}
  from: "{$from}"
  to: "{$to}"
---

# Upgrade
MARKDOWN);
    }
}
