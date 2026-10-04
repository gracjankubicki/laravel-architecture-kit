<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Transport\FakeTransporter;

final class ArchitectureSignatureTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
        clearstatcache();
    }

    private function fixture(string $method = 'public function calculate($invoice) {}'): void
    {
        $this->write('app/Calculator.php', '<?php namespace App; class Calculator { '.$method.' }');
        $this->write('app/Caller.php', '<?php namespace App; class Caller { public function short() { (new Calculator)->calculate(1); } public function long() { (new Calculator)->calculate(1, "EUR"); } public function named() { (new Calculator)->calculate(invoice: 1); } }');
    }

    private function query(?string $proposal = null, string $subject = 'Calculator::calculate', int $limit = 100, bool $cache = false): array
    {
        $files = new Filesystem;

        return (new ArchitectureImpact($files, $this->tempPath, new AuditScope(['app', 'tests']), $cache ? new ProjectGraphCache($files, $this->tempPath) : null))->inspect($subject, limit: $limit, change: 'signature', signature: $proposal);
    }

    private function rows(array $result, string $group): array
    {
        return array_column($result['signature'][$group], 'symbol');
    }

    public function test_inspect_mode_lists_immediate_calls_without_claiming_breaking(): void
    {
        $this->fixture();
        $this->write('app/Indirect.php', '<?php namespace App; class Indirect { public function handle(Caller $x) { $x->short(); } }');
        $result = $this->query();
        $this->assertSame('inspect', $result['signature']['mode']);
        $this->assertSame([], $result['signature']['breaking']);
        $this->assertContains('App\Caller::short', $this->rows($result, 'check'));
        $this->assertNotContains('App\Indirect::handle', $this->rows($result, 'check'));
        $this->assertFalse($result['signature']['safe_to_change']);
    }

    public function test_required_optional_variadic_and_named_argument_changes(): void
    {
        $this->fixture();
        $required = $this->query('calculate($invoice, $currency)');
        $this->assertContains('App\Caller::short', $this->rows($required, 'breaking'));
        $this->assertContains('App\Caller::named', $this->rows($required, 'breaking'));
        $this->assertContains('App\Caller::long', $this->rows($required, 'compatible'));
        $optional = $this->query('calculate($invoice, $currency = "PLN")');
        $this->assertSame([], $optional['signature']['breaking']);
        $this->assertContains('App\Caller::short', $this->rows($optional, 'compatible'));
        $variadic = $this->query('calculate($invoice, ...$others)');
        $this->assertSame([], $variadic['signature']['breaking']);
        $renamed = $this->query('calculate($document)');
        $this->assertContains('App\Caller::named', $this->rows($renamed, 'breaking'));
        $this->assertNotContains('App\Caller::short', $this->rows($renamed, 'breaking'));
        foreach ($required['signature']['breaking'] as $row) {
            $this->assertFileExists($this->tempPath.'/'.$row['path']);
            $this->assertGreaterThan(0, $row['line']);
            $this->assertNotEmpty($row['reasons']);
        }
    }

    public function test_extra_positional_arguments_and_removed_parameters_are_semantic_checks(): void
    {
        $this->fixture('public function calculate($invoice, $currency = "PLN") {}');
        $result = $this->query('calculate($invoice)');
        $this->assertSame([], $result['signature']['breaking']);
        $this->assertContains('App\Caller::long', $this->rows($result, 'check'));
        $this->assertStringContainsString('extra positional', json_encode($result['signature']));
    }

    public function test_unpack_possible_callable_and_dynamic_calls_are_checks(): void
    {
        $this->fixture();
        $this->write('app/Child.php', '<?php namespace App; class Child extends Calculator { public function calculate($invoice) {} }');
        $this->write('app/Uncertain.php', '<?php namespace App; class Uncertain { public function spread($args) { (new Calculator)->calculate(...$args); } public function possible(Calculator $c) { $c->calculate(1); } public function reference() { return [Calculator::class, "calculate"]; } public function dynamic($c, $method) { $c->$method(1); } }');
        $result = $this->query('calculate($invoice, $currency)', 'Child::calculate');
        $this->assertNotContains('App\Uncertain::possible', $this->rows($result, 'breaking'));
        $this->assertContains('App\Uncertain::possible', $this->rows($result, 'check'));
        $base = $this->query('calculate($invoice, $currency)');
        $this->assertContains('App\Uncertain::spread', $this->rows($base, 'check'));
        $this->assertContains('App\Uncertain::reference', $this->rows($base, 'check'));
        $this->assertStringContainsString('Unresolved', json_encode($base['signature']['check']));
    }

    public function test_same_line_calls_keep_their_own_argument_evidence(): void
    {
        $this->fixture();
        $this->write('app/SameLine.php', '<?php namespace App; class SameLine { public function call() { (new Calculator)->calculate(1); (new Calculator)->calculate(1, 2); } }');
        $result = $this->query('calculate($invoice, $currency)');
        $this->assertContains('App\SameLine::call', $this->rows($result, 'breaking'));
        $this->assertContains('App\SameLine::call', $this->rows($result, 'compatible'));
    }

    public function test_reference_literal_is_breaking_variable_and_returning_call_are_distinct(): void
    {
        $this->fixture();
        $this->write('app/References.php', '<?php namespace App; class References { public function variable() { $i = 1; (new Calculator)->calculate($i); } public function unknown() { (new Calculator)->calculate(value()); } }');
        $result = $this->query('calculate(&$invoice)');
        $this->assertContains('App\Caller::short', $this->rows($result, 'breaking'));
        $this->assertNotContains('App\References::variable', $this->rows($result, 'breaking'));
        $this->assertContains('App\References::unknown', $this->rows($result, 'check'));
    }

    public function test_static_to_instance_respects_inherited_instance_scope(): void
    {
        $this->fixture('public static function calculate($invoice) {}');
        $this->write('app/StaticCalls.php', '<?php namespace App; class StaticCalls { public function outside() { Calculator::calculate(1); } } class LocalCalls extends Calculator { public function inside() { parent::calculate(1); Calculator::calculate(1); } public static function noInstance() { parent::calculate(1); } }');
        $result = $this->query('public function calculate($invoice)');
        $this->assertContains('App\StaticCalls::outside', $this->rows($result, 'breaking'));
        $this->assertContains('App\LocalCalls::noInstance', $this->rows($result, 'breaking'));
        $this->assertNotContains('App\LocalCalls::inside', $this->rows($result, 'breaking'));
    }

    public function test_visibility_narrowing_checks_caller_class_scope(): void
    {
        $this->fixture('public function calculate($invoice) {} public function local() { $this->calculate(1); }');
        $this->write('app/Child.php', '<?php namespace App; class Child extends Calculator { public function localChild() { $this->calculate(1); } }');
        $protected = $this->query('protected function calculate($invoice)');
        $this->assertContains('App\Caller::short', $this->rows($protected, 'breaking'));
        $this->assertNotContains('App\Calculator::local', $this->rows($protected, 'breaking'));
        $this->assertNotContains('App\Child::localChild', $this->rows($protected, 'breaking'));
        $private = $this->query('private function calculate($invoice)');
        $this->assertContains('App\Child::localChild', $this->rows($private, 'breaking'));
    }

    public function test_contracts_go_up_and_down_and_check_required_capacity_reference_and_static(): void
    {
        $this->write('app/Contract.php', '<?php namespace App; interface Contract { public function calculate($invoice); }');
        $this->write('app/Calculator.php', '<?php namespace App; class Calculator implements Contract { public function calculate($invoice) {} }');
        $this->write('app/Child.php', '<?php namespace App; class Child extends Calculator { public function calculate($invoice) {} }');
        $up = $this->query('calculate($invoice, $currency)');
        $this->assertContains('App\Contract::calculate', $this->rows($up, 'breaking'));
        $this->assertContains('App\Child::calculate', $this->rows($up, 'breaking'));
        $down = $this->query('calculate($invoice, $currency = null)', 'Contract::calculate');
        $this->assertContains('App\Calculator::calculate', $this->rows($down, 'breaking'));
        $this->assertContains('App\Child::calculate', $this->rows($down, 'breaking'));
        $ref = $this->query('calculate(&$invoice)');
        $this->assertContains('App\Contract::calculate', $this->rows($ref, 'breaking'));
        $static = $this->query('public static function calculate($invoice)');
        $this->assertContains('App\Contract::calculate', $this->rows($static, 'breaking'));
    }

    public function test_type_changes_are_uncertain_and_known_absence_contract_failures_are_proved(): void
    {
        $this->write('app/Contract.php', '<?php namespace App; interface Contract { public function calculate($invoice): int; }');
        $this->write('app/Calculator.php', '<?php namespace App; class Calculator implements Contract { public function calculate($invoice): int { return 1; } }');
        $different = $this->query('calculate($invoice): string');
        $this->assertContains('App\Contract::calculate', $this->rows($different, 'check'));
        $removed = $this->query('calculate($invoice)');
        $this->assertContains('App\Contract::calculate', $this->rows($removed, 'breaking'));
        $restricted = $this->query('calculate(int $invoice): int');
        $this->assertContains('App\Contract::calculate', $this->rows($restricted, 'breaking'));
    }

    public function test_inherited_and_trait_selectors_show_actual_changed_declaration(): void
    {
        $this->fixture();
        $this->write('app/Child.php', '<?php namespace App; class Child extends Calculator {}');
        $this->assertSame('App\Calculator::calculate', $this->query('calculate($invoice, $currency)', 'Child::calculate')['signature']['declaration']);
        $this->write('app/Trait.php', '<?php namespace App; trait Calculates { public function calculate($invoice) {} } interface Contract { public function calculate($invoice); } class TraitUser implements Contract { use Calculates; }');
        $trait = $this->query('calculate($invoice, $currency)', 'TraitUser::calculate');
        $this->assertSame('App\Calculates::calculate', $trait['signature']['declaration']);
        $this->assertContains('App\Contract::calculate', $this->rows($trait, 'breaking'));
    }

    public function test_constructor_new_arguments_and_container_uncertainty_with_concrete_parent_exception(): void
    {
        $this->write('app/Base.php', '<?php namespace App; class Base { public function __construct($value) {} } class Built extends Base { public function __construct($value) {} } class Builder { public function make() { return new Built(1); } }');
        $result = $this->query('__construct($value, $currency)', 'Built::__construct');
        $this->assertContains('App\Builder::make', $this->rows($result, 'breaking'));
        $this->assertNotContains('App\Base::__construct', $this->rows($result, 'breaking'));
        $this->assertContains('container', array_column($result['signature']['check'], 'kind'));
    }

    public function test_limits_uncertainty_and_no_callers_never_claim_safety(): void
    {
        $this->fixture();
        $limited = $this->query('calculate($invoice, $currency)', limit: 0);
        $this->assertSame('limit', $limited['signature']['status']);
        $this->assertTrue($limited['signature']['truncated']);
        $this->assertSame([], $limited['signature']['breaking']);
        $this->assertGreaterThan(0, $limited['signature']['total']['breaking']);
        $this->write('app/Unused.php', '<?php namespace App; class Unused { public function run() {} }');
        $none = $this->query('run()', 'Unused::run');
        $this->assertSame('no_proven_breaking', $none['signature']['status']);
        $this->assertFalse($none['signature']['safe_to_change']);
        $this->assertStringContainsString('Framework', implode(' ', $none['signature']['limitations']));
    }

    public function test_proposal_errors_and_discovery_never_execute_application_or_defaults(): void
    {
        $this->fixture();
        foreach (['other($invoice)', 'calculate($invoice) { die(); }', 'calculate($x, $x)', 'calculate(...$x, $y)', '', str_repeat('x', 10001)] as $bad) {
            $result = $this->query($bad);
            $this->assertFalse($result['ok'], $bad);
            $this->assertSame('E_IMPACT_SIGNATURE_INVALID', $result['m']);
        }
        $this->assertSame('E_IMPACT_SIGNATURE_SUBJECT', $this->query('calculate()', 'Calculator')['m']);
        $this->write('app/Poison.php', '<?php namespace App; file_put_contents('.var_export($this->tempPath.'/executed', true).', "bad"); class Poison { public function __construct() {} }');
        $this->assertTrue($this->query('__construct($x = new \App\Poison)', 'Poison::__construct')['ok']);
        $this->assertFileDoesNotExist($this->tempPath.'/executed');
    }

    public function test_cache_cold_warm_changed_corrupt_metadata_and_legacy_graph_output(): void
    {
        $this->fixture();
        $first = $this->query('calculate($invoice, $currency)', cache: true);
        $warm = $this->query('calculate($invoice, $currency)', cache: true);
        $this->assertSame($first['signature'], $warm['signature']);
        $this->assertSame('fresh', $warm['cache']);
        $this->write('app/Caller.php', '<?php namespace App; class Caller { public function short() { (new Calculator)->calculate(1, "EUR"); } }');
        $changed = $this->query('calculate($invoice, $currency)', cache: true);
        $this->assertSame([], $changed['signature']['breaking']);
        $files = new Filesystem;
        $loader = new ProjectGraphLoader($files, $this->tempPath, new AuditScope(['app', 'tests']), cache: new ProjectGraphCache($files, $this->tempPath), impact: true);
        $plan = $loader->plan();
        $data = (new ProjectGraphCache($files, $this->tempPath))->read($plan->signature)->graph->toArray();
        $data['entries']['app/Calculator.php']['i']['classes']['App\Calculator']['methods']['calculate']['signature']['static'] = 'bad';
        $this->assertNull(CachedGraph::fromArray($data));
        $a = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $b = (new ProjectGraphLoader($files, $this->tempPath, impact: true))->load();
        $this->assertEquals($a->symbols, $b->symbols);
        $this->assertEquals($a->edges, $b->edges);
        $plain = (new ArchitectureImpact($files, $this->tempPath))->inspect('Calculator::calculate');
        $this->assertArrayNotHasKey('signature', $plain);
        $this->assertArrayNotHasKey('signature', $plain['subject']['declaration']);
    }

    public function test_cli_mcp_schema_and_instructions_are_consistent(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', "<?php return ['enabled' => ['actions'], 'audit' => ['cache' => false]];");
        $proposal = 'calculate($invoice, $currency)';
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Calculator::calculate', '--signature' => $proposal, '--agent' => true, '--limit' => 100]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Calculator::calculate', 'signature' => $proposal, 'limit' => 100])->assertOk()->assertStructuredContent(fn ($json) => $json->where('signature', $cli['signature'])->etc());
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Calculator::calculate', '--change' => 'signature']));
        $this->assertStringContainsString('Signature inspect', Artisan::output());
        $this->assertSame(1, Artisan::call('architecture-kit:impact', ['subject' => 'Calculator::calculate', '--signature' => 'other()']));
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Calculator::calculate', 'signature' => 123])->assertOk()->assertStructuredContent(fn ($json) => $json->where('m', 'E_INVALID_TOOL_INPUT')->etc());
        $schema = ImpactSchema::get()['oneOf'][0]['properties']['signature'];
        $this->assertContains('safe_to_change', $schema['required']);
        $context = (new ArchitectureKitServer(new FakeTransporter))->createContext();
        $tool = $context->tools()->first(fn ($t) => $t->name() === 'impact');
        $this->assertSame(['signature', 'delete'], $tool->toArray()['inputSchema']['properties']['change']['enum']);
        $this->assertStringContainsString('signature', $context->instructions);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath);
        $this->assertStringContainsString('signature', $resources->guideline([])->contents);
    }

    public function test_same_short_type_names_in_different_namespaces_are_not_proved_identical(): void
    {
        $this->write('app/Contract.php', '<?php namespace Domain; interface Contract { public function calculate(Value $invoice): Value; }');
        $this->write('app/Calculator.php', '<?php namespace App; class Calculator implements \Domain\Contract { public function calculate(Value $invoice): Value {} }');
        $result = $this->query('calculate(\App\Value $invoice): \App\Value');
        $this->assertContains('Domain\\Contract::calculate', $this->rows($result, 'check'));
        $this->assertSame('app\\value', $result['signature']['current']['return_type']);
    }

    public function test_out_of_scope_parent_contract_is_an_explicit_check(): void
    {
        $this->write('app/Calculator.php', '<?php namespace App; class Calculator implements \Vendor\Contract { public function calculate($invoice) {} }');
        $result = $this->query('calculate($invoice, $currency = null)');
        $this->assertContains('Vendor\\Contract', $this->rows($result, 'check'));
        $this->assertSame('check', $result['signature']['status']);
    }

    public function test_magic_dispatch_after_visibility_change_is_not_a_proved_access_error(): void
    {
        $this->fixture('public function calculate($invoice) {} public function __call($name, $arguments) {}');
        $result = $this->query('private function calculate($invoice)');
        $this->assertNotContains('App\\Caller::short', $this->rows($result, 'breaking'));
        $this->assertContains('App\\Caller::short', $this->rows($result, 'check'));
    }

    public function test_extra_positional_arguments_need_inspection_without_a_signature_change(): void
    {
        $this->fixture();
        $result = $this->query('calculate($invoice)');
        $this->assertContains('App\\Caller::long', $this->rows($result, 'check'));
        $this->assertNotContains('App\\Caller::long', $this->rows($result, 'compatible'));
        $this->assertSame([], $result['signature']['breaking']);
        $this->assertContains('App\\Caller::long', $this->rows($this->query('calculate($invoice, ...$rest)'), 'compatible'));
    }

    public function test_abstract_trait_requirements_are_contracts_in_both_directions(): void
    {
        $this->write('app/Trait.php', '<?php namespace App; trait Calculates { abstract public function calculate($invoice); } class Calculator { use Calculates; public function calculate($invoice) {} }');
        $up = $this->query('calculate($invoice, $currency)');
        $this->assertContains('App\\Calculates::calculate', $this->rows($up, 'breaking'));
        $down = $this->query('calculate($invoice, $currency = null)', 'Calculates::calculate');
        $this->assertContains('App\\Calculator::calculate', $this->rows($down, 'breaking'));
    }

    public function test_concrete_trait_replacements_are_independent_but_inherited_overrides_have_contracts(): void
    {
        $this->write('app/Trait.php', '<?php namespace App; trait Calculates { public function calculate($invoice) {} } class Calculator { use Calculates; public function calculate($invoice) {} } class Imports { use Calculates; } class Child extends Imports { public function calculate($invoice) {} }');
        $trait = $this->query('calculate($invoice, $currency = null)', 'Calculates::calculate');
        $this->assertNotContains('App\\Calculator::calculate', $this->rows($trait, 'breaking'));
        $this->assertContains('App\\Child::calculate', $this->rows($trait, 'breaking'));
        $replacement = $this->query('calculate($invoice, $currency)');
        $this->assertNotContains('App\\Calculates::calculate', $this->rows($replacement, 'breaking'));
        $child = $this->query('calculate($invoice, $currency)', 'Child::calculate');
        $this->assertContains('App\\Calculates::calculate', $this->rows($child, 'breaking'));
    }

    public function test_shorthand_defaults_containing_function_are_parsed_as_literals(): void
    {
        $this->fixture('protected static function calculate($invoice) {}');
        $result = $this->query('calculate($invoice = "function")');
        $this->assertTrue($result['ok']);
        $this->assertSame('protected', $result['signature']['proposed']['visibility']);
        $this->assertTrue($result['signature']['proposed']['static']);
        $this->assertSame('"function"', $result['signature']['proposed']['parameters'][0]['default']);
    }

    public function test_magic_handlers_on_the_receiver_child_prevent_proved_access_errors(): void
    {
        $this->fixture();
        $this->write('app/Magic.php', '<?php namespace App; class Child extends Calculator { public function __call($name, $arguments) {} public static function __callStatic($name, $arguments) {} } class MagicCaller { public function instance() { (new Child)->calculate(1); } public function statically() { Child::calculate(1); } }');
        $result = $this->query('private function calculate($invoice)');
        $this->assertNotContains('App\\MagicCaller::instance', $this->rows($result, 'breaking'));
        $this->assertContains('App\\MagicCaller::instance', $this->rows($result, 'check'));
        $this->assertNotContains('App\\MagicCaller::statically', $this->rows($result, 'breaking'));
        $this->assertContains('App\\MagicCaller::statically', $this->rows($result, 'check'));
        $this->assertContains('App\\Caller::short', $this->rows($result, 'breaking'));
    }

    public function test_private_abstract_trait_requirements_apply_but_private_parent_methods_are_independent(): void
    {
        $this->write('app/Trait.php', '<?php namespace App; trait Calculates { abstract private function calculate($invoice); } class Calculator { use Calculates; private function calculate($invoice) {} }');
        $up = $this->query('private function calculate($invoice, $currency)');
        $this->assertContains('App\\Calculates::calculate', $this->rows($up, 'breaking'));
        $down = $this->query('private function calculate($invoice, $currency = null)', 'Calculates::calculate');
        $this->assertContains('App\\Calculator::calculate', $this->rows($down, 'breaking'));
        $this->write('app/Parent.php', '<?php namespace App; class ParentCalculator { private function calculate($invoice) {} } class ChildCalculator extends ParentCalculator { private function calculate($invoice) {} }');
        $child = $this->query('private function calculate($invoice, $currency)', 'ChildCalculator::calculate');
        $this->assertNotContains('App\\ParentCalculator::calculate', $this->rows($child, 'breaking'));
        $parent = $this->query('private function calculate($invoice, $currency = null)', 'ParentCalculator::calculate');
        $this->assertNotContains('App\\ChildCalculator::calculate', $this->rows($parent, 'breaking'));
    }
}
