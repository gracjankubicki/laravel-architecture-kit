<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class RouteLogicRuleTest extends TestCase
{
    public function test_a_route_closure_that_owns_a_workflow_is_reported(): void
    {
        // This is the shape that used to leave a gate green purely because of where the
        // file was saved.
        $this->writeFile('routes/api.php', <<<'PHP'
<?php

use App\Jobs\SendInvoice;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/invoices/{invoice}', function (Illuminate\Http\Request $request, Invoice $invoice) {
    $request->validate(['total' => 'required']);

    DB::transaction(function () use ($invoice) {
        $invoice->update(['status' => 'sent']);
    });

    SendInvoice::dispatch($invoice);
});
PHP);

        $codes = $this->codes($this->audit());

        $this->assertContains('E_ROUTE_INLINE_VALIDATION', $codes);
        $this->assertContains('E_ROUTE_TRANSACTION', $codes);
        $this->assertContains('E_ROUTE_MODEL_WRITE', $codes);
        $this->assertContains('E_ROUTE_DISPATCH', $codes);
    }

    public function test_each_finding_points_at_the_route_file_and_the_offending_line(): void
    {
        $this->writeFile('routes/web.php', <<<'PHP'
<?php

use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', function () {
    Invoice::create(['total' => 1]);
});
PHP);

        $findings = $this->findingsFor($this->audit(), 'route-logic');

        $this->assertCount(1, $findings);
        $this->assertSame('routes/web.php', $findings[0]->path);
        $this->assertSame(7, $findings[0]->line);
        $this->assertSame('E_ROUTE_MODEL_WRITE', $findings[0]->code);
    }

    public function test_a_route_file_is_invisible_until_the_project_puts_it_in_scope(): void
    {
        // The default scope is unchanged, so a project that upgrades the package without
        // touching its config keeps the result it had.
        $this->writeFile('routes/web.php', <<<'PHP'
<?php

use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', function () {
    Invoice::create(['total' => 1]);
});
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers],
            changedOnly: false,
        );

        $this->assertSame([], $this->findingsFor($result, 'route-logic'));
    }

    public function test_a_route_that_only_points_at_a_controller_is_accepted(): void
    {
        $this->writeFile('routes/web.php', <<<'PHP'
<?php

use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', [InvoiceController::class, 'index']);
PHP);

        $this->assertSame([], $this->findingsFor($this->audit(), 'route-logic'));
    }

    public function test_a_file_in_scope_never_becomes_a_layer_of_its_own(): void
    {
        // The stand-in symbol exists so a classless file contributes its dependencies.
        // Classifying it by path would make routes/Actions/* an `application` symbol and
        // its dependency on infrastructure would then be reported as a layer violation.
        $this->writeFile('routes/Actions/billing.php', <<<'PHP'
<?php

use App\Infrastructure\LegacyBillingClient;

$client = new LegacyBillingClient();
$client->charge();
PHP);
        $this->writeFile('app/Infrastructure/LegacyBillingClient.php', <<<'PHP'
<?php

namespace App\Infrastructure;

final class LegacyBillingClient
{
    public function charge(): void
    {
    }
}
PHP);

        $this->assertSame([], $this->findingsFor($this->audit(), 'layer-dependency'));
    }

    public function test_a_write_to_something_other_than_a_route_model_is_not_reported(): void
    {
        // update() on an arbitrary object is not a model write; only a parameter typed as
        // a model counts, the same way the controller rule decides it.
        $this->writeFile('routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/cache', function ($store) {
    $store->update(['warm' => true]);
});
PHP);

        $this->assertSame([], $this->findingsFor($this->audit(), 'route-logic'));
    }

    private function audit(): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers],
            changedOnly: false,
            scope: new AuditScope(['app', 'routes']),
        );
    }

    /**
     * @return array<int, string>
     */
    private function codes(ApplicationAuditResult $result): array
    {
        return array_values(array_filter(array_map(
            static fn (AuditFinding $finding): ?string => $finding->code,
            $result->findings,
        )));
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function findingsFor(ApplicationAuditResult $result, string $rule): array
    {
        return array_values(array_filter(
            $result->findings,
            static fn (AuditFinding $finding): bool => $finding->rule === $rule,
        ));
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
