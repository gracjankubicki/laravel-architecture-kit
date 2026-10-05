<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Guidance\FileGuidance;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class ProjectClassificationTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$source);
    }

    public function test_graph_shared_classification_namespace_override_cache_and_scope(): void
    {
        $this->write('app/Billing/UseCases/Pay.php', 'namespace App\Billing\UseCases; class Pay {}');
        $this->write('other/Excluded.php', 'namespace Other; class Excluded {}');
        $config = 'return ["audit"=>["classification"=>["roles"=>[["namespace"=>"App\\\\Billing\\\\UseCases", "role"=>"application", "kind"=>"action"]], "modules"=>[["name"=>"Billing", "path"=>"app/Billing"]]]]];';
        $this->write('config/architectures.php', $config);
        $files = new Filesystem;
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $loader = new ProjectGraphLoader($files, $this->tempPath, new AuditScope, $cache);
        $graph = $loader->load();
        $this->assertSame('application', $graph->symbol('App\Billing\UseCases\Pay')->role);
        $this->assertNull($graph->symbol('Other\Excluded'));
        $description = $loader->classification->describe('app/Billing/UseCases/Pay.php', 'App\Billing\UseCases\Pay', 'class');
        $this->assertSame('action', $description['application_kind']);
        $this->assertSame('class', $description['php_kind']);
        $this->assertSame('Billing', $description['module']);
        $this->assertStringContainsString('namespace:', $description['provenance']['classification']);
        $this->assertSame('fresh', (new ProjectGraphLoader($files, $this->tempPath, new AuditScope, $cache))->plan()->cacheStatus->value);
        $this->write('config/architectures.php', str_replace('"application"', '"domain"', $config));
        $changed = new ProjectGraphLoader($files, $this->tempPath, new AuditScope, $cache);
        $this->assertNotSame('fresh', $changed->plan()->cacheStatus->value);
        $this->assertSame('domain', $changed->load()->symbol('App\Billing\UseCases\Pay')->role);
    }

    public function test_declared_actions_and_queries_run_enabled_rules_without_relocation(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"app/Billing/UseCases", "kind"=>"action"], ["namespace"=>"App\\\\Billing\\\\Readers", "kind"=>"query"]]]]];');
        $this->write('app/Billing/UseCases/Pay.php', 'namespace App\\Billing\\UseCases; use Illuminate\\Http\\Request; class Pay { public function handle(Request $request) {} }');
        $this->write('app/Billing/Readers/Invoices.php', 'namespace App\\Billing\\Readers; class Invoices { public function handle() { \\Illuminate\\Support\\Facades\\DB::table("invoices")->update(["id"=>1]); } }');
        $audit = new ApplicationAudit(new Filesystem, $this->tempPath);
        $result = $audit->run([Architecture::Actions, Architecture::QueryObjects], false, useBaseline: false);
        $this->assertContains('actions', array_map(static fn ($f) => $f->rule, $result->findings));
        $this->assertContains('query-objects', array_map(static fn ($f) => $f->rule, $result->findings));
        $disabled = $audit->run([Architecture::Enums], false, useBaseline: false);
        $this->assertNotContains('actions', array_map(static fn ($f) => $f->rule, $disabled->findings));
        $this->assertNotContains('query-objects', array_map(static fn ($f) => $f->rule, $disabled->findings));
    }

    public function test_test_and_classless_invariants_and_default_models(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"tests", "role"=>"domain", "kind"=>"action"], ["path"=>"routes", "role"=>"application"]]]]];');
        $roles = RoleClassifier::forProject(new Filesystem, $this->tempPath);
        $test = $roles->describe('tests/EntryTest.php', 'Tests\EntryTest', 'class');
        $this->assertSame('test', $test['role']);
        $this->assertSame('test', $test['application_kind']);
        $classless = $roles->describe('routes/Entries.php', '(file) routes/Entries.php', 'file');
        $this->assertSame('unknown', $classless['role']);
        $this->assertNull($classless['application_kind']);
        $model = $roles->describe('app/Models/User.php', 'App\Models\User', 'class');
        $this->assertSame('domain', $model['role']);
        $this->assertSame('model', $model['application_kind']);
        $this->assertNull($model['module']);
    }

    public function test_unknown_layer_levels_do_not_report_missing_kind_or_tests(): void
    {
        $this->write('app/Misc/Thing.php', 'namespace App\\Misc; class Thing {}');
        $this->write('app/Domain/Entity.php', 'namespace App\\Domain; class Entity {}');
        $this->write('tests/ThingTest.php', 'namespace Tests; class ThingTest {}');
        foreach (['off', 'warn', 'error'] as $level) {
            $this->write('config/architectures.php', 'return '.var_export(['audit' => ['classification' => ['unknown_role' => $level]]], true).';');
            $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::Enums], false, useBaseline: false, scope: new AuditScope(['app', 'tests']));
            $unknown = array_values(array_filter($result->findings, static fn ($f) => $f->rule === 'unknown-role'));
            $this->assertCount($level === 'off' ? 0 : 1, $unknown);
            if ($unknown !== []) {
                $this->assertSame($level, $unknown[0]->severity);
                $this->assertSame('app/Misc/Thing.php', $unknown[0]->path);
            }
            if ($level !== 'off') {
                $this->assertContains('unknown', array_column($result->classification['symbols'], 'role'));
            }
        }
    }

    public function test_shared_classification_across_audit_search_context_impact_and_file_guidance(): void
    {
        $config = ['enabled' => [Architecture::Actions->value, Architecture::QueryObjects->value], 'audit' => ['classification' => ['roles' => [['path' => 'app/Billing/UseCases', 'role' => 'application', 'kind' => 'action']], 'modules' => [['name' => 'Billing', 'path' => 'app/Billing'], ['name' => 'Refunds', 'path' => 'app/Billing/Refunds']]]]];
        $this->write('config/architectures.php', 'return '.var_export($config, true).';');
        $this->write('app/Billing/UseCases/Pay.php', 'namespace App\\Billing\\UseCases; class Pay { public function handle(\\App\\Billing\\Refunds\\Refund $refund, \\App\\Billing\\Ledger $ledger, \\App\\Models\\User $user) {} }');
        $this->write('app/Billing/Refunds/Refund.php', 'namespace App\\Billing\\Refunds; class Refund {}');
        $this->write('app/Billing/Ledger.php', 'namespace App\\Billing; class Ledger {}');
        $this->write('app/Models/User.php', 'namespace App\\Models; class User {}');
        $files = new Filesystem;
        $enabled = [Architecture::Actions, Architecture::QueryObjects];
        $audit = (new ApplicationAudit($files, $this->tempPath))->run($enabled, false, useBaseline: false);
        $context = (new ArchitectureContext($files, $this->tempPath))->inspect('App\\Billing\\UseCases\\Pay', $enabled);
        $impact = (new ArchitectureImpact($files, $this->tempPath))->inspect('App\\Billing\\UseCases\\Pay');
        $search = (new ArchitectureDiscovery($files, $this->tempPath))->search('Pay', 'action');
        $this->assertTrue($search['ok']);
        $guidance = (new FileGuidance($files, $this->tempPath, new ArchitectureCatalog($files, $this->tempPath)))->for('app/Billing/UseCases/Pay.php', $enabled, new CustomRuleSet);
        foreach ([array_values(array_filter($audit->classification['symbols'], static fn ($row) => $row['name'] === 'App\\Billing\\UseCases\\Pay'))[0], $context->classification['symbols'][0], array_values(array_filter($impact['classification']['symbols'], static fn ($row) => $row['name'] === 'App\\Billing\\UseCases\\Pay'))[0], $search['candidates'][0]['classification'], $guidance['classification'][0]] as $row) {
            $this->assertSame('application', $row['role']);
            $this->assertSame('action', $row['application_kind']);
            $this->assertSame('class', $row['php_kind']);
            $this->assertSame('Billing', $row['module']);
            $this->assertSame('path:app/Billing/UseCases', $row['provenance']['classification']);
        }
        $this->assertEqualsCanonicalizing(['inter_module', 'intra_module', 'unassigned'], array_unique(array_column($context->classification['module_relations'], 'relation')));
        $actions = array_values(array_filter($guidance['architectures'], static fn ($a) => $a['slug'] === 'actions'))[0];
        $this->assertTrue($actions['governs']);
        $this->assertContains('app/Billing/UseCases', $actions['placement']);
        $this->assertContains('actions', $actions['rules']);
    }

    public function test_custom_kind_overrides_standard_directory_rule_and_placement(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"app/Actions", "kind"=>"query"]]]]];');
        $this->write('app/Actions/Read.php', 'namespace App\\Actions; class Read { public function handle() { \\Illuminate\\Support\\Facades\\DB::table("rows")->update(["id"=>1]); } }');
        $files = new Filesystem;
        $enabled = [Architecture::Actions, Architecture::QueryObjects];
        $result = (new ApplicationAudit($files, $this->tempPath))->run($enabled, false, useBaseline: false);
        $rules = array_column($result->findings, 'rule');
        $this->assertContains('query-objects', $rules);
        $this->assertNotContains('actions', $rules);
        $guidance = (new FileGuidance($files, $this->tempPath, new ArchitectureCatalog($files, $this->tempPath)))->for('app/Actions/Read.php', $enabled, new CustomRuleSet);
        foreach ($guidance['architectures'] as $a) {
            if ($a['slug'] === 'actions') {
                $this->assertFalse($a['governs']);
                $this->assertNotContains('actions', $a['rules']);
            }
        }
    }

    public function test_declared_configuration_does_not_execute_php_or_load_project_constants(): void
    {
        $this->write('config/architectures.php', 'use GracjanKubicki\\ArchitectureKit\\Architecture as Patterns; return ["enabled" => [Patterns::Actions], "audit" => ["classification" => []]];');
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php');
        $this->assertSame([Architecture::Actions], $config->read());
        foreach (['file_put_contents(__DIR__."/executed", "bad"); return ["audit"=>["classification"=>[]]];', 'return ["enabled"=>[ProjectConstants::BAD], "audit"=>["classification"=>[]]];', 'return ["enabled"=>[file_put_contents(__DIR__."/executed", "bad")], "audit"=>["classification"=>[]]];'] as $source) {
            $this->write('config/architectures.php', $source);
            $rejected = false;
            try {
                (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->read();
            } catch (\Throwable $e) {
                $rejected = true;
                $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
                $this->assertNotSame('', $e->getMessage());
            }
            $this->assertTrue($rejected, 'Dynamic declared configuration must fail.');
        }
    }

    public function test_default_declarations_preserve_existing_audit_findings(): void
    {
        $this->write('app/Actions/Bad.php', 'namespace App\\Actions; class Bad { public function handle(\\Illuminate\\Http\\Request $request) {} }');
        $this->write('app/Queries/BadRead.php', 'namespace App\\Queries; class BadRead { public function handle() { \\Illuminate\\Support\\Facades\\DB::table("rows")->update(["id" => 1]); } }');
        $audit = new ApplicationAudit(new Filesystem, $this->tempPath);
        $enabled = [Architecture::Actions, Architecture::QueryObjects];
        $before = $audit->run($enabled, false, useBaseline: false)->findings;
        $this->assertNotEmpty($before);
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>[]]];');
        $this->assertEquals($before, $audit->run($enabled, false, useBaseline: false)->findings);
    }

    public function test_nested_kind_and_source_declaration_not_enable_a_profile(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"app/Billing/Payloads", "kind"=>"data"], ["path"=>"app/Billing/Requests", "kind"=>"request"]]]]];');
        $this->write('app/Billing/Payloads/InvoiceData.php', 'namespace App\\Billing\\Payloads; class InvoiceData { public function setName(string $name) {} }');
        $files = new Filesystem;
        $audit = new ApplicationAudit($files, $this->tempPath);
        $enabled = $audit->run([Architecture::DataObjects], false, useBaseline: false)->findings;
        $disabled = $audit->run([Architecture::Actions], false, useBaseline: false)->findings;
        $this->assertContains('data-objects', array_column($enabled, 'rule'));
        $this->assertNotContains('data-objects', array_column($disabled, 'rule'));
        $roles = RoleClassifier::forProject($files, $this->tempPath);
        $this->assertSame('builder', $roles->describe('app/Models/Builders/InvoiceBuilder.php', 'App\\Models\\Builders\\InvoiceBuilder', 'class')['application_kind']);
    }

    public function test_namespace_kind_rules_apply_to_the_matching_declaration_in_a_mixed_file(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["namespace"=>"App\\\\Billing\\\\UseCases", "kind"=>"action"], ["namespace"=>"App\\\\Billing\\\\Domain", "role"=>"domain", "kind"=>"model"]]]]];');
        $this->write('app/Billing/Mixed.php', 'namespace App\\Billing\\Domain { class Entity {} } namespace App\\Billing\\UseCases { class Pay { public function handle(\\Illuminate\\Http\\Request $request) {} } }');
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::Actions], false, useBaseline: false);
        $this->assertContains('actions', array_column($result->findings, 'rule'));
        $this->assertNotContains('folder-purity', array_column($result->findings, 'rule'));
    }

    public function test_declared_controller_audit_does_not_boot_the_application(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"app/Billing/Http", "kind"=>"controller", "role"=>"adapter"]]]]];');
        $this->write('app/Billing/Http/InvoiceController.php', 'namespace App\\Billing\\Http; class InvoiceController { public function index() {} }');
        $this->write('bootstrap/app.php', 'file_put_contents(__DIR__."/executed", "bad"); throw new \\RuntimeException("Do not boot");');
        $this->write('vendor/autoload.php', 'file_put_contents(__DIR__."/executed", "bad");');
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::ThinControllers], false, useBaseline: false);
        $this->assertFileDoesNotExist($this->tempPath.'/bootstrap/executed');
        $this->assertFileDoesNotExist($this->tempPath.'/vendor/executed');
        $this->assertSame('incomplete', $result->analysisStatus);
        $this->assertStringContainsString('does not boot the application', $result->notices[0]->message);
    }

    public function test_mapped_sibling_does_not_disable_a_conventional_action_in_the_same_file(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["namespace"=>"App\\\\Readers", "kind"=>"query"]]]]];');
        $this->write('app/Actions/Mixed.php', 'namespace App\\Actions { class Pay { public function handle(\\Illuminate\\Http\\Request $request) {} } } namespace App\\Readers { class Read { public function handle() { \\Illuminate\\Support\\Facades\\DB::table("rows")->update(["id"=>1]); } } }');
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::Actions, Architecture::QueryObjects], false, useBaseline: false);
        $this->assertContains('actions', array_column($result->findings, 'rule'));
        $this->assertContains('query-objects', array_column($result->findings, 'rule'));
    }

    public function test_declaration_views_preserve_only_their_namespace_imports(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["namespace"=>"App\\\\UseCases", "kind"=>"action"], ["namespace"=>"App\\\\Domain", "kind"=>"model"]]]]];');
        $this->write('app/Mixed.php', 'namespace App\\Domain { use Illuminate\\Http\\Response; class Entity {} } namespace App\\UseCases { use Illuminate\\Http\\Request; class Pay { private Request $request; public function handle() {} } }');
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::Actions], false, useBaseline: false);
        $actions = array_values(array_filter($result->findings, static fn ($f) => $f->rule === 'actions'));
        $this->assertCount(1, $actions);
        $this->assertStringContainsString('HTTP request or response', $actions[0]->message);
    }

    public function test_file_selectors_retain_classification_in_reach_delete_and_move(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"app/Billing/UseCases", "kind"=>"action", "role"=>"application"]], "modules"=>[["name"=>"Billing", "path"=>"app/Billing"]]]]];');
        $path = 'app/Billing/UseCases/Pay.php';
        $this->write($path, 'namespace App\\Billing\\UseCases; class Pay { public function handle() {} }');
        (new Filesystem)->put($this->tempPath.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        $files = new Filesystem;
        $impact = new ArchitectureImpact($files, $this->tempPath);
        $reports = [
            (new ArchitectureReach($files, $this->tempPath))->inspect($path),
            $impact->inspect($path, change: 'delete'),
            $impact->inspect($path, change: 'move', targetPath: 'app/Billing/UseCases/PayRenamed.php'),
        ];
        foreach ($reports as $report) {
            $this->assertTrue($report['ok']);
            $this->assertCount(1, $report['classification']['symbols']);
            $row = $report['classification']['symbols'][0];
            $this->assertSame('App\\Billing\\UseCases\\Pay', $row['name']);
            $this->assertSame('application', $row['role']);
            $this->assertSame('action', $row['application_kind']);
            $this->assertSame('Billing', $row['module']);
        }
    }

    public function test_placement_removes_a_default_directory_assigned_another_kind(): void
    {
        $this->write('config/architectures.php', 'return ["audit"=>["classification"=>["roles"=>[["path"=>"app/Actions", "kind"=>"query"], ["path"=>"app/Billing/UseCases", "kind"=>"action"]]]]];');
        $files = new Filesystem;
        $guidance = (new FileGuidance($files, $this->tempPath, new ArchitectureCatalog($files, $this->tempPath)))->for('app/Billing/UseCases/Pay.php', [Architecture::Actions, Architecture::QueryObjects], new CustomRuleSet);
        $actions = array_values(array_filter($guidance['architectures'], static fn ($a) => $a['slug'] === 'actions'))[0];
        $this->assertContains('app/Billing/UseCases', $actions['placement']);
        $this->assertNotContains('app/Actions', $actions['placement']);
    }

    public function test_oversized_parenthesized_declarations_are_rejected_without_execution(): void
    {
        foreach ([
            'return (["audit" => ["classification" => []]]);',
            'return ["audit" => (["classification" => []])];',
            'return ((array("audit" => (array("classification" => [])))));',
            'return [("audit") => [("classification") => []]];',
        ] as $declaration) {
            $this->assertNotNull((new FileContext('(fixture)', '<?php '.$declaration))->ast());
            $source = 'file_put_contents(__DIR__."/executed", "bad"); '.$declaration.' // '.str_repeat('x', 110000);
            $this->write('config/architectures.php', $source);
            $this->assertTrue(DeclaredConfiguration::hasDeclarationKey('<?php '.$source));
            foreach ([
                fn () => (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->read(),
                fn () => ClassificationMappings::load(new Filesystem, $this->tempPath),
            ] as $read) {
                $rejected = false;
                try {
                    $read();
                } catch (\InvalidArgumentException) {
                    $rejected = true;
                }
                $this->assertTrue($rejected);
                $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
            }
        }
    }

    public function test_oversized_document_string_and_binary_literal_keys_do_not_execute_configuration(): void
    {
        $declarations = [
            "return ['audit' => [<<<'KEY'\nclassification\nKEY\n => []]];",
            "return [<<<'KEY'\naudit\nKEY\n => ['classification' => []]];",
            "return [<<<KEY\naudit\nKEY\n => [<<<KEY\nclassification\nKEY\n => []]];",
            'return [b"audit" => [B"classification" => []]];',
            'return ["audit" => ["\u{63}lassification" => []]];',
            'return ["\u{61}udit" => ["classification" => []]];',
            'return [b"\u{61}udit" => [B"\u{63}lassification" => []]];',
        ];
        foreach ($declarations as $declaration) {
            $this->assertNotNull((new FileContext('(fixture)', '<?php '.$declaration))->ast());
            $source = 'file_put_contents(__DIR__."/executed", "bad"); '.$declaration.' // '.str_repeat('x', 110000);
            $this->write('config/architectures.php', $source);
            $this->assertTrue(DeclaredConfiguration::hasDeclarationKey('<?php '.$source));
            foreach ([
                fn () => (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->read(),
                fn () => ClassificationMappings::load(new Filesystem, $this->tempPath),
            ] as $read) {
                $rejected = false;
                try {
                    $read();
                } catch (\InvalidArgumentException) {
                    $rejected = true;
                }
                $this->assertTrue($rejected);
                $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
            }
        }
    }

    public function test_large_legacy_configuration_is_preserved_while_large_declarations_are_bounded(): void
    {
        $source = 'return '.var_export(['enabled' => ['actions'], 'audit' => ['exclude' => [str_repeat('x', 110000)]], 'metadata' => ['classification' => []]], true).'; // classification';
        $this->write('config/architectures.php', $source);
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php');
        $this->assertSame([Architecture::Actions], $config->read());
        $this->assertSame([str_repeat('x', 110000)], $config->auditExcludes());
        $this->assertSame([], ClassificationMappings::load(new Filesystem, $this->tempPath)->roles);
        $this->write('app/Actions/Pay.php', 'namespace App\\Actions; class Pay { public function handle() {} }');
        $this->assertNotNull((new ProjectGraphLoader(new Filesystem, $this->tempPath))->load()->symbol('App\\Actions\\Pay'));
        foreach (['return ["audit"=>["classification"=>[]]];', 'return array("audit"=>array("classification"=>array()));'] as $declared) {
            $this->write('config/architectures.php', $declared.' // '.str_repeat('x', 110000));
            $rejected = false;
            try {
                (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->read();
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
    }
}
