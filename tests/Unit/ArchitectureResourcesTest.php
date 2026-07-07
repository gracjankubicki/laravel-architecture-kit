<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ArchitectureResourcesTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-resources-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_compact_guideline_stays_within_token_budget(): void
    {
        $resources = $this->resources();

        $default = $resources->guideline(Architecture::defaultSelection())->contents;
        $all = $resources->guideline(Architecture::cases())->contents;

        $this->assertLessThanOrEqual(60, $this->lineCount($default));
        $this->assertLessThanOrEqual(6000, strlen($default));
        $this->assertLessThanOrEqual(90, $this->lineCount($all));
        $this->assertStringContainsString('## Architecture Kit', $default);
        $this->assertStringContainsString('## Enabled Architecture Index', $default);
        $this->assertStringContainsString('## Before Finishing', $default);
        $this->assertStringNotContainsString('Good example:', $default);
        $this->assertStringNotContainsString('```php', $default);
    }

    public function test_full_and_single_architecture_guidelines_keep_expanded_content(): void
    {
        $resources = $this->resources();
        $enabled = [Architecture::Actions];

        $full = $resources->fullGuideline($enabled);
        $single = $resources->architectureGuideline('actions', $enabled);

        $this->assertStringContainsString('## Package-First Architecture Rule', $full);
        $this->assertStringContainsString('### Actions', $full);
        $this->assertStringContainsString('Good example:', $full);
        $this->assertStringContainsString('## Actions', $single);
        $this->assertStringContainsString('Status: enabled globally.', $single);
        $this->assertStringContainsString('Expose one public entry method: `handle(...)`.', $single);
    }

    public function test_summary_fragments_and_custom_fallbacks_are_resolved(): void
    {
        $resources = $this->resources();

        $withoutData = $resources->summaryFor(Architecture::FormRequests, [Architecture::FormRequests]);
        $withData = $resources->summaryFor(Architecture::FormRequests, [Architecture::FormRequests, Architecture::DataObjects]);

        $this->assertStringNotContainsString('toData()', $withoutData);
        $this->assertStringContainsString('toData()', $withData);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/billing-workflows');
        $files->put($this->tempPath.'/.architecture-kit/architectures/billing-workflows/guideline.md', "\nCustom billing workflow rules.\nSecond line.");

        $this->assertSame(
            'Custom billing workflow rules.',
            $resources->summaryFor('billing-workflows', ['billing-workflows']),
        );
    }

    public function test_custom_architecture_renders_without_default_placement(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/billing-workflows');
        $files->put($this->tempPath.'/.architecture-kit/architectures/billing-workflows/guideline.md', 'Custom billing workflow rules.');

        $guideline = $this->resources()->guideline(['billing-workflows'])->contents;

        $this->assertStringContainsString('| Billing Workflows | — | Custom billing workflow rules. |', $guideline);
    }

    public function test_builtin_architectures_require_summary_resources(): void
    {
        $files = new Filesystem;
        $packagePath = $this->tempPath.'/package';
        $files->ensureDirectoryExists($packagePath.'/resources/architectures/actions');
        $files->put($packagePath.'/resources/architectures/actions/guideline.md', 'Rules');
        $files->put($packagePath.'/resources/architectures/actions/SKILL.md', "---\nname: architecture-kit-actions\n---\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing Architecture Kit source resource');

        (new ArchitectureResources($packagePath, $this->tempPath, $files))->assertSourcesExist([Architecture::Actions]);
    }

    private function resources(): ArchitectureResources
    {
        return new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, new Filesystem);
    }

    private function lineCount(string $contents): int
    {
        return substr_count(trim($contents), "\n") + 1;
    }
}
