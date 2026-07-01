<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Support\ApplicationAudit;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Tests\TestCase;

class AuditCommandTest extends TestCase
{
    public function test_it_reports_architecture_violations(): void
    {
        $this->writeConfig([
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::DataObjects,
            Architecture::Enums,
            Architecture::ApiResources,
            Architecture::ModernPhp85,
        ]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentController
{
    public function update($request, $document)
    {
        $document->update($request->validated());
    }
}
PHP);

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

        $this->writeFile('app/Actions/Documents/DownloadOriginalDocumentResult.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final readonly class DownloadOriginalDocumentResult
{
}
PHP);

        $this->writeFile('app/Http/Responses/DocumentActionResponse.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Responses;

final class DocumentActionResponse
{
}
PHP);

        $result = (new ApplicationAudit(new Filesystem(), $this->tempPath))->run([
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::DataObjects,
            Architecture::Enums,
            Architecture::ApiResources,
            Architecture::ModernPhp85,
        ], changedOnly: false);

        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'folder-purity'
                && $finding->path === 'app/Actions/Documents/DownloadOriginalDocumentResult.php'
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'thin-controller'
                && str_contains($finding->message, 'mutates a model directly')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'form-request'
                && str_contains($finding->message, 'toData')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'enums'
                && str_contains($finding->message, 'Rule::enum')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'unenabled-pattern'
                && str_contains($finding->message, 'Http Responses')
        ));

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('error folder-purity')
            ->expectsOutputToContain('error thin-controller')
            ->expectsOutputToContain('error form-request')
            ->expectsOutputToContain('warn  enums')
            ->expectsOutputToContain('warn  unenabled-pattern')
            ->expectsOutputToContain('Summary:')
            ->assertExitCode(1);
    }

    public function test_it_passes_for_a_minimal_compliant_slice(): void
    {
        $this->writeConfig([
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::DataObjects,
        ]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Documents\UpdateDocument;
use App\Http\Requests\Documents\UpdateDocumentRequest;

final class DocumentController
{
    public function update(UpdateDocumentRequest $request, UpdateDocument $update)
    {
        return $update->handle($request->toData());
    }
}
PHP);

        $this->writeFile('app/Http/Requests/Documents/UpdateDocumentRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Data\Documents\UpdateDocumentData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string'],
        ];
    }

    public function toData(): UpdateDocumentData
    {
        return new UpdateDocumentData((string) $this->validated('document_type'));
    }
}
PHP);

        $this->writeFile('app/Actions/Documents/UpdateDocument.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Data\Documents\UpdateDocumentData;

final class UpdateDocument
{
    public function handle(UpdateDocumentData $data): void
    {
    }
}
PHP);

        $this->writeFile('app/Data/Documents/UpdateDocumentData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Data\Documents;

final readonly class UpdateDocumentData
{
    public function __construct(public string $documentType)
    {
    }
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('No architecture violations found.')
            ->assertExitCode(0);
    }

    public function test_strict_mode_fails_on_warnings(): void
    {
        $this->writeConfig([
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

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('warn  enums')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:audit --strict')
            ->expectsOutputToContain('warn  enums')
            ->assertExitCode(1);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    private function writeConfig(array $enabled): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($enabled);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem();
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
