<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class ProjectArchitectureGraphTest extends TestCase
{
    public function test_audit_detects_port_bypass_but_accepts_the_provider_binding(): void
    {
        $this->writeFile('app/Documents/Ports/DocumentGateway.php', <<<'PHP'
<?php

namespace App\Documents\Ports;

/**
 * Keeps document workflows independent from the external API provider.
 */
interface DocumentGateway
{
    public function fetch(): string;
}
PHP);
        $this->writeFile('app/Documents/Adapters/HttpDocumentGateway.php', <<<'PHP'
<?php

namespace App\Documents\Adapters;

use App\Documents\Ports\DocumentGateway;

final class HttpDocumentGateway implements DocumentGateway
{
    public function fetch(): string
    {
        return 'document';
    }
}
PHP);
        $this->writeFile('app/Actions/FetchDocument.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Documents\Adapters\HttpDocumentGateway;

final class FetchDocument
{
    public function __construct(private HttpDocumentGateway $gateway)
    {
    }
}
PHP);
        $this->writeFile('app/Providers/AppServiceProvider.php', <<<'PHP'
<?php

namespace App\Providers;

use App\Documents\Adapters\HttpDocumentGateway;
use App\Documents\Ports\DocumentGateway;

final class AppServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentGateway::class, HttpDocumentGateway::class);
    }
}
PHP);

        $result = $this->audit([Architecture::PortsAndAdapters]);
        $bypasses = array_values(array_filter(
            $result->findings,
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'E_PORT_BYPASS',
        ));

        $this->assertCount(1, $bypasses);
        $this->assertSame('app/Actions/FetchDocument.php', $bypasses[0]->path);
        $this->assertStringContainsString('DocumentGateway', $bypasses[0]->message);
    }

    public function test_audit_reports_conservative_layer_dependency_and_namespace_cycle(): void
    {
        $this->writeFile('app/Models/Invoice.php', <<<'PHP'
<?php

namespace App\Models;

use App\Actions\CreateInvoice;

final class Invoice
{
    public function __construct(private CreateInvoice $action)
    {
    }
}
PHP);
        $this->writeFile('app/Actions/CreateInvoice.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Models\Invoice;

final class CreateInvoice
{
    public function __construct(private Invoice $invoice)
    {
    }

    public function handle(): void
    {
    }
}
PHP);

        $result = $this->audit([Architecture::Actions]);
        $codes = array_map(fn ($finding): string => (new FindingCodeRegistry)->codeFor($finding), $result->findings);

        $this->assertContains('E_LAYER_DEPENDENCY', $codes);
        $this->assertContains('W_NAMESPACE_CYCLE', $codes);
    }

    public function test_bidirectional_eloquent_relations_do_not_create_a_namespace_cycle(): void
    {
        $this->writeFile('app/Models/Billing/Invoice.php', <<<'PHP'
<?php

namespace App\Models\Billing;

use App\Models\Customers\Customer;

final class Invoice
{
    public function customer(): mixed
    {
        return $this->belongsTo(Customer::class);
    }
}
PHP);
        $this->writeFile('app/Models/Customers/Customer.php', <<<'PHP'
<?php

namespace App\Models\Customers;

use App\Models\Billing\Invoice;

final class Customer
{
    public function invoices(): mixed
    {
        return $this->hasMany(Invoice::class);
    }
}
PHP);

        $result = $this->audit([]);

        $this->assertFalse(collect($result->findings)->contains(
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'W_NAMESPACE_CYCLE',
        ));
    }

    public function test_changed_scope_uses_the_full_graph_and_reports_a_cycle_closed_by_the_changed_edge(): void
    {
        $this->writeFile('app/Actions/RunBilling.php', <<<'PHP'
<?php

namespace App\Actions;

final class RunBilling
{
    public function handle(): void
    {
    }
}
PHP);
        $this->writeFile('app/Services/BillingService.php', <<<'PHP'
<?php

namespace App\Services;

use App\Actions\RunBilling;

final class BillingService
{
    public function __construct(private RunBilling $action)
    {
    }
}
PHP);
        $this->git('init -b main');
        $this->git('config user.email architecture-kit@example.test');
        $this->git('config user.name "Architecture Kit Tests"');
        $this->git('add app');
        $this->git('commit -m initial');
        $this->writeFile('app/Actions/RunBilling.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Services\BillingService;

final class RunBilling
{
    public function __construct(private BillingService $service)
    {
    }

    public function handle(): void
    {
    }
}
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            enabled: [Architecture::Actions, Architecture::Services],
            changedOnly: true,
            baseRef: 'main',
        );

        $this->assertSame('changed application files since main', $result->scope);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'W_NAMESPACE_CYCLE'
                && $finding->path === 'app/Actions/RunBilling.php',
        ));
    }

    public function test_changed_scope_does_not_report_an_unchanged_graph_cycle_without_focused_evidence(): void
    {
        $this->writeFile('app/Actions/RunBilling.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Services\BillingService;

final class RunBilling
{
    public function __construct(private BillingService $service)
    {
    }

    public function handle(): void
    {
    }
}
PHP);
        $this->writeFile('app/Services/BillingService.php', <<<'PHP'
<?php

namespace App\Services;

use App\Actions\RunBilling;

final class BillingService
{
    public function __construct(private RunBilling $action)
    {
    }
}
PHP);
        $this->writeFile('app/Data/ChangedData.php', <<<'PHP'
<?php

namespace App\Data;

final readonly class ChangedData
{
}
PHP);
        $this->git('init -b main');
        $this->git('config user.email architecture-kit@example.test');
        $this->git('config user.name "Architecture Kit Tests"');
        $this->git('add app');
        $this->git('commit -m initial');
        $this->writeFile('app/Data/ChangedData.php', <<<'PHP'
<?php

namespace App\Data;

final readonly class ChangedData
{
    public function __construct(public string $value)
    {
    }
}
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            enabled: [Architecture::Actions, Architecture::Services, Architecture::DataObjects],
            changedOnly: true,
            baseRef: 'main',
        );

        $this->assertFalse(collect($result->findings)->contains(
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'W_NAMESPACE_CYCLE',
        ));
    }

    public function test_excludes_and_inline_ignores_apply_to_project_graph_findings(): void
    {
        $this->writeFile('app/Infrastructure/PaymentAdapter.php', <<<'PHP'
<?php

namespace App\Infrastructure;

final class PaymentAdapter
{
}
PHP);
        $this->writeFile('app/Actions/PayInvoice.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Infrastructure\PaymentAdapter;

final class PayInvoice
{
    // @architecture-kit-ignore layer-dependency -- temporary accepted boundary
    public function __construct(private PaymentAdapter $adapter)
    {
    }
}
PHP);

        $suppressed = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            enabled: [Architecture::Actions],
            changedOnly: false,
        );
        $excluded = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            enabled: [Architecture::Actions],
            changedOnly: false,
            exclude: ['app/Infrastructure/**'],
        );

        $this->assertSame(1, $suppressed->suppressedInline);
        $this->assertFalse(collect($suppressed->findings)->contains(
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'E_LAYER_DEPENDENCY',
        ));
        $this->assertFalse(collect($excluded->findings)->contains(
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'E_LAYER_DEPENDENCY',
        ));
    }

    public function test_baseline_suppresses_a_project_graph_finding_with_a_stable_fingerprint(): void
    {
        $this->writeFile('app/Infrastructure/PaymentAdapter.php', <<<'PHP'
<?php

namespace App\Infrastructure;

final class PaymentAdapter
{
}
PHP);
        $this->writeFile('app/Actions/PayInvoice.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Infrastructure\PaymentAdapter;

final class PayInvoice
{
    public function __construct(private PaymentAdapter $adapter)
    {
    }

    public function handle(): void
    {
    }
}
PHP);
        $audit = new ApplicationAudit(new Filesystem, $this->tempPath);
        $updated = $audit->run(
            enabled: [Architecture::Actions],
            changedOnly: false,
            updateBaseline: true,
        );
        $second = $audit->run(
            enabled: [Architecture::Actions],
            changedOnly: false,
        );

        $this->assertSame(1, $updated->suppressedBaseline);
        $this->assertSame(1, $second->suppressedBaseline);
        $this->assertFalse(collect($second->findings)->contains(
            fn ($finding): bool => (new FindingCodeRegistry)->codeFor($finding) === 'E_LAYER_DEPENDENCY',
        ));
    }

    /** @param array<int, Architecture|string> $enabled */
    private function audit(array $enabled)
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run($enabled, changedOnly: false);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }

    private function git(string $command): void
    {
        exec('git -C '.escapeshellarg($this->tempPath).' '.$command.' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
