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
    public function test_guard_agent_output_reports_audit_findings_without_changing_json_contract(): void
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

        $exitCode = Artisan::call('architecture-kit:guard', ['--agent' => true, '--strict' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('guard', $payload['cmd']);
        $this->assertSame('ok', $payload['doctor']);
        $this->assertSame('fail', $payload['audit']);
        $this->assertSame('E_THIN_CONTROLLER_MODEL_WRITE', $payload['find'][0]['m']);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true, '--strict' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"doctor"', $output);
        $this->assertStringContainsString('"audit"', $output);
        $this->assertStringContainsString('"message": "Controller mutates a model directly; move the write use case to an Action."', $output);
    }

    public function test_guard_agent_output_marks_audit_as_skipped_when_doctor_blocks(): void
    {
        $this->writeFile('config/architectures.php', <<<'PHP'
<?php

return [
    'enabled' => [
        'billing-workflows',
    ],
];
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $payload['doctor']);
        $this->assertSame('skip', $payload['audit']);
        $this->assertSame(['run:architecture-kit:install', 'rerun:guard --agent'], $payload['next']);
    }

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

    public function test_guard_json_includes_suppressed_counts(): void
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
        // @architecture-kit-ignore thin-controller -- legacy endpoint accepted during migration
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"suppressed"', $output);
        $this->assertStringContainsString('"inline": 1', $output);
        $this->assertStringContainsString('"baseline": 0', $output);
    }

    public function test_guard_json_fails_when_baseline_json_is_invalid(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit');
        $files->put($this->tempPath.'/.architecture-kit/baseline.json', '{broken');

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"ok": false', $output);
        $this->assertStringContainsString('.architecture-kit/baseline.json is invalid or uses an unsupported version.', $output);
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

    public function test_thin_controller_architecture_ignores_forbidden_tokens_in_comments_and_strings(): void
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
    public function __invoke(): array
    {
        // $this->validate([]);
        // DB::transaction(fn () => null);
        // DocumentUploaded::dispatch();
        // $document->update([]);
        // $document->delete();
        // Document::create([]);
        // use App\Services\DocumentIntake\DocumentIntakeService;
        // public function __construct(private DocumentIntakeService $intake)

        return [
            'debug' => 'validate(), DB::transaction(), dispatch(), update(), delete(), create(), App\\Services',
        ];
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_ignores_actions_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $this->writeFile('app/Actions/Documents/ApproveDocument.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final class ApproveDocument
{
    public function handle(string $documentId): void
    {
        // use Illuminate\Http\Request;
        // public function handle(Request $request): JsonResponse
        $debug = 'Symfony\Component\HttpFoundation\Response and Illuminate\Foundation\Http\FormRequest are examples only';
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
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

    public function test_it_flags_invalid_form_request_shape(): void
    {
        $this->writeCurrentResources([
            Architecture::FormRequests,
            Architecture::DataObjects,
        ]);

        $this->writeFile('app/Http/Requests/Documents/StoreDocumentRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Auth\EmailVerificationRequest;

final class StoreDocumentRequest extends EmailVerificationRequest
{
    #[\Override]
    public function authorize(): bool
    {
        return true;
    }

    #[\Override]
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string'],
        ];
    }

    public function data(): array
    {
        return $this->validated();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "form-request"', $output);
        $this->assertStringContainsString('Do not extend EmailVerificationRequest as a generic FormRequest base class', $output);
        $this->assertStringContainsString('Do not define data() on FormRequests; use toData()', $output);
        $this->assertStringContainsString('Do not add #[\\\\Override] to FormRequest authorize()', $output);
        $this->assertStringContainsString('Do not add #[\\\\Override] to FormRequest rules()', $output);
        $this->assertStringContainsString('Data Objects are enabled; FormRequest should expose toData()', $output);
    }

    public function test_it_ignores_form_request_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([
            Architecture::FormRequests,
            Architecture::DataObjects,
        ]);

        $this->writeFile('app/Http/Requests/Documents/StoreDocumentRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Data\Documents\StoreDocumentData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // #[\Override]
        $debug = 'EmailVerificationRequest and function data() are documentation examples only';

        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string'],
        ];
    }

    public function toData(): StoreDocumentData
    {
        return new StoreDocumentData((string) $this->validated('document_type'));
    }
}
PHP);

        $this->writeFile('app/Data/Documents/StoreDocumentData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Data\Documents;

final readonly class StoreDocumentData
{
    public function __construct(public string $documentType)
    {
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_flags_queries_and_lazy_loading_inside_api_resources(): void
    {
        $this->writeCurrentResources([Architecture::ApiResources]);

        $this->writeFile('app/Http/Resources/DocumentResource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'related' => Document::query()->where('case_id', $this->case_id)->count(),
            'customer' => $this->resource->loadMissing('customer'),
            'lines' => $this->resource->load('lines'),
        ];
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "api-resource"', $output);
        $this->assertStringContainsString('API Resources must not query the database', $output);
        $this->assertStringContainsString('API Resources must format loaded data, not build queries', $output);
        $this->assertStringContainsString('API Resources must not trigger lazy loading', $output);
        $this->assertStringContainsString('API Resources must not trigger loading', $output);
    }

    public function test_it_ignores_api_resource_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::ApiResources]);

        $this->writeFile('app/Http/Resources/DocumentResource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Document::query()->where('case_id', $this->case_id)->count();
        $debug = 'load(), loadMissing(), where(), and ::query() are documentation examples only';

        return [
            'id' => $this->id,
        ];
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_modern_php_architecture_uses_real_declare_and_override_attributes(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'php' => '^8.5',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([
            Architecture::ModernPhp85,
            Architecture::ApiResources,
        ]);

        $this->writeFile('app/Http/Resources/DocumentResource.php', <<<'PHP'
<?php

// declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentResource extends JsonResource
{
    // #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'debug' => '#[\Override]',
        ];
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--strict' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "modern-php-85"', $output);
        $this->assertStringContainsString('Changed PHP files should declare strict_types=1', $output);
        $this->assertStringContainsString('Add #[\\\\Override] to toArray().', $output);
    }

    public function test_it_allows_custom_eloquent_builder_query_vocabulary(): void
    {
        $this->writeCurrentResources([Architecture::CustomEloquentBuilders]);

        $this->writeFile('app/Models/Builders/DocumentBuilder.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

final class DocumentBuilder extends Builder
{
    public function uploaded(): self
    {
        return $this->where('status', 'uploaded');
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_flags_invalid_custom_eloquent_builder_shape_and_behavior(): void
    {
        $this->writeCurrentResources([Architecture::CustomEloquentBuilders]);

        $this->writeFile('app/Models/Builders/DocumentReport.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Events\DocumentUploaded;
use Illuminate\Http\Request;

class DocumentReport
{
    public function uploaded(Request $request): void
    {
        $this->update(['status' => 'uploaded']);
        DocumentUploaded::dispatch();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('"rule": "custom-eloquent-builders"', $output);
        $this->assertStringContainsString('must contain final custom Eloquent Builder classes only', $output);
        $this->assertStringContainsString('must be final', $output);
        $this->assertStringContainsString('must extend Illuminate\\\\Database\\\\Eloquent\\\\Builder', $output);
        $this->assertStringContainsString('must use the Builder suffix', $output);
        $this->assertStringContainsString('must not depend on HTTP request or response classes', $output);
        $this->assertStringContainsString('must not mutate domain state', $output);
        $this->assertStringContainsString('must not dispatch events or jobs', $output);
    }

    public function test_it_ignores_custom_eloquent_builder_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::CustomEloquentBuilders]);

        $this->writeFile('app/Models/Builders/DocumentBuilder.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

final class DocumentBuilder extends Builder
{
    public function uploaded(): self
    {
        // use Illuminate\Http\Request;
        // DocumentUploaded::dispatch();
        // $this->update(['status' => 'uploaded']);
        $debug = 'DB::transaction(), dispatch(), update(), delete(), Request and Response are examples only';

        return $this->where('status', 'uploaded');
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_allows_immutable_data_objects(): void
    {
        $this->writeCurrentResources([Architecture::DataObjects]);

        $this->writeFile('app/Data/Documents/StartDocumentPseudonymizationData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Data\Documents;

final readonly class StartDocumentPseudonymizationData
{
    public function __construct(
        public int $documentId,
        public string $mode,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            documentId: (int) $data['document_id'],
            mode: (string) $data['mode'],
        );
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_flags_invalid_data_object_shape_and_behavior(): void
    {
        $this->writeCurrentResources([Architecture::DataObjects]);

        $this->writeFile('app/Data/Documents/DocumentPayload.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Data\Documents;

use App\Events\DocumentUploaded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentPayload extends Model
{
    public function setDocumentId(int $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $this->update(['document_id' => $documentId]);
            DocumentUploaded::dispatch();
        });
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('"rule": "data-objects"', $output);
        $this->assertStringContainsString('app/Data/** must contain Data Objects', $output);
        $this->assertStringContainsString('must not extend Eloquent Model', $output);
        $this->assertStringContainsString('must not expose setters', $output);
        $this->assertStringContainsString('must not orchestrate infrastructure or workflow side effects', $output);
        $this->assertStringContainsString('must not mutate domain state', $output);
        $this->assertStringContainsString('must not dispatch workflow side effects', $output);
    }

    public function test_it_ignores_data_object_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::DataObjects]);

        $this->writeFile('app/Data/Documents/StartDocumentPseudonymizationData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Data\Documents;

final readonly class StartDocumentPseudonymizationData
{
    public function __construct(
        public int $documentId,
    ) {
    }

    public static function fromArray(array $data): self
    {
        // public function setDocumentId(int $documentId): void {}
        // DocumentUploaded::dispatch();
        // DB::transaction(fn () => $this->update([]));
        $debug = 'Model, update(), delete(), dispatch(), DB::transaction() are examples only';

        return new self((int) $data['document_id']);
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_allows_immutable_value_objects(): void
    {
        $this->writeCurrentResources([Architecture::ValueObjects]);

        $this->writeFile('app/ValueObjects/Money.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }

    public function add(self $money): self
    {
        return new self(
            amount: $this->amount + $money->amount,
            currency: $this->currency,
        );
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_flags_invalid_value_object_shape_and_mutation(): void
    {
        $this->writeCurrentResources([Architecture::ValueObjects]);

        $this->writeFile('app/ValueObjects/MoneyValueObject.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Database\Eloquent\Model;

class MoneyValueObject extends Model
{
    public int $amount = 0;

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('"rule": "value-objects"', $output);
        $this->assertStringContainsString('Value Object folders must contain final readonly Value Object classes only', $output);
        $this->assertStringContainsString('Value Objects must be final classes', $output);
        $this->assertStringContainsString('Value Objects must be readonly classes', $output);
        $this->assertStringContainsString('do not use Value, ValueObject, or Vo suffixes', $output);
        $this->assertStringContainsString('Value Objects must not extend Eloquent Model', $output);
        $this->assertStringContainsString('Value Objects must not expose setters', $output);
        $this->assertStringContainsString('must return new objects instead of mutating current state', $output);
    }

    public function test_it_ignores_value_object_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::ValueObjects]);

        $this->writeFile('app/Domain/Billing/ValueObjects/Money.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

final readonly class Money
{
    public function __construct(
        public int $amount,
    ) {
    }

    public function add(self $money): self
    {
        // class MoneyValueObject extends Model {}
        // public function setAmount(int $amount): void {}
        // $this->amount = $amount;
        $debug = 'ValueObject, Vo, setAmount(), Model, and $this->amount = 1 are examples only';

        return new self($this->amount + $money->amount);
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_ignores_enum_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([
            Architecture::Enums,
            Architecture::ApiResources,
        ]);

        $this->writeFile('app/Http/Requests/Documents/StoreDocumentRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        // 'document_type' => ['required', Rule::in(Document::TYPES)],
        $debug = 'Rule::in(Document::STATUSES) should use Rule::enum()';

        return [
            'document_type' => ['required', 'string'],
        ];
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
    // public const STATUS_UPLOADED = 'uploaded';
    // public const TYPES = ['opponent_letter'];
    public const DEBUG = 'public const STATUSES = []';
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
    public function toArray(Request $request): array
    {
        // 'status' => $this->status,
        $debug = "'type' => $this->document_type";

        return [
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
        ];
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_requires_real_enum_declarations_in_enum_folders(): void
    {
        $this->writeCurrentResources([Architecture::Enums]);

        $this->writeFile('app/Enums/Documents/DocumentStatus.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Enums\Documents;

final class DocumentStatus
{
    // enum DocumentStatus: string {}
    public const DEBUG = 'enum DocumentStatus';
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('app/Enums/** must contain Enums only', $output);
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
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
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
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
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

    public function test_laravel_ai_architecture_ignores_forbidden_tokens_in_comments_and_strings(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'laravel/ai' => '^0.8',
            ],
        ], JSON_PRETTY_PRINT));

        $this->writeCurrentResources([Architecture::LaravelAi]);

        $this->writeFile('app/Http/Controllers/DocumentSummaryController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentSummaryController
{
    public function show(): array
    {
        // DocumentSummaryAgent::make()->prompt('Summarize the document.');
        // Laravel\Ai\Agents\Agent::prompt('Summarize the document.');
        // Embeddings::for('document');
        // new class implements Tool {};
        // function runAgent(string $agent, string $input): array {}
        // new StructuredGatewayAgent('document')
        // ->prompt('Summarize', provider: 'openrouter', model: 'openai/gpt-4.1');

        return [
            'debug' => 'Agent::make()->prompt(), Embeddings::for(), StructuredGatewayAgent, provider: "openrouter"',
        ];
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
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

    public function test_service_architecture_ignores_forbidden_tokens_in_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::Services]);

        $this->writeFile('app/Services/Documents/DocumentPseudonymizationService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Documents;

final class DocumentPseudonymizationService
{
    public function describe(): string
    {
        // use Illuminate\Http\Request;
        // public static function normalize(): string
        // app(PseudonymizationMapResolver::class)
        // function approve(Request $request): JsonResponse

        return "enum DocumentPseudonymizationType { case Ready; }";
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_query_object_architecture_ignores_forbidden_tokens_in_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::QueryObjects]);

        $this->writeFile('app/Queries/Documents/SearchDocuments.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Queries\Documents;

final class SearchDocuments
{
    public function handle(): array
    {
        // use Illuminate\Http\Request;
        // function handle(Request $request): JsonResponse
        // Document::query()->update(['status' => 'archived']);
        // DB::transaction(fn () => Document::query()->delete());
        // ProcessDocuments::dispatch();

        return [
            'example' => 'Document::query()->whereIn("status", ["ready"])',
        ];
    }
}
PHP);

        $this->writeFile('app/Http/Controllers/DocumentSearchController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentSearchController
{
    public function __invoke(): array
    {
        return [];
    }

    private function example(): string
    {
        return 'private function search() { Document::query()->whereIn("status", ["ready"]); }';
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
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

    public function test_global_dependency_rules_ignore_forbidden_tokens_in_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentController
{
    public function __invoke(): array
    {
        // app(ResolveDocumentPseudonymizationMap::class);
        // private static function map(): ResolveDocumentPseudonymizationMap { return new ResolveDocumentPseudonymizationMap(); }

        return [
            'debug' => 'app(ResolveDocumentPseudonymizationMap::class) and return new ResolveDocumentPseudonymizationMap() are docs only',
        ];
    }
}
PHP);

        $this->artisan('architecture-kit:guard --strict')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_exception_folder_purity_uses_real_exception_inheritance(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        $this->writeFile('app/Exceptions/DocumentFailure.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Exceptions;

final class DocumentFailure
{
    // extends RuntimeException
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('app/Exceptions/** must contain Exceptions only.', $output);
    }

    public function test_it_allows_eloquent_lifecycle_observer_delegating_to_one_handler(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Observers/InvoiceObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Lifecycle\Invoices\NormalizeInvoiceBeforeUpdate;
use App\Models\Invoice;

final readonly class InvoiceObserver
{
    public function __construct(
        private NormalizeInvoiceBeforeUpdate $normalizeInvoiceBeforeUpdate,
    ) {
    }

    public function updating(Invoice $invoice): void
    {
        $this->normalizeInvoiceBeforeUpdate->handle($invoice);
    }
}
PHP);

        $this->writeFile('app/Lifecycle/Invoices/NormalizeInvoiceBeforeUpdate.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Lifecycle\Invoices;

use App\Models\Invoice;

final class NormalizeInvoiceBeforeUpdate
{
    public function handle(Invoice $invoice): void
    {
        $invoice->number = trim((string) $invoice->number);
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_allows_single_after_save_named_event_dispatch(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Observers/InvoiceObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\InvoiceUpdated;
use App\Events\InvoiceUpdatedData;
use App\Models\Invoice;

final class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        InvoiceUpdated::dispatch(InvoiceUpdatedData::fromModel($invoice));
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_flags_side_effects_and_branching_in_before_save_observer(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Observers/InvoiceObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\InvoiceApproved;
use App\Models\Invoice;

final class InvoiceObserver
{
    public function updating(Invoice $invoice): void
    {
        if ($invoice->status === 'approved') {
            app(InvoiceAuditService::class)->record($invoice);
            $invoice->customer->update(['has_approved_invoice' => true]);
            InvoiceApproved::dispatch($invoice);
        }
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"severity": "error"', $output);
        $this->assertStringContainsString('"severity": "warn"', $output);
        $this->assertStringContainsString('"rule": "eloquent-lifecycle"', $output);
        $this->assertStringContainsString('Before-save observers must not resolve collaborators with app()', $output);
        $this->assertStringContainsString('Before-save observers must not write other models', $output);
        $this->assertStringContainsString('Before-save observers must not dispatch events or jobs', $output);
        $this->assertStringContainsString('Business branching does not belong in observer methods', $output);
    }

    public function test_it_allows_technical_dirty_check_in_observer_before_delegation(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Observers/InvoiceObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Lifecycle\Invoices\NormalizeInvoiceBeforeUpdate;
use App\Models\Invoice;

final readonly class InvoiceObserver
{
    public function __construct(
        private NormalizeInvoiceBeforeUpdate $normalizeInvoiceBeforeUpdate,
    ) {
    }

    public function updating(Invoice $invoice): void
    {
        if (! $invoice->isDirty(['number'])) {
            return;
        }

        $this->normalizeInvoiceBeforeUpdate->handle($invoice);
    }
}
PHP);

        $this->writeFile('app/Lifecycle/Invoices/NormalizeInvoiceBeforeUpdate.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Lifecycle\Invoices;

use App\Models\Invoice;

final class NormalizeInvoiceBeforeUpdate
{
    public function handle(Invoice $invoice): void
    {
        $invoice->number = trim((string) $invoice->number);
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_ignores_eloquent_lifecycle_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Observers/InvoiceObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Lifecycle\Invoices\NormalizeInvoiceBeforeUpdate;
use App\Models\Invoice;

final readonly class InvoiceObserver
{
    public function __construct(
        private NormalizeInvoiceBeforeUpdate $normalizeInvoiceBeforeUpdate,
    ) {
    }

    public function updating(Invoice $invoice): void
    {
        // app(InvoiceAuditService::class)->record($invoice);
        $debug = 'Http::post and InvoiceApproved::dispatch are documentation examples only';

        $this->normalizeInvoiceBeforeUpdate->handle($invoice);
    }
}
PHP);

        $this->writeFile('app/Lifecycle/Invoices/NormalizeInvoiceBeforeUpdate.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Lifecycle\Invoices;

use App\Models\Invoice;

final class NormalizeInvoiceBeforeUpdate
{
    public function handle(Invoice $invoice): void
    {
        $invoice->number = trim((string) $invoice->number);
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_warns_about_side_effects_inside_database_transactions(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Actions/Invoices/ApproveInvoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Invoices;

use App\Events\InvoiceApproved;
use Illuminate\Support\Facades\DB;

final class ApproveInvoice
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            InvoiceApproved::dispatch();
        });
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--strict' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "transaction-side-effects"', $output);
        $this->assertStringContainsString('Do not dispatch events, jobs, notifications, mail, HTTP, or external API calls inside an open DB transaction', $output);
    }

    public function test_it_warns_when_after_commit_is_applied_to_observer_with_before_methods(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Observers/InvoiceObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Invoice;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class InvoiceObserver implements ShouldHandleEventsAfterCommit
{
    public function creating(Invoice $invoice): void
    {
        $invoice->uuid ??= 'invoice-uuid';
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"severity": "warn"', $output);
        $this->assertStringContainsString('Do not delay the whole observer with ShouldHandleEventsAfterCommit', $output);
    }

    public function test_it_warns_on_quiet_saves_provider_registration_and_inline_model_events(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Actions/Invoices/UpdateInvoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Invoices;

final class UpdateInvoice
{
    public function handle($invoice): void
    {
        $invoice->saveQuietly();
    }
}
PHP);

        $this->writeFile('app/Providers/AppServiceProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);
    }
}
PHP);

        $this->writeFile('app/Models/Invoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            $invoice->number = trim((string) $invoice->number);
        });
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Quiet saves/withoutEvents suggest observers carry behavior', $output);
        $this->assertStringContainsString('Register observers with #[ObservedBy(...)] on the model', $output);
        $this->assertStringContainsString('Concrete models must not register inline lifecycle closures', $output);
    }

    public function test_it_enforces_lifecycle_folder_purity(): void
    {
        $this->writeCurrentResources([Architecture::EloquentLifecycle]);

        $this->writeFile('app/Lifecycle/Invoices/InvoiceLifecycleData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Lifecycle\Invoices;

final readonly class InvoiceLifecycleData
{
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "eloquent-lifecycle"', $output);
        $this->assertStringContainsString('Lifecycle folders must contain final lifecycle handlers only', $output);
    }

    public function test_it_allows_complete_saloon_integration_through_action_boundary(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Http/Integrations/Fakturownia/FakturowniaConnector.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia;

use Saloon\Http\Connector;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class FakturowniaConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasRateLimits;

    public ?int $tries = 3;
    public ?int $retryInterval = 500;
    public ?bool $useExponentialBackoff = true;

    public function resolveBaseUrl(): string
    {
        return config('services.fakturownia.url');
    }
}
PHP);

        $this->writeFile('app/Http/Integrations/Fakturownia/Requests/CreateInvoiceRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia\Requests;

use App\Http\Integrations\Fakturownia\Dto\InvoiceCreatedData;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

final class CreateInvoiceRequest extends Request
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/invoices.json';
    }

    public function createDtoFromResponse(Response $response): InvoiceCreatedData
    {
        return InvoiceCreatedData::fromArray($response->json());
    }
}
PHP);

        $this->writeFile('app/Http/Integrations/Fakturownia/Dto/InvoiceCreatedData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia\Dto;

final readonly class InvoiceCreatedData
{
    public function __construct(
        public string $externalId,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self((string) $payload['id']);
    }
}
PHP);

        $this->writeFile('app/Actions/Invoicing/IssueExternalInvoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Http\Integrations\Fakturownia\FakturowniaConnector;
use App\Http\Integrations\Fakturownia\Requests\CreateInvoiceRequest;

final readonly class IssueExternalInvoice
{
    public function __construct(
        private FakturowniaConnector $fakturownia,
    ) {
    }

    public function handle(): string
    {
        return $this->fakturownia
            ->send(new CreateInvoiceRequest())
            ->dtoOrFail()
            ->externalId;
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_fails_on_raw_http_outside_saloon_integrations(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Http/Controllers/InvoiceController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

final class InvoiceController
{
    public function store(): array
    {
        return Http::post('https://example.test/invoices')->json();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "raw-http"', $output);
        $this->assertStringContainsString('Raw Laravel Http:: calls are forbidden when Saloon is enabled', $output);
    }

    public function test_it_ignores_saloon_forbidden_tokens_inside_comments_and_strings(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Actions/Invoicing/DocumentExternalInvoiceRules.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

final class DocumentExternalInvoiceRules
{
    public function handle(): string
    {
        // Http::post('https://example.test/invoices')->json();
        // $client = new Client();

        return 'curl_init(), file_get_contents("http://example.test"), and App\\Http\\Integrations\\Fakturownia are examples only';
    }
}
PHP);

        $this->artisan('architecture-kit:guard')
            ->expectsOutputToContain('Architecture guard passed.')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_controller_imports_or_sends_saloon_integration(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Http/Controllers/InvoiceController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Integrations\Fakturownia\FakturowniaConnector;
use App\Http\Integrations\Fakturownia\Requests\CreateInvoiceRequest;

final class InvoiceController
{
    public function store(): mixed
    {
        return (new FakturowniaConnector())->send(new CreateInvoiceRequest());
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "saloon"', $output);
        $this->assertStringContainsString('must not import integration classes', $output);
        $this->assertStringContainsString('must not instantiate Saloon Connectors', $output);
    }

    public function test_it_enforces_saloon_folder_purity_and_dto_shape(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Http/Integrations/Fakturownia/InvoiceController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia;

final class InvoiceController
{
}
PHP);

        $this->writeFile('app/Http/Integrations/Fakturownia/Dto/InvoicePayload.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia\Dto;

final class InvoicePayload
{
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "folder-purity"', $output);
        $this->assertStringContainsString('app/Http/Integrations/** must contain Saloon Connectors', $output);
        $this->assertStringContainsString('Integration DTOs under app/Http/Integrations/**/Dto/** must be final readonly', $output);
    }

    public function test_it_warns_on_saloon_connector_hygiene_and_errors_on_env(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Http/Integrations/Fakturownia/FakturowniaConnector.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia;

use Saloon\Http\Connector;

class FakturowniaConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return env('FAKTUROWNIA_URL', 'https://example.test');
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"severity": "error"', $output);
        $this->assertStringContainsString('"severity": "warn"', $output);
        $this->assertStringContainsString('Do not call env() inside integrations', $output);
        $this->assertStringContainsString('Saloon Connectors should be final classes', $output);
        $this->assertStringContainsString('AlwaysThrowOnErrors', $output);
        $this->assertStringContainsString('HasRateLimits', $output);
    }

    public function test_it_flags_absolute_saloon_request_endpoints(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Http/Integrations/Fakturownia/Requests/CreateInvoiceRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Fakturownia\Requests;

use Saloon\Http\Request;

final class CreateInvoiceRequest extends Request
{
    public function resolveEndpoint(): string
    {
        return 'https://example.test/invoices';
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Saloon Request endpoints must be relative paths', $output);
    }

    public function test_it_warns_on_raw_saloon_response_consumption_outside_integrations(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Actions/Invoicing/IssueExternalInvoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Http\Integrations\Fakturownia\FakturowniaConnector;
use App\Http\Integrations\Fakturownia\Requests\CreateInvoiceRequest;

final readonly class IssueExternalInvoice
{
    public function __construct(
        private FakturowniaConnector $fakturownia,
    ) {
    }

    public function handle(): array
    {
        $response = $this->fakturownia->send(new CreateInvoiceRequest());

        return $response->json();
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--strict' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must not consume raw Saloon responses with ->json() or ->body()', $output);
    }

    public function test_it_fails_when_integration_dto_leaks_outside_use_case_boundary(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Support/InvoicePresenter.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Integrations\Fakturownia\Dto\InvoiceCreatedData;

final class InvoicePresenter
{
    public function present(InvoiceCreatedData $data): array
    {
        return ['external_id' => $data->externalId];
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Integration DTOs must not leak outside Actions, Jobs, or app/Http/Integrations/**', $output);
    }

    public function test_it_warns_when_saloon_send_runs_inside_database_transaction(): void
    {
        $this->writeSaloonComposer();
        $this->writeCurrentResources([Architecture::Saloon]);

        $this->writeFile('app/Actions/Invoicing/IssueExternalInvoice.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Http\Integrations\Fakturownia\FakturowniaConnector;
use App\Http\Integrations\Fakturownia\Requests\CreateInvoiceRequest;
use Illuminate\Support\Facades\DB;

final readonly class IssueExternalInvoice
{
    public function __construct(
        private FakturowniaConnector $fakturownia,
    ) {
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $this->fakturownia->send(new CreateInvoiceRequest());
        });
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--strict' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "transaction-side-effects"', $output);
        $this->assertStringContainsString('Do not send Saloon requests inside an open DB transaction', $output);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    private function writeCurrentResources(array $enabled): void
    {
        $files = new Filesystem;
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
        $files = new Filesystem;
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }

    private function writeSaloonComposer(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'saloonphp/saloon' => '^4.0',
                'saloonphp/laravel-plugin' => '^4.0',
                'saloonphp/rate-limit-plugin' => '^4.0',
            ],
        ], JSON_PRETTY_PRINT));
    }

    private function git(string $arguments): void
    {
        $output = [];
        $exitCode = 0;

        exec('git -C '.escapeshellarg($this->tempPath).' '.$arguments.' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
