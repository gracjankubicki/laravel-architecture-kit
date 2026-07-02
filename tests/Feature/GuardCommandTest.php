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

    public function test_it_fails_when_laravel_ai_is_called_directly_from_controller(): void
    {
        (new Filesystem())->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'laravel/ai' => '^0.8',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::LaravelAi]);

        $this->writeFile('app/Http/Controllers/DocumentSummaryController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Agents\DocumentSummaryAgent;

final class DocumentSummaryController
{
    public function show(): array
    {
        return DocumentSummaryAgent::make()
            ->prompt('Summarize the document.')
            ->toArray();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"severity": "error"', $output);
        $this->assertStringContainsString('"rule": "laravel-ai"', $output);
        $this->assertStringContainsString('Controllers, FormRequests, API Resources, and Models must not call Laravel AI Agents directly', $output);
    }

    public function test_it_fails_on_generic_laravel_ai_gateway_and_anonymous_tool(): void
    {
        (new Filesystem())->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'laravel/ai' => '^0.8',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::LaravelAi]);

        $this->writeFile('app/Ai/Gateways/GenericAiGateway.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Ai\Gateways;

use Laravel\Ai\Contracts\Tool;

final class GenericAiGateway
{
    public function runAgent(string $agent, string $input): array
    {
        $tool = new class implements Tool {
        };

        return (new StructuredGatewayAgent($agent))
            ->prompt($input, provider: 'openrouter', model: 'openai/gpt-4.1')
            ->toArray();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--strict' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"severity": "error"', $output);
        $this->assertStringContainsString('"severity": "warn"', $output);
        $this->assertStringContainsString('"rule": "laravel-ai"', $output);
        $this->assertStringContainsString('Generic runAgent(string $agent, string $input): array gateways are diagnostic-only', $output);
        $this->assertStringContainsString('Production Laravel AI Tools must be dedicated classes', $output);
        $this->assertStringContainsString('StructuredGatewayAgent is diagnostic-only', $output);
        $this->assertStringContainsString('Avoid raw provider/model strings', $output);
    }

    public function test_it_warns_about_services_when_services_architecture_is_disabled(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $this->writeFile('app/Services/Documents/DocumentPseudonymizationService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

final readonly class DocumentPseudonymizationService
{
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"severity": "warn"', $output);
        $this->assertStringContainsString('"rule": "unenabled-pattern"', $output);
        $this->assertStringContainsString('Services are not enabled; prefer an enabled architecture boundary.', $output);
    }

    public function test_it_allows_valid_services_when_services_architecture_is_enabled(): void
    {
        $this->writeCurrentResources([Architecture::Services]);

        $this->writeFile('app/Services/Documents/DocumentPseudonymizationService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

final readonly class DocumentPseudonymizationService
{
    public function start(Document $document): DocumentPseudonymizationResult
    {
        return new DocumentPseudonymizationResult($document);
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_fails_on_service_folder_purity_suffix_http_and_static_violations(): void
    {
        $this->writeCurrentResources([Architecture::Services]);

        $this->writeFile('app/Services/Documents/DocumentPseudonymizationData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

final readonly class DocumentPseudonymizationData
{
}
PHP);

        $this->writeFile('app/Services/Documents/DocumentPseudonymizationService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DocumentPseudonymizationService
{
    public static function normalize(string $value): string
    {
        return trim($value);
    }

    public function approve(Request $request): JsonResponse
    {
        return new JsonResponse();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"severity": "error"', $output);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('"rule": "services"', $output);
        $this->assertStringContainsString('app/Services/** must contain Services only.', $output);
        $this->assertStringContainsString('Service classes under app/Services/** must use the Service suffix.', $output);
        $this->assertStringContainsString('Services must not depend on HTTP request or response classes.', $output);
        $this->assertStringContainsString('Services must not expose public static application behavior', $output);
    }

    public function test_it_warns_on_hidden_service_dependencies(): void
    {
        $this->writeCurrentResources([Architecture::Services]);

        $this->writeFile('app/Services/Documents/DocumentPseudonymizationService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

final class DocumentPseudonymizationService
{
    public function resolve(Document $document): DocumentPseudonymizationMap
    {
        return app(PseudonymizationMapResolver::class)->resolve($document);
    }

    private static function maps(): PseudonymizationMapResolver
    {
        return new PseudonymizationMapResolver();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"warnings": 2', $output);
        $this->assertStringContainsString('"rule": "service-locator"', $output);
        $this->assertStringContainsString('"rule": "testability"', $output);
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
