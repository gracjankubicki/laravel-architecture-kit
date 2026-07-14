<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

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

use App\Models\Document;

final class DocumentController
{
    public function update(Document $document): void
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
        $this->assertSame(['inline' => 0, 'baseline' => 0], $payload['sup']);
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

    public function test_guard_fails_on_enabled_scoped_custom_audit_rule(): void
    {
        $this->writeCustomArchitecture('billing-workflows');
        $this->writeCurrentResources(['billing-workflows']);
        $this->writeFile('config/architectures.php', <<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Tests\Fixtures\ForbiddenWorkflowAuditRule;

return [
    'enabled' => [
        'billing-workflows',
    ],
    'rules' => [
        'billing-workflows' => [
            ForbiddenWorkflowAuditRule::class,
        ],
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

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"rule": "forbidden-workflow-audit-rule"', $output);
    }

    public function test_guard_passes_when_scoped_custom_audit_rule_architecture_is_disabled(): void
    {
        $this->writeCustomArchitecture('billing-workflows');
        $this->writeCurrentResources([Architecture::Actions]);
        $this->writeFile('config/architectures.php', <<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Tests\Fixtures\ForbiddenWorkflowAuditRule;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        'billing-workflows' => [
            ForbiddenWorkflowAuditRule::class,
        ],
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

        $exitCode = Artisan::call('architecture-kit:guard', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ok": true', $output);
        $this->assertStringNotContainsString('forbidden-workflow-audit-rule', $output);
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

use App\Models\Document;

final class DocumentController
{
    public function update(Document $document): void
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

    public function test_guard_agent_output_includes_suppressed_counts(): void
    {
        $this->writeCurrentResources([
            Architecture::ThinControllers,
            Architecture::Actions,
        ]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;

final class DocumentController
{
    public function update(Document $document): void
    {
        // @architecture-kit-ignore thin-controller -- legacy endpoint accepted during migration
        $document->update(['name' => 'changed']);
    }
}
PHP);

        $exitCode = Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['inline' => 1, 'baseline' => 0], $payload['sup']);
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

use App\Models\Document;

final class DocumentController
{
    public function update(Document $document): void
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
        $this->assertStringContainsString('must not mutate domain state', $output);
        $this->assertStringContainsString('must not orchestrate infrastructure or workflow side effects', $output);
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

use App\Models\Document;

final class DocumentController
{
    public function update(Document $document): void
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

    private function writeCurrentResources(array $enabled): void
    {
        $files = new Filesystem;
        $composer = json_decode($files->get($this->tempPath.'/composer.json'), true);
        $composer = is_array($composer) ? $composer : [];
        $composer['require'] ??= [];
        $composer['require']['gracjankubicki/laravel-architecture-kit'] = '^0.2';
        $files->put($this->tempPath.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $laravelAi = null;

        if (in_array(Architecture::LaravelAi, $enabled, true)) {
            $constraint = is_string($composer['require']['laravel/ai'] ?? null) ? $composer['require']['laravel/ai'] : '^0.8';
            $version = str_contains($constraint, '0.9') ? '0.9.0' : '0.8.1';
            $composer['require']['laravel/ai'] = $constraint;
            $files->put($this->tempPath.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $files->put($this->tempPath.'/composer.lock', json_encode([
                'packages' => [['name' => 'laravel/ai', 'version' => $version]],
                'packages-dev' => [],
            ], JSON_THROW_ON_ERROR));
            $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
            $files->put($this->tempPath.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$version}']]];\n");
            $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Responses');
            $files->put($this->tempPath.'/vendor/laravel/ai/src/Responses/StructuredAgentResponse.php', '<?php class StructuredAgentResponse implements ArrayAccess { public function toArray(): array {} }');
            $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Concerns');
            $files->put($this->tempPath.'/vendor/laravel/ai/src/Concerns/ProviderOptions.php', '<?php trait ProviderOptions { public function withProviderOptions(array $options): static {} }');
            $laravelAi = LaravelAiRequirement::resolve($files, $this->tempPath);
        }

        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files, laravelAi: $laravelAi);

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

    private function writeCustomArchitecture(string $slug): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/'.$slug);
        $files->put($this->tempPath.'/.architecture-kit/architectures/'.$slug.'/guideline.md', 'Custom architecture guideline.');
    }

    private function git(string $arguments): void
    {
        $output = [];
        $exitCode = 0;

        exec('git -C '.escapeshellarg($this->tempPath).' '.$arguments.' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
