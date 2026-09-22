<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerAnalysisResult;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class Gh17AuditPolicyTest extends TestCase
{
    #[DataProvider('readVerbs')]
    public function test_get_and_head_do_not_create_a_findings_level_violation(array $verbs): void
    {
        $this->writeFixture();

        $result = $this->audit([], $verbs);

        $this->assertSame(0, $result->errors());
        $this->assertSame(0, $result->warnings());
        $this->assertSame(ControllerAnalysisResult::COMPLETE, $result->analysisStatus);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame('S_MOVE_WRITE_TO_ACTION', $result->suggestions[0]->code);
        $this->assertSame('actions', $result->suggestions[0]->architecture);
        $this->assertFalse($result->suggestions[0]->enabled);
        $this->assertSame([], $result->notices);
    }

    public static function readVerbs(): iterable
    {
        yield 'GET' => [['GET']];
        yield 'HEAD' => [['HEAD']];
        yield 'GET and HEAD' => [['GET', 'HEAD']];
    }

    public function test_correct_action_boundary_gets_no_redundant_suggestion(): void
    {
        $this->writeFixture();
        $this->write('app/Actions/UpdateInvoice.php', <<<'PHP'
<?php
namespace App\Actions;
use App\Models\Invoice;
final class UpdateInvoice {
    public function handle(Invoice $invoice): void { $invoice->save(); }
}
PHP);
        $this->writeController('return (new \App\Actions\UpdateInvoice)->handle($invoice);');

        $result = $this->audit([Architecture::ThinControllers, Architecture::Actions]);

        $this->assertSame([], $result->findings);
        $this->assertSame([], $result->suggestions);
        $this->assertSame([], $result->notices);
        $this->assertSame(ControllerAnalysisResult::COMPLETE, $result->analysisStatus);
    }

    public function test_existing_direct_model_write_rule_still_blocks_and_suppresses_duplicate_advice(): void
    {
        $this->writeFixture();
        $this->writeController('$invoice->update(["total" => 1]);');

        $result = $this->audit([Architecture::ThinControllers, Architecture::Actions], ['GET']);

        $this->assertSame(1, $result->errors());
        $this->assertSame('E_THIN_CONTROLLER_MODEL_WRITE', $result->findings[0]->code);
        $this->assertSame([], $result->suggestions);
    }

    public function test_disabled_profiles_still_receive_non_blocking_placement_advice(): void
    {
        $this->writeFixture();

        $result = $this->audit([], ['POST']);

        $this->assertSame([], $result->findings);
        $this->assertCount(1, $result->suggestions);
        $this->assertFalse($result->suggestions[0]->enabled);
    }

    public function test_query_object_violation_remains_an_enforced_warning(): void
    {
        $this->writeFixture();
        $this->write('app/Http/Controllers/PlanningController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
final class PlanningController {
    public function show() { return $this->loadInvoices("open"); }
    private function loadInvoices(string $status) {
        return Invoice::query()->where("status", $status)->orderBy("id")->get();
    }
}
PHP);

        $result = $this->audit([
            Architecture::ThinControllers,
            Architecture::Actions,
            Architecture::QueryObjects,
        ]);

        $this->assertContains('query-objects', array_column($result->findings, 'rule'));
        $this->assertSame([], $result->suggestions);
    }

    public function test_same_method_keeps_distinct_route_contexts_but_deduplicates_case_variants(): void
    {
        $this->writeFixture();
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET'], 'projects', name: 'projects.index', class: 'App\\Http\\Controllers\\PlanningController', method: 'show', middleware: ['web']),
            new RouteEntry(['GET'], 'admin/projects', name: 'admin.projects.index', class: 'App\\Http\\Controllers\\PlanningController', method: 'show', middleware: ['auth:admin']),
            new RouteEntry(['GET'], 'projects', name: 'projects.index', class: 'App\\Http\\Controllers\\PlanningController', method: 'SHOW', middleware: ['web']),
            new RouteEntry(['GET'], 'projects/other', name: 'projects.index', class: 'App\\Http\\Controllers\\PlanningController', method: 'show', middleware: ['web']),
        ]);

        $result = $this->audit([], ['GET'], $routes);

        $this->assertCount(3, $result->suggestions);
        $routeIds = array_map(fn ($suggestion): string => $suggestion->route['id'], $result->suggestions);
        $this->assertCount(3, array_unique($routeIds));
    }

    /** @param array<int, Architecture|string> $enabled
     * @param  list<string>  $verbs
     */
    private function audit(array $enabled, array $verbs = ['GET', 'HEAD'], ?RouteMap $routes = null): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            $enabled,
            changedOnly: false,
            routes: $routes ?? new RouteMap(['app\http\controllers\planningcontroller::show' => $verbs]),
        );
    }

    private function writeFixture(): void
    {
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->writeController('$invoice->save();');
    }

    private function writeController(string $body): void
    {
        $this->write('app/Http/Controllers/PlanningController.php', <<<PHP
<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
final class PlanningController {
    public function show(Invoice \$invoice) { {$body} }
}
PHP);
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
