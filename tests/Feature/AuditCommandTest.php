<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\ApplicationAudit;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Config\ArchitectureConfig;
use Taqie\ArchitectureKit\Tests\TestCase;

class AuditCommandTest extends TestCase
{
    public function test_agent_output_is_minified_and_uses_finding_codes_by_default(): void
    {
        $this->writeConfig([
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

        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $output = trim(Artisan::output());
        $payload = json_decode($output, true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertStringNotContainsString("\n", $output);
        $this->assertSame(1, $payload['v']);
        $this->assertFalse($payload['ok']);
        $this->assertSame('audit', $payload['cmd']);
        $this->assertSame(1, $payload['err']);
        $this->assertSame('thin-controller', $payload['find'][0]['r']);
        $this->assertSame('err', $payload['find'][0]['s']);
        $this->assertSame('E_THIN_CONTROLLER_MODEL_WRITE', $payload['find'][0]['m']);
        $this->assertArrayNotHasKey('msg', $payload['find'][0]);
        $this->assertStringNotContainsString('Controller mutates a model directly', $output);
    }

    public function test_agent_full_output_includes_full_messages(): void
    {
        $this->writeConfig([
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

        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true, '--full' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('Controller mutates a model directly; move the write use case to an Action.', $payload['find'][0]['msg']);
    }

    public function test_agent_output_can_hide_findings_with_zero_limit(): void
    {
        $this->writeConfig([
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

        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true, '--limit' => 0]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($payload['trunc']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame(0, $payload['shown']);
        $this->assertArrayNotHasKey('find', $payload);
    }

    public function test_agent_output_can_limit_findings_and_report_truncation(): void
    {
        $this->writeConfig([
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
        $document->delete();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true, '--limit' => 1]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($payload['trunc']);
        $this->assertSame(2, $payload['total']);
        $this->assertSame(1, $payload['shown']);
        $this->assertCount(1, $payload['find']);
    }

    public function test_agent_output_reports_command_errors_as_json(): void
    {
        $this->writeRawConfig(<<<'PHP'
<?php

use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Tests\Feature\MissingFixtureAuditRule;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        MissingFixtureAuditRule::class,
    ],
];
PHP);

        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('audit', $payload['cmd']);
        $this->assertSame('E_COMMAND_FAILED', $payload['m']);
    }

    public function test_agent_schema_option_outputs_json_schema(): void
    {
        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true, '--schema' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $payload['$schema']);
        $this->assertSame('Architecture Kit audit agent output', $payload['title']);
        $this->assertSame('audit', $payload['properties']['cmd']['const']);
    }

    public function test_agent_output_is_shorter_than_human_text_output_for_same_fixture(): void
    {
        $this->writeConfig([
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
        $this->validate([], []);
        $document->update(['name' => 'changed']);
        $document->delete();
        DB::transaction(fn () => null);
        Document::create(['name' => 'new']);
        ProcessDocument::dispatch($document);
    }
}
PHP);

        Artisan::call('architecture-kit:audit');
        $textOutput = Artisan::output();

        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $agentOutput = Artisan::output();

        $this->assertLessThan(strlen($textOutput), strlen($agentOutput));
    }

    public function test_it_reports_architecture_violations(): void
    {
        $this->writeConfig([
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::QueryObjects,
            Architecture::DataObjects,
            Architecture::Enums,
            Architecture::ApiResources,
            Architecture::ModernPhp85,
        ]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DocumentIntake\DocumentIntakeService;

final class DocumentController
{
    public function __construct(private DocumentIntakeService $intake)
    {
    }

    public function update($request, $document)
    {
        $document->update($request->validated());
        app(ResolveDocumentPseudonymizationMap::class);
    }

    private function pseudonymizationMap($document)
    {
        return PseudonymizationMap::query()
            ->where('document_id', $document->id)
            ->whereIn('placeholder', ['[PERSON_1]'])
            ->get();
    }
}
PHP);

        $this->writeFile('app/Models/Document.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Document extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const TYPES = [
        'opponent_letter',
    ];

    protected $fillable = [
        'document_type',
        'status',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'string',
        ];
    }
}
PHP);

        $this->writeFile('app/Enums/Documents/DocumentStatus.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Enums\Documents;

enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
}
PHP);

        $this->writeFile('app/Enums/Documents/DocumentType.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Enums\Documents;

enum DocumentType: string
{
    case OpponentLetter = 'opponent_letter';
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

        $this->writeFile('app/Actions/Documents/ReceiveChunkedDocumentUpload.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use Illuminate\Http\Request;

final class ReceiveChunkedDocumentUpload
{
    public function handle(Request $request): void
    {
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

        $this->writeFile('app/Queries/Documents/SearchDocuments.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Queries\Documents;

use App\Models\Document;
use Illuminate\Http\Request;

final class SearchDocuments
{
    public function handle(Request $request): void
    {
        Document::query()->update(['status' => 'archived']);
    }
}
PHP);

        $this->writeFile('app/Http/Requests/ArchitectureFormRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Auth\EmailVerificationRequest;

abstract class ArchitectureFormRequest extends EmailVerificationRequest
{
}
PHP);

        $this->writeFile('app/Http/Resources/DocumentResource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentResource extends JsonResource
{
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'document_type' => $this->document_type,
        ];
    }
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

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::QueryObjects,
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
            fn ($finding): bool => $finding->rule === 'thin-controller'
                && str_contains($finding->message, 'App\\Services')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'actions'
                && str_contains($finding->message, 'HTTP request or response')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'query-objects'
                && str_contains($finding->message, 'HTTP request or response')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'query-objects'
                && str_contains($finding->message, 'private read/query logic')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'form-request'
                && str_contains($finding->message, 'toData')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'form-request'
                && str_contains($finding->message, 'EmailVerificationRequest')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'enums'
                && str_contains($finding->message, 'Rule::enum')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'enums'
                && str_contains($finding->message, 'value + label')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'enums'
                && str_contains($finding->message, "Model attribute 'status'")
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'service-locator'
                && str_contains($finding->message, 'app(...)')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'unenabled-pattern'
                && str_contains($finding->message, 'Http Responses')
        ));

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('error folder-purity')
            ->expectsOutputToContain('error thin-controller')
            ->expectsOutputToContain('error actions')
            ->expectsOutputToContain('error query-objects')
            ->expectsOutputToContain('error form-request')
            ->expectsOutputToContain('warn  enums')
            ->expectsOutputToContain('warn  service-locator')
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

    public function test_ports_and_adapters_reports_speculative_interfaces_and_boundary_leaks(): void
    {
        $this->writeConfig([
            Architecture::PortsAndAdapters,
            Architecture::DataObjects,
        ]);

        $this->writeFile('app/Contracts/CreateInvoiceInterface.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Contracts;

interface CreateInvoiceInterface
{
    public function handle(array $payload): array;
}
PHP);

        $this->writeFile('app/Actions/CreateInvoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class CreateInvoice
{
}
PHP);

        $this->writeFile('app/Contracts/DocumentApiGateway.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Contracts;

use Saloon\Http\Response;

/**
 * Port boundary for document API access.
 *
 * EN: Exists to keep document workflows independent from the external API provider.
 *
 * PL: Istnieje po to, żeby workflow dokumentów był niezależny od zewnętrznego API.
 */
interface DocumentApiGateway
{
    public function send(Response $response): DocumentApiResultData;
}
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([
            Architecture::PortsAndAdapters,
            Architecture::DataObjects,
        ], changedOnly: false);

        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'ports-and-adapters'
                && str_contains($finding->message, 'bilingual EN/PL PHPDoc')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'ports-and-adapters'
                && str_contains($finding->message, 'mirror a single local implementation')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'ports-and-adapters'
                && str_contains($finding->message, 'raw arrays')
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'ports-and-adapters'
                && str_contains($finding->message, 'vendor response types')
        ));

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('warn  ports-and-adapters')
            ->expectsOutputToContain('error ports-and-adapters')
            ->assertExitCode(1);
    }

    public function test_ports_and_adapters_accepts_documented_provider_boundary(): void
    {
        $this->writeConfig([
            Architecture::PortsAndAdapters,
            Architecture::DataObjects,
        ]);

        $this->writeFile('app/Documents/Ports/DocumentTypeDetector.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Documents\Ports;

/**
 * Port boundary for document type detection.
 *
 * EN: Exists to keep document workflows independent from the AI/OCR provider
 * and to allow tests to replace provider calls with a fake detector.
 *
 * PL: Istnieje po to, żeby workflow dokumentów był niezależny od providera AI/OCR
 * i żeby testy mogły zastąpić wywołania providera fake detektorem.
 */
interface DocumentTypeDetector
{
    public function detect(OcrResultData $ocrResult): DetectedDocumentTypeData;
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('No architecture violations found.')
            ->assertExitCode(0);
    }

    public function test_ports_and_adapters_ignores_framework_style_interfaces(): void
    {
        $this->writeConfig([
            Architecture::PortsAndAdapters,
        ]);

        $this->writeFile('app/Support/Arrayable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Support;

interface Arrayable
{
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('No architecture violations found.')
            ->assertExitCode(0);
    }

    public function test_ast_backed_audit_rules_report_findings_for_every_ast_architecture(): void
    {
        $enabled = [
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::Services,
            Architecture::QueryObjects,
            Architecture::DataObjects,
            Architecture::ValueObjects,
            Architecture::Enums,
            Architecture::ApiResources,
            Architecture::CustomEloquentBuilders,
            Architecture::PortsAndAdapters,
            Architecture::LaravelAi,
            Architecture::ModernPhp85,
        ];

        $this->writeConfig($enabled);

        $this->writeFile('app/Http/Controllers/AstController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Laravel\Ai\Facades\Ai;

final class AstController
{
    public function update($document): void
    {
        $document->update(['status' => 'ready']);
        app(AstPortInterface::class);
        Ai::prompt('Summarize document');
    }
}
PHP);

        $this->writeFile('app/Actions/AstAction.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Http\Request;

final class AstAction
{
    public function handle(Request $request): void
    {
    }
}
PHP);

        $this->writeFile('app/Actions/AstResult.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class AstResult
{
}
PHP);

        $this->writeFile('app/Actions/AstFactoryAction.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class AstFactoryAction
{
    public function handle(): void
    {
        self::collaborator()->handle();
    }

    private static function collaborator(): AstCollaborator
    {
        return new AstCollaborator();
    }
}
PHP);

        $this->writeFile('app/Services/AstWorkflow.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

final class AstWorkflow
{
}
PHP);

        $this->writeFile('app/Queries/AstQuery.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Http\Request;

final class AstQuery
{
    public function handle(Request $request): void
    {
    }
}
PHP);

        $this->writeFile('app/Data/AstPayloadData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Data;

final readonly class AstPayloadData
{
    public function setName(string $name): void
    {
    }
}
PHP);

        $this->writeFile('app/ValueObjects/AstMoneyValue.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class AstMoneyValue
{
}
PHP);

        $this->writeFile('app/Models/AstDocument.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

final class AstDocument
{
    public const STATUS_READY = 'ready';
}
PHP);

        $this->writeFile('app/Http/Requests/AstRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AstRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    public function data(): array
    {
        return $this->validated();
    }
}
PHP);

        $this->writeFile('app/Http/Resources/AstResource.php', <<<'PHP'
<?php

namespace App\Http\Resources;

use App\Models\AstDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AstResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'documents' => AstDocument::query()->where('status', 'ready')->get(),
            'status' => $this->status,
        ];
    }
}
PHP);

        $this->writeFile('app/Models/Builders/AstScope.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models\Builders;

final class AstScope
{
}
PHP);

        $this->writeFile('app/Contracts/AstPortInterface.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Contracts;

use Saloon\Http\Response;

interface AstPortInterface
{
    public function handle(array $payload): Response;
}
PHP);

        $this->writeFile('app/Contracts/AstPort.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Contracts;

final class AstPort
{
}
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run($enabled, changedOnly: false);

        $expectedRules = [
            'actions',
            'api-resource',
            'custom-eloquent-builders',
            'data-objects',
            'enums',
            'folder-purity',
            'form-request',
            'laravel-ai',
            'modern-php-85',
            'ports-and-adapters',
            'query-objects',
            'service-locator',
            'services',
            'testability',
            'thin-controller',
            'value-objects',
        ];

        $actualRules = collect($result->findings)
            ->pluck('rule')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $missingRules = array_values(array_diff($expectedRules, $actualRules));

        $this->assertSame([], $missingRules, 'Missing AST-backed findings: '.implode(', ', $missingRules));
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

    public function test_it_warns_for_hidden_private_static_dependency_factories(): void
    {
        $this->writeConfig([
            Architecture::Actions,
        ]);

        $this->writeFile('app/Actions/Documents/StartDocumentPseudonymization.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final class StartDocumentPseudonymization
{
    public function handle(): void
    {
        self::documentTemplate()->handle();
    }

    private static function documentTemplate(): ResolveWorkingCaseDocumentTemplate
    {
        return new ResolveWorkingCaseDocumentTemplate();
    }
}
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::Actions], changedOnly: false);

        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => $finding->rule === 'testability'
                && str_contains($finding->message, 'private static factory')
        ));

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('warn  testability')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:audit --strict')
            ->expectsOutputToContain('warn  testability')
            ->assertExitCode(1);
    }

    public function test_modern_php_does_not_require_override_on_form_request_convention_methods(): void
    {
        $this->writeConfig([
            Architecture::FormRequests,
            Architecture::ModernPhp85,
        ]);

        $this->writeFile('app/Http/Requests/Documents/StoreDocumentRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentRequest extends FormRequest
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
}
PHP);

        $this->artisan('architecture-kit:audit --strict')
            ->expectsOutputToContain('No architecture violations found.')
            ->assertExitCode(0);
    }

    public function test_changed_audit_with_base_ref_includes_committed_diff(): void
    {
        $this->writeConfig([
            Architecture::ThinControllers,
            Architecture::Actions,
        ]);

        $this->git('init -b main');
        $this->git('config user.email architecture-kit@example.test');
        $this->git('config user.name "Architecture Kit"');
        $this->git('add config/architectures.php');
        $this->git('commit -m base');
        $this->git('checkout -b feature');

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentController
{
    public function update($document)
    {
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $this->git('add app/Http/Controllers/DocumentController.php');
        $this->git('commit -m feature');

        $this->artisan('architecture-kit:audit --changed --base=main')
            ->expectsOutputToContain('Scope: changed application files since main')
            ->expectsOutputToContain('error thin-controller')
            ->assertExitCode(1);
    }

    public function test_inline_ignore_suppresses_specific_finding(): void
    {
        $this->writeConfig([
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
        // @architecture-kit-ignore thin-controller -- legacy endpoint accepted during migration
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('No architecture violations found.')
            ->expectsOutputToContain('Suppressed: 1 inline, 0 baseline')
            ->assertExitCode(0);
    }

    public function test_invalid_inline_ignore_does_not_suppress_original_finding(): void
    {
        $this->writeConfig([
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
        // @architecture-kit-ignore unknown-rule -- typo
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $this->artisan('architecture-kit:audit --strict')
            ->expectsOutputToContain('error thin-controller')
            ->expectsOutputToContain('warn  invalid-suppression')
            ->assertExitCode(1);
    }

    public function test_update_baseline_suppresses_existing_findings_and_keeps_new_findings_visible(): void
    {
        $this->writeConfig([
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
            ->expectsOutputToContain('No architecture violations found.')
            ->expectsOutputToContain('Suppressed: 0 inline, 1 baseline')
            ->assertExitCode(0);

        $this->assertFileExists($this->tempPath.'/.architecture-kit/baseline.json');

        $this->writeFile('app/Http/Controllers/OtherController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class OtherController
{
    public function destroy($document): void
    {
        $document->delete();
    }
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('error thin-controller')
            ->expectsOutputToContain('Suppressed: 0 inline, 1 baseline')
            ->assertExitCode(1);
    }

    public function test_audit_excludes_skip_matching_paths(): void
    {
        $this->writeRawConfig(<<<'PHP'
<?php

use Taqie\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::ThinControllers,
        Architecture::Actions,
    ],
    'audit' => [
        'exclude' => ['app/Legacy/*'],
    ],
];
PHP);

        $this->writeFile('app/Legacy/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Legacy;

final class DocumentController
{
    public function update($document): void
    {
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('No architecture violations found.')
            ->assertExitCode(0);
    }

    public function test_unparseable_php_file_is_reported_without_crashing_audit(): void
    {
        $this->writeConfig([Architecture::Actions]);
        $this->writeFile('app/Actions/Broken.php', '<?php final class Broken {');

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('warn  unparseable-file')
            ->assertExitCode(0);
    }

    public function test_custom_audit_rule_reports_findings_and_can_be_suppressed(): void
    {
        $this->writeRawConfig(<<<PHP
<?php

use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Tests\Feature\FixtureAuditRule;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        FixtureAuditRule::class,
    ],
];
PHP);

        $this->writeFile('app/Actions/ForbiddenWorkflow.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class ForbiddenWorkflow
{
    public function handle(): void
    {
    }
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('error fixture-audit-rule')
            ->assertExitCode(1);

        $this->writeFile('app/Actions/ForbiddenWorkflow.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

// @architecture-kit-ignore-file fixture-audit-rule -- accepted project exception
final class ForbiddenWorkflow
{
    public function handle(): void
    {
    }
}
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('No architecture violations found.')
            ->expectsOutputToContain('Suppressed: 1 inline, 0 baseline')
            ->assertExitCode(0);
    }

    public function test_invalid_baseline_json_fails_audit(): void
    {
        $this->writeConfig([Architecture::Actions]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit');
        $files->put($this->tempPath.'/.architecture-kit/baseline.json', '{broken');

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('.architecture-kit/baseline.json is invalid or uses an unsupported version.')
            ->assertExitCode(1);
    }

    public function test_missing_custom_audit_rule_fails_with_clear_message(): void
    {
        $this->writeRawConfig(<<<'PHP'
<?php

use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Tests\Feature\MissingFixtureAuditRule;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        MissingFixtureAuditRule::class,
    ],
];
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('Architecture Kit audit rule [Taqie\ArchitectureKit\Tests\Feature\MissingFixtureAuditRule] does not exist.')
            ->assertExitCode(1);
    }

    public function test_custom_audit_rule_must_implement_audit_rule(): void
    {
        $this->writeRawConfig(<<<'PHP'
<?php

use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Tests\Feature\FixtureNotAuditRule;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        FixtureNotAuditRule::class,
    ],
];
PHP);

        $this->artisan('architecture-kit:audit')
            ->expectsOutputToContain('Architecture Kit audit rule [Taqie\ArchitectureKit\Tests\Feature\FixtureNotAuditRule] must implement Taqie\ArchitectureKit\Audit\AuditRule.')
            ->assertExitCode(1);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function writeConfig(array $enabled): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($enabled);
    }

    private function writeRawConfig(string $contents): void
    {
        $this->writeFile('config/architectures.php', $contents);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
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

final class FixtureAuditRule implements AuditRule
{
    public function supports(string $path, array $enabled): bool
    {
        return str_ends_with($path, 'ForbiddenWorkflow.php');
    }

    public function check(FileContext $file): array
    {
        return [
            new AuditFinding('error', 'fixture-audit-rule', $file->path, 8, 'Fixture custom rule failed.'),
        ];
    }
}

final class FixtureNotAuditRule {}
