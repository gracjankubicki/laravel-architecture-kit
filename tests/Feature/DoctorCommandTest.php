<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class DoctorCommandTest extends TestCase
{
    public function test_doctor_agent_output_reports_compact_checks_and_issues(): void
    {
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php');
        $config->write([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:doctor', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('doctor', $payload['cmd']);
        $this->assertSame(['actions'], $payload['enabled']);
        $this->assertSame('fail', $payload['checks']['generated']);
        $this->assertSame('generated', $payload['issues'][0]['a']);
        $this->assertSame('err', $payload['issues'][0]['s']);
        $this->assertArrayNotHasKey('msg', $payload['issues'][0]);
    }

    public function test_doctor_agent_full_output_includes_issue_messages(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit');
        $files->put($this->tempPath.'/.architecture-kit/baseline.json', '{broken');

        $exitCode = Artisan::call('architecture-kit:doctor', ['--agent' => true, '--full' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $payload['checks']['baseline']);
        $this->assertSame('E_BASELINE_INVALID', $payload['issues'][0]['m']);
        $this->assertSame('.architecture-kit/baseline.json is invalid or uses an unsupported version.', $payload['issues'][0]['msg']);
    }

    public function test_it_passes_when_generated_resources_are_current(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('current  config/architectures.php')
            ->expectsOutputToContain('current  .ai/guidelines/architecture-kit.md')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_generated_guideline_is_missing(): void
    {
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php');
        $config->write([Architecture::Actions]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('missing  .ai/guidelines/architecture-kit.md')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_generated_guideline_uses_old_full_format(): void
    {
        $files = new Filesystem;
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);
        $enabled = [Architecture::Actions];

        $config->write($enabled);
        $guideline = $resources->guideline($enabled);
        $files->ensureDirectoryExists(dirname($guideline->path));
        $files->put($guideline->path, $resources->fullGuideline($enabled));

        foreach ($resources->skills($enabled) as $skill) {
            $files->ensureDirectoryExists(dirname($skill->path));
            $files->put($skill->path, $skill->contents);
        }

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('outdated .ai/guidelines/architecture-kit.md')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_generated_skill_is_missing(): void
    {
        $files = new Filesystem;
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);

        $config->write([Architecture::Actions]);

        $guideline = $resources->guideline([Architecture::Actions]);
        $files->ensureDirectoryExists(dirname($guideline->path));
        $files->put($guideline->path, $guideline->contents);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('missing  .ai/skills/architecture-kit-actions/SKILL.md')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_generated_skill_is_outdated(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        (new Filesystem)->append($this->tempPath.'/.ai/skills/architecture-kit-actions/SKILL.md', "\nmanual edit\n");

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('outdated .ai/skills/architecture-kit-actions/SKILL.md')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_generated_skill_is_stale(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.ai/skills/architecture-kit-value-objects');
        $files->put(
            $this->tempPath.'/.ai/skills/architecture-kit-value-objects/SKILL.md',
            "---\nname: architecture-kit-value-objects\n---\n\n<!-- Generated by Laravel Architecture Kit. Do not edit manually. hash=sha256:old -->\n",
        );

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('stale    .ai/skills/architecture-kit-value-objects')
            ->assertExitCode(1);
    }

    public function test_it_accepts_builtin_architecture_raw_strings(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/config');
        $files->put($this->tempPath.'/config/architectures.php', "<?php\n\nreturn ['enabled' => ['actions']];\n");

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('current  config/architectures.php')
            ->expectsOutputToContain('enabled  actions')
            ->expectsOutputToContain('missing  .ai/guidelines/architecture-kit.md')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_modern_php_85_is_enabled_without_php_85_requirement(): void
    {
        $this->writeCurrentResources([Architecture::ModernPhp85]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('blocked  composer.json')
            ->expectsOutputToContain('Modern PHP 8.5 is enabled but composer.json does not require PHP 8.5 or newer.')
            ->assertExitCode(1);
    }

    public function test_it_passes_for_modern_php_85_when_project_requires_php_85(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'php' => '^8.5',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::ModernPhp85]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('current  config/architectures.php')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_laravel_ai_is_enabled_without_laravel_ai_requirement(): void
    {
        $this->writeCurrentResources([Architecture::LaravelAi]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('blocked  composer.json')
            ->expectsOutputToContain('Laravel AI is enabled but composer.json does not require laravel/ai.')
            ->assertExitCode(1);
    }

    public function test_it_warns_when_laravel_ai_is_installed_but_architecture_is_disabled(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'laravel/ai' => '^0.8',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::Actions]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('warning  composer.json')
            ->expectsOutputToContain('laravel/ai is installed but Architecture::LaravelAi is not enabled.')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_saloon_is_enabled_without_required_packages(): void
    {
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('blocked  composer.json')
            ->expectsOutputToContain('composer.json is missing or invalid.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_saloon_v3_is_allowed(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'saloonphp/saloon' => '^3.0',
                'saloonphp/laravel-plugin' => '^4.0',
                'saloonphp/rate-limit-plugin' => '^2.5',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::Saloon]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('blocked  composer.json')
            ->expectsOutputToContain('saloonphp/saloon must require ^4.0 and must not allow Saloon 3')
            ->assertExitCode(1);
    }

    public function test_it_passes_for_saloon_when_required_packages_are_installed(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'saloonphp/saloon' => '^4.0',
                'saloonphp/laravel-plugin' => '^4.0',
                'saloonphp/rate-limit-plugin' => '^2.5',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::Saloon]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('current  config/architectures.php')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_services_exist_but_services_architecture_is_disabled(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Services/Documents');
        $files->put($this->tempPath.'/app/Services/Documents/DocumentPseudonymizationService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

final readonly class DocumentPseudonymizationService
{
}
PHP);

        $this->writeCurrentResources([Architecture::Actions]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('warning  app/Services')
            ->expectsOutputToContain('app/Services exists but Architecture::Services is not enabled.')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_ports_and_adapters_is_enabled_without_data_objects(): void
    {
        $this->writeCurrentResources([Architecture::PortsAndAdapters]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('warning  config/architectures.php')
            ->expectsOutputToContain('Ports And Adapters works best with Data Objects enabled')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_generic_laravel_best_practices_skill_is_present(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.ai/skills/laravel-best-practices');
        $files->put($this->tempPath.'/.ai/skills/laravel-best-practices/SKILL.md', "---\nname: laravel-best-practices\n---\n");

        $this->writeCurrentResources([Architecture::LaravelBestPractices]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('Laravel Boost:')
            ->expectsOutputToContain('warning  .ai/skills/laravel-best-practices')
            ->expectsOutputToContain('Generic Laravel Boost laravel-best-practices skill is present.')
            ->assertExitCode(0);
    }

    public function test_it_does_not_warn_about_generic_laravel_best_practices_skill_when_architecture_is_disabled(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.ai/skills/laravel-best-practices');
        $files->put($this->tempPath.'/.ai/skills/laravel-best-practices/SKILL.md', "---\nname: laravel-best-practices\n---\n");

        $this->writeCurrentResources([Architecture::Actions]);

        $this->artisan('architecture-kit:doctor')
            ->doesntExpectOutputToContain('Generic Laravel Boost laravel-best-practices skill is present.')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_docker_runtime_has_no_compose_file(): void
    {
        $this->writeCurrentResources([Architecture::Actions], [
            'driver' => 'docker',
            'service' => 'app',
            'php' => 'php',
            'command' => null,
        ]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('Runtime:')
            ->expectsOutputToContain('warning  compose.yaml')
            ->expectsOutputToContain('Runtime driver is docker but no compose.yaml')
            ->assertExitCode(0);
    }

    public function test_it_warns_when_sail_runtime_has_no_sail_binary(): void
    {
        $this->writeCurrentResources([Architecture::Actions], [
            'driver' => 'sail',
            'service' => 'laravel.test',
            'php' => 'php',
            'command' => null,
        ]);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('Runtime:')
            ->expectsOutputToContain('warning  vendor/bin/sail')
            ->expectsOutputToContain('Runtime driver is sail but vendor/bin/sail was not found.')
            ->assertExitCode(0);
    }

    public function test_it_reports_orphaned_baseline_entries_and_update_baseline_removes_them(): void
    {
        $this->writeCurrentResources([
            Architecture::ThinControllers,
            Architecture::Actions,
        ]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentController
{
    public function update($document): void
    {
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $this->artisan('architecture-kit:audit --update-baseline')
            ->assertExitCode(0);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentController
{
    public function update(): void
    {
    }
}
PHP);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('Baseline:')
            ->expectsOutputToContain('warning  .architecture-kit/baseline.json')
            ->expectsOutputToContain('1 baseline entry is orphaned')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:audit --update-baseline')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('current  .architecture-kit/baseline.json')
            ->assertExitCode(0);
    }

    public function test_it_blocks_when_baseline_json_is_invalid(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit');
        $files->put($this->tempPath.'/.architecture-kit/baseline.json', '{broken');

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('Baseline:')
            ->expectsOutputToContain('blocked  .architecture-kit/baseline.json')
            ->expectsOutputToContain('.architecture-kit/baseline.json is invalid or uses an unsupported version.')
            ->assertExitCode(1);
    }

    public function test_it_blocks_custom_project_architecture_without_guideline_source(): void
    {
        $this->writeFile('config/architectures.php', <<<'PHP'
<?php

return [
    'enabled' => [
        'billing-workflows',
    ],
];
PHP);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('blocked  config/architectures.php')
            ->expectsOutputToContain('Missing Architecture Kit source resource: '.$this->tempPath.'/.architecture-kit/architectures/billing-workflows/guideline.md')
            ->assertExitCode(1);
    }

    public function test_every_architecture_has_source_resources(): void
    {
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath);

        foreach (Architecture::cases() as $architecture) {
            $this->assertFileExists($resources->guidelineSource($architecture));
            $this->assertFileExists($resources->skillSource($architecture));
            $this->assertTrue($resources->summarySource($architecture) !== '');
            $this->assertTrue(is_file($resources->summarySource($architecture)) || is_dir($resources->summarySource($architecture)));
        }
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    private function writeCurrentResources(array $enabled, ?array $runtime = null): void
    {
        $files = new Filesystem;
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);

        $config->write($enabled, $runtime);

        $generated = array_merge(
            [$resources->guideline($enabled)],
            array_values($resources->skills($enabled)),
        );

        foreach ($generated as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
