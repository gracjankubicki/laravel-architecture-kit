<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureResources;
use Taqie\ArchitectureKit\Tests\TestCase;

class GuardCommandTest extends TestCase
{
    public function test_it_passes_with_current_resources_and_no_audit_findings(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ok": true', $output);
        $this->assertStringContainsString('"doctor"', $output);
        $this->assertStringContainsString('"audit"', $output);
    }

    public function test_it_fails_when_doctor_state_is_not_current(): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"ok": false', $output);
        $this->assertStringContainsString('"status": "missing"', $output);
    }

    public function test_it_fails_on_audit_errors(): void
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

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('error thin-controller')
            ->assertExitCode(1);
    }

    public function test_strict_guard_fails_on_audit_warnings(): void
    {
        $this->writeCurrentResources([
            Architecture::FormRequests,
            Architecture::Enums,
        ]);

        $this->writeFile('app/Http/Requests/Documents/StoreDocumentRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(Document::TYPES)],
        ];
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Findings: 0 error(s), 1 warning(s)')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:guard --strict')
            ->expectsOutputToContain('warn  enums')
            ->assertExitCode(1);
    }

    public function test_changed_guard_with_base_ref_includes_committed_diff(): void
    {
        $this->writeCurrentResources([
            Architecture::ThinControllers,
            Architecture::Actions,
        ]);

        $this->git('init -b main');
        $this->git('config user.email architecture-kit@example.test');
        $this->git('config user.name "Architecture Kit"');
        $this->git('add config/architectures.php .ai');
        $this->git('commit -m base');
        $this->git('checkout -b feature');

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

        $this->git('add app/Http/Controllers/DocumentController.php');
        $this->git('commit -m feature');

        $this->artisan('architecture-kit:guard --changed --base=main')
            ->expectsOutputToContain('Audit: changed application files since main')
            ->expectsOutputToContain('error thin-controller')
            ->assertExitCode(1);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    private function writeCurrentResources(array $enabled): void
    {
        $files = new Filesystem();
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);

        $config->write($enabled);

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
        $files = new Filesystem();
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }

    private function git(string $arguments): void
    {
        $output = [];
        $exitCode = 0;

        exec('git -C '.escapeshellarg($this->tempPath).' '.$arguments.' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
