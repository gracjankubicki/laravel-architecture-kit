<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\Baseline;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Target;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Target\ArchitectureTarget;
use GracjanKubicki\ArchitectureKit\Target\FrozenFilesystem;
use GracjanKubicki\ArchitectureKit\Target\MutatingSourceReader;
use GracjanKubicki\ArchitectureKit\Target\TargetSchema;
use GracjanKubicki\ArchitectureKit\Target\TargetSources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ArchitectureTargetTest extends TestCase
{
    public function test_absent_target_is_inert_and_does_not_read_project_configuration(): void
    {
        $this->write('config/architectures.php', '<?php file_put_contents(__DIR__."/executed", "bad"); return env("UNKNOWN");');
        $report = $this->report();
        $this->assertTrue($report['ok']);
        $this->assertFalse($report['configured']);
        $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
    }

    public function test_current_and_desired_placement_future_file_and_models_without_git(): void
    {
        $this->fixture();
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; class Invoice {}');
        $report = $this->report('App\\Actions\\CreateInvoice::handle');
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertTrue($report['analysis']['fresh']);
        $this->assertSame('migration', $report['subject']['status']);
        $this->assertSame('app/Actions/CreateInvoice.php', $report['subject']['path']);
        $this->assertSame(['app/Billing/Actions'], $report['subject']['expected']['paths']);
        $this->assertSame('conformant', $this->report('App\\Models\\Invoice')['subject']['status']);
        $future = $this->report('app/Billing/Actions/NewInvoice.php');
        $this->assertSame('future_file', $future['subject']['status']);
        $this->assertSame('application', $future['subject']['expected']['role']);
        $this->assertDirectoryDoesNotExist($this->tempPath.'/.git');
        $this->assertDirectoryDoesNotExist($this->tempPath.'/storage');
    }

    public function test_reference_must_be_accepted_and_new_only_does_not_write_it(): void
    {
        $this->fixture(['only' => 'new', 'mode' => 'block']);
        $this->assertSame('E_TARGET_REFERENCE', $this->report()['m']);
        $this->target();
        $candidate = $this->report()['reference_candidate'];
        $this->assertFalse($candidate['accepted']);
        $this->write('.architecture-kit/reference.json', json_encode($candidate, JSON_THROW_ON_ERROR));
        $this->target(['only' => 'new', 'reference' => '.architecture-kit/reference.json']);
        $before = file_get_contents($this->tempPath.'/.architecture-kit/reference.json');
        $this->assertFalse($this->report()['ok']);
        $this->assertSame($before, file_get_contents($this->tempPath.'/.architecture-kit/reference.json'));
        $this->assertFileDoesNotExist($this->tempPath.'/.architecture-kit/baseline.json');
    }

    public function test_migration_progress_tracks_code_move_and_new_violations(): void
    {
        $this->fixture();
        $this->accept();
        $this->target(['mode' => 'block', 'only' => 'new', 'reference' => '.architecture-kit/reference.json']);
        $this->assertTrue($this->report()['gate']['ok']);
        $contents = file_get_contents($this->tempPath.'/app/Actions/CreateInvoice.php');
        unlink($this->tempPath.'/app/Actions/CreateInvoice.php');
        $this->write('app/Billing/Actions/CreateInvoice.php', $contents);
        $moved = $this->report();
        $this->assertTrue($moved['ok'], json_encode($moved));
        $this->assertSame(1, $moved['progress']['resolved_issues']);
        $this->assertTrue($moved['progress']['transitions'][0]['code_improvement']);
        $this->write('app/Actions/Other.php', '<?php namespace App\Actions; class Other { public function handle() {} }');
        $new = $this->report();
        $this->assertSame(1, $new['gate']['issues']);
        $this->assertFalse($new['gate']['ok']);
    }

    public function test_scope_version_target_or_current_mapping_changes_do_not_claim_progress(): void
    {
        $this->fixture();
        $this->accept();
        foreach ([['version' => '2'], ['paths' => ['app/Billing']], ['placements' => []]] as $change) {
            $this->target([...$change, 'reference' => '.architecture-kit/reference.json']);
            $report = $this->report();
            $this->assertFalse($report['progress']['comparable']);
            $this->assertNull($report['progress']['resolved_issues']);
            $this->assertSame([], array_filter($report['progress']['transitions'], fn ($row) => $row['code_improvement']));
        }
        $this->target(['reference' => '.architecture-kit/reference.json']);
        $this->write('config/architectures.php', '<?php return ["enabled"=>[], "audit"=>["exclude"=>["app/Actions/*"]]];');
        $report = $this->report();
        $this->assertFalse($report['progress']['comparable']);
        $this->assertSame(1, $report['totals']['migration']);
        $this->target(['only' => 'new', 'reference' => '.architecture-kit/reference.json']);
        $this->assertSame('E_TARGET_REFERENCE', $this->report()['m']);
    }

    public function test_incomplete_analysis_preserves_known_facts_and_never_resolves_reference_issues(): void
    {
        $this->fixture();
        $this->accept();
        $this->target(['reference' => '.architecture-kit/reference.json']);
        $this->write('app/Broken.php', '<?php class Broken {');
        $report = $this->report();
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertFalse($report['analysis']['structure_complete']);
        $this->assertFalse($report['reference_candidate']['complete']);
        $this->assertNull($report['progress']['resolved_issues']);
        $this->assertSame(1, $report['totals']['migration']);
        $this->accept($report['reference_candidate']);
        $this->assertFalse($this->report()['ok']);
    }

    public function test_dynamic_configuration_is_not_executed_or_inferred_as_known_classification(): void
    {
        $this->fixture(['classification' => ['roles' => [['path' => 'app/Actions', 'role' => 'domain', 'kind' => 'action']]], 'placements' => []]);
        $this->write('config/architectures.php', '<?php file_put_contents(__DIR__."/executed", "bad"); return env("ARCHITECTURES");');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertSame('requires_check', $report['subject']['status']);
        $this->assertFalse($report['audit']['complete']);
        $this->assertSame('possible', $report['subject']['issues'][0]['certainty']);
        $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
    }

    public function test_target_scope_is_independent_of_current_audit_scope_and_exclusion(): void
    {
        $this->fixture(['paths' => ['modules'], 'placements' => [], 'classification' => ['roles' => [['path' => 'modules', 'role' => 'application', 'kind' => 'action']]]]);
        $this->write('modules/Billing/Create.php', '<?php namespace Billing; class Create {}');
        $report = $this->report('Billing\\Create');
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertTrue($report['subject']['covered']);
        $this->assertSame('application', $report['subject']['expected']['role']);
    }

    public function test_source_capture_is_frozen_and_never_reads_symlinks_or_vendor(): void
    {
        $this->fixture();
        $this->write('app/vendor/Hidden.php', '<?php class Hidden {}');
        $this->write('outside.php', '<?php class Outside {}');
        symlink($this->tempPath.'/outside.php', $this->tempPath.'/app/Symlink.php');
        $snapshot = (new TargetSources($this->tempPath))->capture(['app']);
        $this->assertArrayNotHasKey('app/vendor/Hidden.php', $snapshot->files);
        $this->assertArrayNotHasKey('app/Symlink.php', $snapshot->files);
        $fs = new FrozenFilesystem($snapshot, $this->tempPath);
        $contents = $fs->get($this->tempPath.'/app/Actions/CreateInvoice.php');
        $this->write('app/Actions/CreateInvoice.php', '<?php class Mutated {}');
        $this->assertSame($contents, $fs->get($this->tempPath.'/app/Actions/CreateInvoice.php'));
        $this->assertFalse($fs->exists($this->tempPath.'/outside.php'));
        $this->assertNotSame($snapshot->fingerprint, (new TargetSources($this->tempPath))->capture(['app'])->fingerprint);
    }

    public function test_conflicting_and_unsafe_declarations_fail_with_friendly_error(): void
    {
        $this->fixture();
        foreach ([['paths' => ['../outside']], ['placements' => [['kind' => 'action', 'paths' => ['app/A']], ['kind' => 'action', 'paths' => ['app/B']]]], ['classification' => ['roles' => [['path' => 'app', 'role' => 'domain'], ['path' => 'app', 'role' => 'adapter']]]]] as $change) {
            $this->target($change);
            $this->assertSame('E_TARGET_CONFIGURATION', $this->report()['m']);
        }
    }

    public function test_missing_source_is_not_reported_as_a_fixed_issue(): void
    {
        $this->fixture();
        $this->accept();
        $this->target(['reference' => '.architecture-kit/reference.json']);
        unlink($this->tempPath.'/app/Actions/CreateInvoice.php');
        $report = $this->report();
        $this->assertSame(0, $report['progress']['resolved_issues']);
        $this->assertSame('source_not_observed', $report['progress']['transitions'][0]['after']);
        $this->assertFalse($report['progress']['transitions'][0]['code_improvement']);
    }

    public function test_cli_mcp_schema_and_file_rules_use_the_same_target(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', '<?php return ["enabled" => ["actions"]];');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertSame(0, Artisan::call('architecture-kit:target', ['subject' => 'App\\Actions\\CreateInvoice', '--agent' => true]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(json_decode(json_encode($report, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), $cli);
        ArchitectureKitServer::tool(Target::class, ['subject' => 'App\\Actions\\CreateInvoice'])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('cmd', 'architecture-target')->where('subject.status', 'migration')->where('gate.mode', 'info')->etc());
        $this->assertSame(0, Artisan::call('architecture-kit:target', ['--schema' => true]));
        $schema = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(TargetSchema::get(), $schema);
        $this->assertSame(0, Artisan::call('architecture-kit:file-rules', ['path' => 'app/Billing/Actions/Future.php', '--agent' => true]));
        $guidance = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('future_file', $guidance['target']['subject']['status']);
        $this->target(['mode' => 'block']);
        $this->assertSame(1, Artisan::call('architecture-kit:target', ['--agent' => true]));
        $this->assertFalse($this->report()['gate']['ok']);
        $this->assertSame(1, Artisan::call('architecture-kit:target', ['--limit' => '-1', '--agent' => true]));
    }

    public function test_known_dependency_policies_and_migration_order_with_cycles(): void
    {
        $this->fixture(['dependencies' => [['from' => ['kind' => 'action'], 'allow' => [['role' => 'domain']]]]]);
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { public function handle(Second $second) {} }');
        $this->write('app/Actions/Second.php', '<?php namespace App\Actions; class Second { public function handle() {} }');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertContains('E_TARGET_DEPENDENCY', array_column($report['subject']['issues'], 'code'));
        $this->assertSame('App\\Actions\\Second', $report['migration_order'][0]['symbol']);
        $this->assertSame('App\\Actions\\CreateInvoice', $report['migration_order'][1]['symbol']);
        $this->write('app/Actions/Second.php', '<?php namespace App\Actions; class Second { public function handle(CreateInvoice $first) {} }');
        $cycle = $this->report();
        $this->assertSame([null, null], array_column($cycle['migration_order'], 'rank'));
        $this->assertNotEmpty($cycle['migration_order'][0]['prerequisites']);
    }

    public function test_inline_and_baseline_findings_remain_visible_and_current_audit_is_unchanged(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', '<?php return ["enabled" => ["actions"]];');
        $code = '<?php namespace App\Actions; use Illuminate\Http\Request; class CreateInvoice { public function handle() {} }';
        $this->write('app/Actions/CreateInvoice.php', $code);
        $before = $this->report();
        $this->assertNotEmpty($before['audit']['findings']);
        $findings = array_map(fn ($row) => new AuditFinding($row['severity'], $row['rule'], $row['path'], $row['line'], $row['message']), $before['audit']['findings']);
        (new Baseline(new Filesystem, $this->tempPath))->write($findings);
        $suppressed = $this->report('App\\Actions\\CreateInvoice');
        $this->assertSame('migration', $suppressed['subject']['status']);
        $this->assertContains('baseline', array_column($suppressed['audit']['findings'], 'suppression'));
        $this->assertTrue($suppressed['audit']['findings'][0]['unresolved']);
        $this->assertSame('actions', $suppressed['subject']['audit_divergence'][0]['rule']);
        $this->write('app/Actions/CreateInvoice.php', str_replace('namespace App', "// @architecture-kit-ignore-file actions\nnamespace App", $code));
        $inline = $this->report();
        $this->assertContains('inline', array_column($inline['audit']['findings'], 'suppression'));
        $this->assertSame(1, $inline['totals']['migration']);
        $this->assertSame($before['audit']['findings'][0]['message'], $inline['audit']['findings'][0]['message']);
        $this->assertSame(0, Artisan::call('architecture-kit:audit', ['--agent' => true]));
        $ordinary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $ordinary['sup']['inline']);
        $this->target(['classification' => ['roles' => [['path' => 'app/Actions', 'kind' => 'controller', 'role' => 'adapter']]], 'placements' => []]);
        $this->assertSame(0, Artisan::call('architecture-kit:audit', ['--agent' => true]));
        $this->assertSame($ordinary, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_dynamic_calls_remain_requires_check_and_cannot_pass_block_gate(): void
    {
        $this->fixture(['placements' => [], 'mode' => 'block']);
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { public function handle($service) { $service->run(); } }');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertSame('requires_check', $report['subject']['status']);
        $this->assertFalse($report['gate']['ok']);
        $this->assertNotEmpty($report['notices']);
    }

    public function test_duplicate_json_keys_and_conflicting_path_namespace_or_placement_roles_fail(): void
    {
        $this->fixture();
        $this->write('.architecture-kit/target.json', '{"id":"billing","version":"1","paths":["app"],"paths":["modules"]}');
        $this->assertSame('E_TARGET_CONFIGURATION', $this->report()['m']);
        $this->target(['classification' => ['roles' => [['path' => 'app/Actions', 'role' => 'application'], ['namespace' => 'App\\Actions', 'role' => 'domain']]]]);
        $this->assertSame('E_TARGET_CONFIGURATION', $this->report()['m']);
        $this->target(['classification' => ['roles' => [['path' => 'app/Actions', 'role' => 'domain']]], 'placements' => [['kind' => 'action', 'role' => 'application', 'paths' => ['app/Billing/Actions']]]]);
        $this->assertSame('E_TARGET_CONFIGURATION', $this->report()['m']);
    }

    public function test_suppression_is_not_migration_progress_and_warn_does_not_block(): void
    {
        $this->fixture();
        $this->accept();
        $this->target(['mode' => 'warn', 'reference' => '.architecture-kit/reference.json']);
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; /* @architecture-kit-ignore-file actions */ class CreateInvoice { public function handle() {} }');
        $report = $this->report();
        $this->assertSame(1, $report['totals']['migration']);
        $this->assertSame(0, $report['progress']['resolved_issues']);
        $this->assertSame(1, $report['gate']['warnings']);
        $this->assertTrue($report['gate']['ok']);
    }

    public function test_guard_opt_in_preserves_current_audit_and_is_shared_with_mcp(): void
    {
        $this->fixture(['mode' => 'block']);
        $files = new Filesystem;
        $enabled = [Architecture::Actions];
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        unlink($this->tempPath.'/config/architectures.php');
        $config->write($enabled);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);
        foreach ([$resources->guideline($enabled), ...array_values($resources->skills($enabled))] as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
        $exit = Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $ordinary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('target', $ordinary);
        $this->assertSame(1, Artisan::call('architecture-kit:guard', ['--agent' => true, '--target' => true]));
        $targeted = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $targeted['audit']);
        $this->assertFalse($targeted['target']['gate']['ok']);
        $this->assertSame($ordinary['err'], $targeted['err']);
        ArchitectureKitServer::tool(Guard::class, ['changed' => false, 'strict' => false, 'target' => true])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('ok', false)->where('audit', 'ok')->where('target.gate.ok', false)->etc());
    }

    public function test_absent_declared_scope_and_source_limits_are_partial_not_a_clean_gate(): void
    {
        $this->fixture(['paths' => ['modules'], 'mode' => 'block']);
        $missing = $this->report();
        $this->assertTrue($missing['ok']);
        $this->assertFalse($missing['gate']['ok']);
        $this->assertFalse($missing['reference_candidate']['complete']);
        $this->assertContains('E_TARGET_SCOPE', array_column($missing['notices'], 'code'));
        $this->target(['mode' => 'block']);
        $this->write('app/Huge.php', '<?php '.str_repeat(' ', 1000000));
        $large = $this->report();
        $this->assertFalse($large['gate']['ok']);
        $this->assertTrue($large['analysis']['total_is_lower_bound']);
        $this->assertSame(1, $large['totals']['migration']);
        $this->assertSame(1, Artisan::call('architecture-kit:target', ['--limit' => '0', '--agent' => true]));
        $limited = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $limited['elements']);
        $this->assertSame(1, $limited['totals']['migration']);
        $this->assertTrue($limited['analysis']['display_truncated']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_working_mutation_marks_report_stale_and_candidate_unacceptable(): void
    {
        $this->fixture();
        require dirname(__DIR__).'/Fixtures/Target/MutatingSourceReader.php';
        MutatingSourceReader::$path = $this->tempPath.'/app/Actions/CreateInvoice.php';
        // Change the file immediately after each snapshot reads it. This cannot
        // race with the report completing between writes on a fast runner.
        $report = $this->report();
        $this->assertGreaterThanOrEqual(3, MutatingSourceReader::$reads);
        $this->assertTrue($report['ok']);
        $this->assertFalse($report['analysis']['fresh']);
        $this->assertFalse($report['reference_candidate']['complete']);
        $this->assertSame(['rerun:architecture-target'], $report['next']);
    }

    public function test_target_module_assignment_and_dependency_rules_are_independent_of_current_mapping(): void
    {
        $this->fixture(['classification' => ['modules' => [['path' => 'app/Actions', 'name' => 'Billing'], ['path' => 'app/Models', 'name' => 'Shared']]], 'dependencies' => [['from' => ['module' => 'Billing'], 'allow' => [['module' => 'Billing']]]]]);
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; class Invoice {}');
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { public function handle(\App\Models\Invoice $invoice) {} }');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertTrue($report['ok']);
        $this->assertNull($report['subject']['current']['module']);
        $this->assertSame('Billing', $report['subject']['expected']['module']);
        $this->assertContains('E_TARGET_DEPENDENCY', array_column($report['subject']['issues'], 'code'));
        $this->assertContains('module', array_column($report['subject']['issues'], 'dimension'));
    }

    public function test_dependency_evaluation_budget_is_explicit_and_cannot_be_an_accepted_reference(): void
    {
        $policies = [];
        for ($i = 0; $i < 600; $i++) {
            $policies[] = ['from' => ['module' => 'Module'.$i], 'allow' => [['role' => 'domain']]];
        }
        $this->fixture(['dependencies' => $policies, 'mode' => 'block']);
        $properties = '';
        for ($i = 0; $i < 200; $i++) {
            $properties .= 'public Second $field'.$i.';';
        }
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { '.$properties.' public function handle() {} }');
        $this->write('app/Actions/Second.php', '<?php namespace App\Actions; class Second { public function handle() {} }');
        $report = $this->report();
        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertContains('E_TARGET_BUDGET', array_column($report['notices'], 'code'));
        $this->assertTrue($report['analysis']['total_is_lower_bound']);
        $this->assertFalse($report['gate']['ok']);
        $this->assertFalse($report['reference_candidate']['complete']);
    }

    public function test_audit_exclude_cannot_hide_dynamic_target_dependencies(): void
    {
        $this->fixture(['placements' => [], 'mode' => 'block']);
        $this->write('config/architectures.php', '<?php return ["enabled" => [], "audit" => ["exclude" => ["app/Actions/*"]]];');
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { public function handle($service) { $service->run(); } }');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertTrue($report['ok']);
        $this->assertSame('requires_check', $report['subject']['status']);
        $this->assertFalse($report['gate']['ok']);
        $this->assertFalse($report['reference_candidate']['complete']);
        $this->assertNotEmpty($report['subject']['uncertainty']);
    }

    public function test_dynamic_include_uncertainty_blocks_and_cannot_claim_reference_progress(): void
    {
        $this->fixture(['placements' => [], 'mode' => 'block']);
        $this->accept();
        $this->target(['placements' => [], 'mode' => 'block', 'reference' => '.architecture-kit/reference.json']);
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { public function handle($path) { require $path; } }');
        $report = $this->report('App\\Actions\\CreateInvoice');
        $this->assertTrue($report['ok']);
        $this->assertSame('requires_check', $report['subject']['status']);
        $this->assertFalse($report['gate']['ok']);
        $this->assertFalse($report['analysis']['dependencies_complete']);
        $this->assertFalse($report['reference_candidate']['complete']);
        $this->assertNull($report['progress']['resolved_issues']);
        $this->assertNotEmpty($report['subject']['uncertainty']);
        $this->assertStringContainsString('include', $report['subject']['uncertainty'][0]['reason']);
    }

    public function test_existing_classless_script_is_not_a_future_file_and_cannot_pass_block_gate(): void
    {
        $this->fixture(['placements' => [], 'mode' => 'block']);
        $this->write('app/Actions/script.php', '<?php $service->run();');
        $report = $this->report('app/Actions/script.php');
        $this->assertTrue($report['ok']);
        $script = $report['subject']['elements'][0];
        $this->assertSame('requires_check', $script['status']);
        $this->assertSame('file', $script['current']['php_kind']);
        $this->assertNotEmpty($script['uncertainty']);
        $this->assertFalse($report['gate']['ok']);
        $this->assertFalse($report['reference_candidate']['complete']);
        $this->write('app/Actions/script.php', '<?php broken {');
        $broken = $this->report('app/Actions/script.php');
        $this->assertSame('requires_check', $broken['subject']['elements'][0]['status'] ?? $broken['subject']['status']);
        $this->assertFalse($broken['gate']['ok']);
    }

    /** @param array<string, mixed> $changes */
    private function fixture(array $changes = []): void
    {
        $this->write('config/architectures.php', '<?php return ["enabled" => []];');
        $this->write('app/Actions/CreateInvoice.php', '<?php namespace App\Actions; class CreateInvoice { public function handle() {} }');
        $this->target($changes);
    }

    /** @param array<string, mixed> $changes */
    private function target(array $changes = []): void
    {
        $this->write('.architecture-kit/target.json', json_encode(array_replace(['id' => 'billing', 'version' => '1', 'paths' => ['app'], 'placements' => [['kind' => 'action', 'paths' => ['app/Billing/Actions']]]], $changes), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed>|null $candidate */
    private function accept(?array $candidate = null): void
    {
        $candidate ??= $this->report()['reference_candidate'];
        $candidate['accepted'] = true;
        $candidate['accepted_by'] = 'project-owner';
        $this->write('.architecture-kit/reference.json', json_encode($candidate, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function report(string $subject = ''): array
    {
        return (new ArchitectureTarget($this->tempPath))->inspect($subject);
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
