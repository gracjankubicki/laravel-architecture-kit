<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

/**
 * The overriding promise of this change: a project that upgrades the package without
 * touching its configuration gets exactly the audit it had before. Widening the scope is
 * worthless if it costs every existing installation a new wall of findings.
 */
final class UnchangedConfigRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([
            Architecture::ThinControllers,
            Architecture::Actions,
        ]);

        $this->writeFile('app/Actions/SendInvoice.php', <<<'PHP'
<?php

namespace App\Actions;

final readonly class SendInvoice
{
    public function handle(): void
    {
    }
}
PHP);

        // A controller that already fails the audit before this change. The point of the
        // regression is that its findings come out exactly as they did: an existing
        // finding must not disappear, gain a line, or acquire a neighbour.
        $this->writeFile('app/Http/Controllers/InvoiceController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

final class InvoiceController
{
    public function store(Request $request, Invoice $invoice): void
    {
        $request->validate(['total' => 'required']);

        $invoice->update(['total' => 1]);
    }
}
PHP);

        // Would be reported if routes/ entered the scope on its own.
        $this->writeFile('routes/web.php', <<<'PHP'
<?php

use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', function () {
    Invoice::create(['total' => 1]);
});
Route::post('/invoices', [\App\Http\Controllers\InvoiceController::class, 'store']);
PHP);
        $this->withRoutes(file_get_contents($this->tempPath.'/routes/web.php'));

        // Would be reported if the missing-test rule turned itself on.
        $this->writeFile('tests/Feature/PlaceholderTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PlaceholderTest extends TestCase
{
    public function test_it_runs(): void
    {
        $this->assertTrue(true);
    }
}
PHP);
    }

    public function test_the_scope_stays_at_the_application_directory(): void
    {
        $state = $this->state();

        $this->assertSame(['app'], $state->auditScope->directories);
        $this->assertFalse($state->missingTestLevel->isEnabled());
    }

    /**
     * The literal form of the promise: the complete finding list, not a filtered view of
     * it. A new warning anywhere, a lost finding, or a shifted line all fail here.
     */
    public function test_the_complete_finding_list_is_exactly_what_it_was(): void
    {
        $result = $this->audit();

        $this->assertSame([
            'error thin-controller app/Http/Controllers/InvoiceController.php:12 E_THIN_CONTROLLER_INLINE_VALIDATION',
            'error thin-controller app/Http/Controllers/InvoiceController.php:14 E_THIN_CONTROLLER_MODEL_WRITE',
        ], $this->normalize($result->findings));

        $this->assertSame('all application files', $result->scope);
    }

    public function test_widening_the_scope_is_what_changes_the_result_and_nothing_else(): void
    {
        // Proves the previous assertion is not green because the audit went quiet: the
        // same tree, audited with the scope a project would have to opt into, does report
        // the route file and the missing tests.
        $widened = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            $this->state()->enabled,
            changedOnly: false,
            scope: new AuditScope(['app', 'routes']),
            missingTestLevel: MissingTestLevel::Warn,
        );

        $rules = array_map(static fn (AuditFinding $finding): string => $finding->rule, $widened->findings);

        $this->assertContains('route-logic', $rules);
        $this->assertContains('missing-test', $rules);
    }

    public function test_the_audit_command_reports_the_application_scope_and_the_same_error_count(): void
    {
        $exitCode = Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        // The fixture controller already failed before this change, so the exit code and
        // the counters have to stay exactly what they were, not become green.
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(2, $payload['err']);
        $this->assertSame(0, $payload['warn']);
        // The agent payload normalizes the scope label to `all` or `changed`.
        $this->assertSame('all', $payload['scope']);
    }

    private function state(): ProjectState
    {
        return ProjectState::load(new Filesystem, dirname(__DIR__, 2), $this->tempPath);
    }

    private function audit(): ApplicationAuditResult
    {
        $state = $this->state();

        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            $state->enabled,
            changedOnly: false,
            scope: $state->auditScope,
            missingTestLevel: $state->missingTestLevel,
        );
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     * @return array<int, string>
     */
    private function normalize(array $findings): array
    {
        return array_map(
            static fn (AuditFinding $finding): string => sprintf(
                '%s %s %s:%d %s',
                $finding->severity,
                $finding->rule,
                $finding->path,
                $finding->line,
                $finding->code ?? '',
            ),
            $findings,
        );
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
