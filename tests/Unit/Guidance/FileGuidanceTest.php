<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Guidance;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Guidance\FileGuidance;
use GracjanKubicki\ArchitectureKit\Tests\Fixtures\ForbiddenWorkflowAuditRule;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class FileGuidanceTest extends TestCase
{
    public function test_it_returns_only_the_architecture_that_governs_the_path(): void
    {
        $result = $this->guidance('app/Actions/SendInvoice.php');
        $slugs = array_column($result['architectures'], 'slug');

        $this->assertContains('actions', $slugs);
        $this->assertNotContains('api-resources', $slugs);
        $this->assertNotContains('saloon', $slugs);
        $this->assertNotContains('eloquent-lifecycle', $slugs);
    }

    public function test_a_controller_path_returns_controller_rules_and_not_action_rules(): void
    {
        $result = $this->guidance('app/Http/Controllers/InvoiceController.php');

        $this->assertContains('thin-controller', $result['rules']);
        $this->assertNotContains('actions', $result['rules']);
    }

    public function test_it_works_for_a_file_that_does_not_exist_yet(): void
    {
        // Asking before writing is the whole point, so a missing file must not be an error.
        $this->assertFileDoesNotExist($this->tempPath.'/app/Actions/NotWrittenYet.php');

        $result = $this->guidance('app/Actions/NotWrittenYet.php');

        $this->assertContains('actions', array_column($result['architectures'], 'slug'));
    }

    public function test_advisory_guidance_is_reported_for_every_application_file(): void
    {
        $result = $this->guidance('app/Actions/SendInvoice.php');
        $advisory = array_values(array_filter(
            $result['architectures'],
            fn (array $entry): bool => $entry['enforcement'] === 'advisory',
        ));

        $this->assertNotSame([], $advisory);
        $this->assertContains('laravel-best-practices', array_column($advisory, 'slug'));
    }

    public function test_an_enforced_architecture_reports_the_rules_that_can_fail(): void
    {
        $result = $this->guidance('app/Actions/SendInvoice.php');
        $actions = $this->entry($result['architectures'], 'actions');

        $this->assertSame('enforced', $actions['enforcement']);
        $this->assertContains('actions', $actions['rules']);
        $this->assertSame(['app/Actions'], $actions['placement']);
        $this->assertTrue($actions['governs']);
    }

    public function test_an_architecture_pulled_in_only_by_a_shared_rule_is_not_reported_as_governing(): void
    {
        // Folder purity and the form-request rule also run here, but the file is not a
        // Data Object, so the agent must not be sent to that guidance.
        $result = $this->guidance('app/Actions/SendInvoice.php');
        $dataObjects = $this->entry($result['architectures'], 'data-objects');

        $this->assertFalse($dataObjects['governs']);
        $this->assertTrue($this->entry($result['architectures'], 'actions')['governs']);
    }

    public function test_a_path_outside_the_application_is_reported_as_out_of_scope(): void
    {
        $result = $this->guidance('routes/api.php');

        $this->assertFalse($result['in_scope']);
        $this->assertSame([], $result['architectures']);
        $this->assertSame([], $result['global_rules']);
    }

    public function test_an_absolute_path_inside_the_project_is_normalized(): void
    {
        $result = $this->guidance($this->tempPath.'/app/Actions/SendInvoice.php');

        $this->assertSame('app/Actions/SendInvoice.php', $result['path']);
    }

    public function test_a_path_that_traverses_out_of_the_application_is_reported_as_out_of_scope(): void
    {
        // The audit resolves real paths, so app/../routes/api.php is never an
        // application file. Reporting rules for it would promise enforcement that the
        // audit will not deliver.
        $result = $this->guidance('app/../routes/api.php');

        $this->assertSame('routes/api.php', $result['path']);
        $this->assertFalse($result['in_scope']);
        $this->assertSame([], $result['rules']);
    }

    public function test_a_traversal_that_stays_inside_the_application_is_still_resolved(): void
    {
        $result = $this->guidance('app/Services/../Actions/SendInvoice.php');

        $this->assertSame('app/Actions/SendInvoice.php', $result['path']);
        $this->assertTrue($result['in_scope']);
    }

    public function test_a_rule_registered_by_the_project_is_reported_as_governing_the_file(): void
    {
        // A project rule fails the same gate as a built-in one, so an agent that never
        // sees it writes code that cannot pass.
        $result = $this->guidance(
            'app/Actions/ForbiddenWorkflow.php',
            CustomRuleSet::fromGlobal([new ForbiddenWorkflowAuditRule]),
        );

        $this->assertContains('forbidden-workflow-audit-rule', $result['project_rules']);
        $this->assertContains('forbidden-workflow-audit-rule', $result['rules']);
    }

    public function test_a_project_rule_that_does_not_match_the_path_is_not_reported(): void
    {
        $result = $this->guidance(
            'app/Actions/SendInvoice.php',
            CustomRuleSet::fromGlobal([new ForbiddenWorkflowAuditRule]),
        );

        $this->assertSame([], $result['project_rules']);
        $this->assertNotContains('forbidden-workflow-audit-rule', $result['rules']);
    }

    public function test_folder_purity_is_reported_for_the_folder_the_architecture_owns(): void
    {
        $result = $this->guidance('app/Http/Resources/InvoiceResource.php');
        $apiResources = $this->entry($result['architectures'], 'api-resources');

        $this->assertTrue($apiResources['governs']);
        $this->assertContains('folder-purity', $apiResources['rules']);
        $this->assertContains('folder-purity', $result['rules']);
    }

    public function test_folder_purity_of_another_folder_is_not_attributed_to_the_architecture(): void
    {
        $result = $this->guidance('app/Actions/SendInvoice.php');
        $slugs = array_column($result['architectures'], 'slug');

        // Folder purity fires here, but it is the Actions folder that must stay pure.
        $this->assertContains('folder-purity', $this->entry($result['architectures'], 'actions')['rules']);
        $this->assertNotContains('api-resources', $slugs);
    }

    public function test_project_graph_rules_are_reported_as_global(): void
    {
        // They run over the whole project rather than per file, so no architecture entry
        // can carry them, yet they still fail the gate.
        $result = $this->guidance('app/Actions/SendInvoice.php');

        $this->assertContains('layer-dependency', $result['global_rules']);
        $this->assertContains('namespace-cycle', $result['global_rules']);
    }

    public function test_a_per_file_rule_is_not_claimed_to_be_always_active(): void
    {
        // service-locator and testability only run on a few path shapes, so listing them
        // as always active would tell the agent they apply where they never run.
        $result = $this->guidance('app/Actions/SendInvoice.php');

        $this->assertNotContains('service-locator', $result['global_rules']);
        $this->assertNotContains('testability', $result['global_rules']);
        $this->assertContains('testability', $result['rules']);
    }

    public function test_the_service_locator_rule_of_the_services_architecture_is_reported(): void
    {
        // ServicesRule reports it inside app/Services itself; ServiceLocatorRule does not
        // cover that folder, so deriving the slug from the wrong rule loses it.
        $result = $this->guidance('app/Services/BillingService.php', enabled: [Architecture::Services]);

        $this->assertContains('service-locator', $result['rules']);
        $this->assertContains('service-locator', $this->entry($result['architectures'], 'services')['rules']);
    }

    public function test_folder_purity_is_not_promised_for_a_folder_whose_architecture_is_disabled(): void
    {
        // The rule supports the path but its check is gated on the architecture, so
        // reporting it would promise a finding the audit never emits.
        $result = $this->guidance('app/Services/BillingService.php', enabled: [Architecture::Actions]);

        $this->assertNotContains('folder-purity', $result['rules']);
    }

    public function test_folder_purity_is_promised_once_the_architecture_is_enabled(): void
    {
        $result = $this->guidance('app/Services/BillingService.php', enabled: [Architecture::Services]);

        $this->assertContains('folder-purity', $result['rules']);
    }

    public function test_a_widened_scope_makes_a_route_file_governed(): void
    {
        // Reporting "no rules here" for a path the audit now reads would recreate the
        // blind spot the scope was widened to close.
        $result = $this->guidance('routes/api.php', scope: new AuditScope(['app', 'routes']));

        $this->assertTrue($result['in_scope']);
        $this->assertContains('route-logic', $result['rules']);
    }

    public function test_a_route_file_is_out_of_scope_by_default(): void
    {
        $result = $this->guidance('routes/api.php');

        $this->assertFalse($result['in_scope']);
        $this->assertSame([], $result['rules']);
    }

    public function test_a_test_file_is_never_reported_as_governed(): void
    {
        // It is read by the audit once the missing-test rule is on, but no rule written
        // for application code applies inside it.
        $result = $this->guidance('tests/Feature/InvoiceTest.php', scope: AuditScope::default()->withTests());

        $this->assertFalse($result['in_scope']);
        $this->assertSame([], $result['rules']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $architectures
     * @return array<string, mixed>
     */
    private function entry(array $architectures, string $slug): array
    {
        foreach ($architectures as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        $this->fail("Architecture [{$slug}] was not returned for the path.");
    }

    /**
     * @param  array<int, Architecture>|null  $enabled
     * @return array{path: string, in_scope: bool, architectures: array<int, array<string, mixed>>, rules: array<int, string>, project_rules: array<int, string>, global_rules: array<int, string>}
     */
    private function guidance(string $path, ?CustomRuleSet $customRules = null, ?array $enabled = null, ?AuditScope $scope = null): array
    {
        $files = new Filesystem;

        return (new FileGuidance($files, $this->tempPath, new ArchitectureCatalog($files, $this->tempPath), $scope ?? AuditScope::default()))
            ->for($path, $enabled ?? Architecture::defaultSelection(), $customRules ?? new CustomRuleSet);
    }
}
