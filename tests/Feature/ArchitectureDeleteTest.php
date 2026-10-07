<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureDeleteTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php namespace App; '.$source);
        clearstatcache();
    }

    private function inspectImpact(string $subject = 'Target::run', int $limit = 100, bool $cache = false): array
    {
        $files = new Filesystem;

        return (new ArchitectureImpact($files, $this->tempPath, new AuditScope(['app', 'tests']), $cache ? new ProjectGraphCache($files, $this->tempPath) : null))->inspect($subject, limit: $limit, change: 'delete');
    }

    private function rows(array $result, string $group): array
    {
        return array_column($result['delete'][$group], 'symbol');
    }

    private function fixture(): void
    {
        $this->write('app/Target.php', 'class Target { public function run($value) { $this->run(1); } }');
        $this->write('app/Caller.php', 'class Caller { public function call() { (new Target)->run(1); } public function reference() { return [Target::class, "run"]; } }');
    }

    public function test_method_delete_reports_callers_but_ignores_removed_body_and_never_edits_source(): void
    {
        $this->fixture();
        $hash = hash_file('sha256', $this->tempPath.'/app/Target.php');
        $result = $this->inspectImpact();
        $this->assertTrue($result['ok']);
        $this->assertSame(['App\\Target::run'], $result['delete']['removed']);
        $this->assertContains('App\\Caller::call', $this->rows($result, 'breaking'));
        $this->assertNotContains('App\\Target::run', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Caller::reference', $this->rows($result, 'check'));
        $this->assertFalse($result['delete']['safe_to_change']);
        $this->assertSame($hash, hash_file('sha256', $this->tempPath.'/app/Target.php'));
    }

    public function test_parent_fallback_is_checked_and_incompatible_arguments_are_breaking(): void
    {
        $this->write('app/Target.php', 'class Base { public function run($value, $other) {} } class Target extends Base { public function run($value, $other = null) {} } class Caller { public function short() { (new Target)->run(1); } public function long() { (new Target)->run(1, 2); } }');
        $result = $this->inspectImpact();
        $this->assertContains('App\\Caller::short', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Caller::long', $this->rows($result, 'check'));
        $this->assertStringContainsString('App\\Base::run', implode(' ', array_column($result['delete']['check'], 'fallback')));
    }

    public function test_trait_fallback_is_not_treated_as_a_missing_method(): void
    {
        $this->write('app/Target.php', 'trait Runs { public function run($value) {} } class Target { use Runs; public function run($value) {} } class Caller { public function call() { (new Target)->run(1); } }');
        $this->assertNotContains('App\\Caller::call', $this->rows($this->inspectImpact(), 'breaking'));
    }

    public function test_inherited_selector_removes_actual_declaration_and_private_access_fallback_breaks(): void
    {
        $this->write('app/Target.php', 'class Base { private function run($value) {} } class Target extends Base { public function run($value) {} } class Child extends Target {} class Caller { public function call() { (new Child)->run(1); } }');
        $result = $this->inspectImpact('Child::run');
        $this->assertSame(['App\\Target::run'], $result['delete']['removed']);
        $this->assertContains('App\\Caller::call', $this->rows($result, 'breaking'));
    }

    public function test_magic_child_and_uncertain_hierarchy_are_checks(): void
    {
        $this->fixture();
        $this->write('app/Child.php', 'class Child extends Target { public function __call($name, $args) {} } class MagicCaller { public function call() { (new Child)->run(1); } }');
        $result = $this->inspectImpact();
        $this->assertContains('App\\MagicCaller::call', $this->rows($result, 'check'));
        $this->assertNotContains('App\\MagicCaller::call', $this->rows($result, 'breaking'));
        $this->write('app/Target.php', 'class Target extends \Vendor\Base { public function run($value) {} }');
        $this->assertContains('App\\Caller::call', $this->rows($this->inspectImpact(), 'check'));
    }

    public function test_required_interface_implementation_is_breaking_but_abstract_class_is_check(): void
    {
        $this->write('app/Target.php', 'interface Contract { public function run($value); } class Target implements Contract { public function run($value) {} }');
        $this->assertContains('App\\Target::run', $this->rows($this->inspectImpact(), 'breaking'));
        $this->write('app/Target.php', 'interface Contract { public function run($value); } abstract class Target implements Contract { public function run($value) {} }');
        $result = $this->inspectImpact();
        $this->assertNotContains('App\\Target::run', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Target::run', $this->rows($result, 'check'));
    }

    public function test_private_abstract_trait_requires_implementation_but_concrete_trait_replacement_can_be_removed(): void
    {
        $this->write('app/Target.php', 'trait Runs { abstract private function run($value); } class Target { use Runs; private function run($value) {} }');
        $this->assertContains('App\\Target::run', $this->rows($this->inspectImpact(), 'breaking'));
        $this->write('app/Target.php', 'trait Runs { public function run($value) {} } class Target { use Runs; public function run($value) {} }');
        $this->assertNotContains('App\\Target::run', $this->rows($this->inspectImpact(), 'breaking'));
    }

    public function test_removing_interface_requirement_does_not_remove_implementations(): void
    {
        $this->write('app/Target.php', 'interface Contract { public function run($value); } class Target implements Contract { public function run($value) {} } class Caller { public function call(Contract $x) { $x->run(1); } }');
        $result = $this->inspectImpact('Contract::run');
        $this->assertSame([], $result['delete']['breaking']);
        $this->assertContains('App\\Caller::call', $this->rows($result, 'check'));
    }

    public function test_constructor_removal_checks_implicit_construction_and_autowiring(): void
    {
        $this->write('app/Target.php', 'class Target { public function __construct($value) {} } class Caller { public function make() { return new Target(1); } }');
        $result = $this->inspectImpact('Target::__construct');
        $this->assertNotContains('App\\Caller::make', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Caller::make', $this->rows($result, 'check'));
    }

    public function test_class_removal_breaks_new_and_hierarchy_but_types_are_checks(): void
    {
        $this->fixture();
        $this->write('app/Child.php', 'class Child extends Target {} class Typed { public function accept(Target $target): Target { return $target; } }');
        $result = $this->inspectImpact('Target');
        $this->assertContains('App\\Caller', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Child', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Typed', $this->rows($result, 'check'));
        $this->assertNotContains('App\\Target', $this->rows($result, 'breaking'));
    }

    public function test_file_removal_includes_all_classes_and_filters_all_internal_references(): void
    {
        $this->write('app/Pair.php', 'class A { public function call() { new B; } } class B {} new A;');
        $this->write('app/Caller.php', 'class Caller { public function call() { new A; new B; } }');
        $result = $this->inspectImpact('app/Pair.php');
        $this->assertTrue($result['ok']);
        $this->assertSame(['App\\A', 'App\\B'], $result['delete']['removed']);
        $this->assertContains('App\\Caller', $this->rows($result, 'breaking'));
        $this->assertNotContains('App\\A', $this->rows($result, 'breaking'));
        $this->assertNotContains('(file) app/Pair.php', $this->rows($result, 'breaking'));
    }

    public function test_classless_file_limits_and_subject_errors_are_explicit(): void
    {
        $this->write('app/script.php', 'echo "not executed";');
        $file = $this->inspectImpact('app/script.php');
        $this->assertTrue($file['ok']);
        $this->assertSame([], $file['delete']['removed']);
        $this->assertNotEmpty($file['delete']['check']);
        $this->fixture();
        $limited = $this->inspectImpact(limit: 0);
        $this->assertTrue($limited['delete']['truncated']);
        $this->assertSame('limit', $limited['delete']['status']);
        $this->assertGreaterThan(0, $limited['delete']['total']['breaking']);
        $this->assertSame('E_IMPACT_SUBJECT_NOT_FOUND', $this->inspectImpact('Absent')['m']);
        $this->write('app/Other.php', 'namespace Other; class Target {}');
        $this->assertSame('E_IMPACT_SUBJECT_AMBIGUOUS', $this->inspectImpact('Target')['m']);
    }

    public function test_cache_freshness_and_default_signature_payloads_are_preserved(): void
    {
        $this->fixture();
        $cold = $this->inspectImpact(cache: true);
        $warm = $this->inspectImpact(cache: true);
        $this->assertSame('fresh', $warm['cache']);
        $this->assertSame($cold['delete'], $warm['delete']);
        $this->write('app/Caller.php', 'class Caller {}');
        $this->assertSame([], $this->inspectImpact(cache: true)['delete']['breaking']);
        $impact = new ArchitectureImpact(new Filesystem, $this->tempPath);
        $this->assertArrayNotHasKey('delete', $impact->inspect('Target::run'));
        $this->assertArrayHasKey('signature', $impact->inspect('Target::run', signature: 'run($value)'));
        $this->assertSame('E_IMPACT_CHANGE_INVALID', $impact->inspect('Target::run', change: 'delete', signature: 'run()')['m']);
    }

    public function test_cli_mcp_and_schema_share_the_delete_report(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', "return ['enabled' => ['actions'], 'audit' => ['cache' => false]];");
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Target::run', '--change' => 'delete', '--agent' => true, '--limit' => 100]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Target::run', 'change' => 'delete', 'limit' => 100])->assertOk()->assertStructuredContent(fn ($json) => $json->where('delete', $cli['delete'])->etc());
        $this->assertArrayHasKey('delete', ImpactSchema::get()['oneOf'][0]['properties']);
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Target::run', '--change' => 'delete']));
        $this->assertStringContainsString('Delete:', Artisan::output());
    }

    public function test_static_fallback_and_reference_requirements_are_evaluated(): void
    {
        $this->write('app/Target.php', 'class Base { private function run(&$value) {} } class Target extends Base { public static function run($value) {} } class Caller { public function call() { Target::run(1); } }');
        $result = $this->inspectImpact();
        $this->assertContains('App\\Caller::call', $this->rows($result, 'breaking'));
        $this->assertStringContainsString('non_static_method_called_statically', json_encode($result['delete']));
        $this->assertStringContainsString('argument_not_reference', json_encode($result['delete']));
    }

    public function test_interface_and_trait_removal_report_remaining_declarations(): void
    {
        $this->write('app/Target.php', 'interface Contract { public function run($value); } trait Runs { public function run($value) {} } class Target implements Contract { use Runs; }');
        $this->assertContains('App\\Target', $this->rows($this->inspectImpact('Contract'), 'breaking'));
        $this->assertContains('App\\Target', $this->rows($this->inspectImpact('Runs'), 'breaking'));
    }

    public function test_dynamic_calls_are_explicit_and_source_is_never_executed(): void
    {
        $this->fixture();
        $this->write('app/Dynamic.php', 'class Dynamic { public function call($target, $name) { $target->$name(1); } }');
        $marker = $this->tempPath.'/executed';
        $this->write('app/Poison.php', 'file_put_contents('.var_export($marker, true).', "bad"); class Poison {}');
        $result = $this->inspectImpact();
        $this->assertStringContainsString('Unresolved', json_encode($result['delete']['check']));
        $this->assertTrue($this->inspectImpact('Poison')['ok']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_private_trait_fallback_uses_the_importing_class_scope(): void
    {
        $this->write('app/Target.php', 'trait Runs { private function run($value) {} public function fromTrait() { $this->run(1); } } class Target { use Runs; public function run($value) {} public function local() { $this->run(1); self::run(1); } } class Child extends Target { public function childCall() { $this->run(1); } } class Caller { public function call() { (new Target)->run(1); } }');
        $result = $this->inspectImpact();
        $this->assertNotContains('App\\Target::local', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Target::local', $this->rows($result, 'check'));
        $this->assertContains('App\\Caller::call', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Child::childCall', $this->rows($result, 'breaking'));
        $this->assertNotContains('App\\Runs::fromTrait', $this->rows($result, 'breaking'));
    }

    public function test_abstract_trait_requirement_does_not_hide_a_concrete_parent_fallback(): void
    {
        $this->write('app/Target.php', 'trait RequiredRun { abstract public function run($value); } class Base { public function run($value) {} } class Target extends Base { use RequiredRun; public function run($value) {} } class Caller { public function call() { (new Target)->run(1); } }');
        $result = $this->inspectImpact();
        $this->assertSame([], $result['delete']['breaking']);
        $this->assertContains('App\\Caller::call', $this->rows($result, 'check'));
        $this->assertStringContainsString('App\\Base::run', implode(' ', array_column($result['delete']['check'], 'fallback')));
        $this->write('app/Target.php', 'trait RequiredRun { abstract public function run($value); } class Target { use RequiredRun; public function run($value) {} }');
        $this->assertContains('App\\Target::run', $this->rows($this->inspectImpact(), 'breaking'));
    }

    public function test_shared_lookup_preserves_default_and_signature_with_an_inherited_trait_implementation(): void
    {
        $this->write('app/Target.php', 'trait RequiredRun { abstract public function run($value); } class Base { public function run($value) {} } class Target extends Base { use RequiredRun; } class Caller { public function call() { (new Target)->run(1); } }');
        $impact = new ArchitectureImpact(new Filesystem, $this->tempPath);
        $plain = $impact->inspect('Target::run');
        $this->assertSame('App\\Base::run', $plain['subject']['declaration']['symbol']);
        $signature = $impact->inspect('Target::run', signature: 'run($value, $required)');
        $this->assertSame('App\\Base::run', $signature['signature']['declaration']);
        $this->assertContains('App\\Caller::call', array_column($signature['signature']['breaking'], 'symbol'));
    }
}
