<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Transport\FakeTransporter;

final class ArchitectureImpactTest extends TestCase
{
    private function write(string $path, string $code): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $code);
        clearstatcache();
    }

    private function fixture(): void
    {
        $this->write('config/architectures.php', "<?php return ['enabled' => ['actions'], 'audit' => ['paths' => ['tests', 'routes']]];");
        $this->write('app/InvoiceCalculator.php', '<?php namespace App; final class InvoiceCalculator { public function calculate(): int { return 1; } public function estimate(): int { return 2; } }');
        $this->write('app/CreateInvoiceAction.php', '<?php namespace App; final class CreateInvoiceAction { public function __construct(private InvoiceCalculator $calculator) {} public function handle(): int { return $this->calculator->calculate(); } public function other(): int { return $this->calculator->estimate(); } }');
        $this->write('app/PreviewInvoiceAction.php', '<?php namespace App; final class PreviewInvoiceAction { public function __construct(private InvoiceCalculator $calculator) {} public function handle(): int { return $this->calculator->estimate(); } }');
        $this->write('app/InvoiceController.php', '<?php namespace App; final class InvoiceController { public function store(CreateInvoiceAction $action): int { return $action->handle(); } public function preview(CreateInvoiceAction $action): int { return $action->other(); } }');
        $this->write('tests/Feature/InvoiceTest.php', '<?php namespace Tests; final class InvoiceTest { public function testInvoice(\App\InvoiceController $controller) { $controller->store(new \App\CreateInvoiceAction(new \App\InvoiceCalculator)); } }');
    }

    private function query(string $subject, bool $cache = false, int $limit = 100, int $depth = 8): array
    {
        $files = new Filesystem;

        return (new ArchitectureImpact($files, $this->tempPath, new AuditScope(['app', 'tests', 'routes']), $cache ? new ProjectGraphCache($files, $this->tempPath) : null))->inspect($subject, limit: $limit, depth: $depth);
    }

    public function test_method_chain_is_precise_at_every_hop_and_class_context_is_separate(): void
    {
        $this->fixture();
        $result = $this->query('InvoiceCalculator::calculate');
        $this->assertTrue($result['ok']);
        $names = array_column($result['dependents'], 'symbol');
        $this->assertContains('App\CreateInvoiceAction::handle', $names);
        $this->assertContains('App\InvoiceController::store', $names);
        $this->assertNotContains('App\PreviewInvoiceAction::handle', $names);
        $this->assertNotContains('App\InvoiceController::preview', $names);
        $controller = array_values(array_filter($result['dependents'], fn ($row) => $row['symbol'] === 'App\InvoiceController::store'))[0];
        $this->assertCount(2, $controller['via']);
        foreach ($controller['via'] as $edge) {
            $this->assertFileExists($this->tempPath.'/'.$edge['path']);
            $this->assertGreaterThan(0, $edge['line']);
        }
        $this->assertContains('App\PreviewInvoiceAction', array_column($result['class_context']['dependents'], 'from'));
        $this->assertSame('class_only_not_method_call', $result['class_context']['dependents'][0]['basis']);
        $this->assertNotEmpty($result['tests']);
        $this->assertFalse($result['tests'][0]['proves_coverage']);
        $this->assertStringNotContainsString('breaking', json_encode($result));
    }

    public function test_outgoing_chains_are_method_scoped_too(): void
    {
        $this->fixture();
        $result = $this->query('InvoiceController::store');
        $names = array_column($result['dependencies'], 'symbol');
        $this->assertContains('App\CreateInvoiceAction::handle', $names);
        $this->assertContains('App\InvoiceCalculator::calculate', $names);
        $this->assertNotContains('App\InvoiceCalculator::estimate', $names);
    }

    public function test_class_file_and_case_insensitive_names_return_transitive_class_relationships(): void
    {
        $this->fixture();
        $class = $this->query('InvoiceCalculator');
        $path = $this->query($this->tempPath.'/app/InvoiceCalculator.php');
        $fqcn = $this->query('\\app\\invoicecalculator');
        $this->assertSame($class, $path);
        $this->assertSame($class, $fqcn);
        $this->assertContains('App\InvoiceController', array_column($class['dependents'], 'symbol'));
    }

    public function test_ambiguous_class_and_file_return_candidates_without_guessing(): void
    {
        $this->fixture();
        $this->write('app/Other.php', '<?php namespace Other; class InvoiceCalculator {} class Another {}');
        foreach (['InvoiceCalculator', 'app/Other.php'] as $selector) {
            $result = $this->query($selector);
            $this->assertFalse($result['ok']);
            $this->assertSame('E_IMPACT_SUBJECT_AMBIGUOUS', $result['m']);
            $this->assertCount(2, $result['candidates']);
        }
    }

    public function test_missing_method_class_invalid_method_and_out_of_scope_are_distinct(): void
    {
        $this->fixture();
        $this->write('vendor/Outside.php', '<?php class Outside {}');
        foreach (['Missing' => 'E_IMPACT_SUBJECT_NOT_FOUND', 'InvoiceCalculator::missing' => 'E_IMPACT_METHOD_NOT_FOUND', 'InvoiceCalculator::bad-name' => 'E_IMPACT_METHOD_INVALID', 'vendor/Outside.php' => 'E_IMPACT_OUT_OF_SCOPE', '' => 'E_IMPACT_SUBJECT_REQUIRED'] as $selector => $code) {
            $this->assertSame($code, $this->query($selector)['m']);
        }
    }

    public function test_static_new_typed_promoted_and_assigned_receivers_are_supported(): void
    {
        $this->fixture();
        $this->write('app/Users.php', <<<'SRC'
<?php namespace App;
final class Users {
    public InvoiceCalculator $property;
    public function typed(InvoiceCalculator $c) { $c->calculate(); }
    public function property() { $this->property->calculate(); }
    public function staticCall() { InvoiceCalculator::calculate(); }
    public function created() { $x = new InvoiceCalculator; $alias = $x; $alias->calculate(); }
    public function chained() { (new InvoiceCalculator)->calculate(); }
}
SRC);
        $names = array_column($this->query('InvoiceCalculator::calculate')['dependents'], 'symbol');
        foreach (['typed', 'property', 'staticCall', 'created', 'chained'] as $method) {
            $this->assertContains('App\Users::'.$method, $names);
        }
    }

    public function test_interfaces_and_overrides_are_possible_not_concrete_implementation_calls(): void
    {
        $this->write('app/Contracts.php', '<?php namespace App; interface CalculatorContract { public function calculate(): int; } class BaseCalculator { public function calculate(): int { return 1; } } class InvoiceCalculator extends BaseCalculator implements CalculatorContract { public function calculate(): int { return 2; } } final class ContractUser { public function run(CalculatorContract $c) { return $c->calculate(); } public function parentType(BaseCalculator $c) { return $c->calculate(); } }');
        $result = $this->query('InvoiceCalculator::calculate');
        $this->assertNotContains('App\ContractUser::run', array_column($result['dependents'], 'symbol'));
        $this->assertContains('App\ContractUser::run', array_column($result['possible']['dependents'], 'symbol'));
        $this->assertContains('App\ContractUser::parentType', array_column($result['possible']['dependents'], 'symbol'));
        $base = $this->query('BaseCalculator::calculate');
        $this->assertSame('App\InvoiceCalculator::calculate', $base['possible']['overrides'][0]['symbol']);
    }

    public function test_inherited_methods_traits_and_parent_calls_resolve_declarations(): void
    {
        $this->write('app/Family.php', '<?php namespace App; class Base { public function calculate() {} } final class Child extends Base { public function run() { parent::calculate(); } } trait CalculateTrait { public function estimate() {} } final class TraitUser { use CalculateTrait; public function run() { $this->estimate(); } }');
        $inherited = $this->query('Child::calculate');
        $this->assertSame('App\Base::calculate', $inherited['subject']['declaration']['symbol']);
        $this->assertContains('App\Child::run', array_column($inherited['dependents'], 'symbol'));
        $trait = $this->query('TraitUser::estimate');
        $this->assertSame('App\CalculateTrait::estimate', $trait['subject']['declaration']['symbol']);
        $this->assertContains('App\TraitUser::run', array_column($trait['dependents'], 'symbol'));
    }

    public function test_trait_adaptations_do_not_guess_a_declaration(): void
    {
        $this->write('app/Family.php', '<?php namespace App; trait A { public function calculate() {} } trait B { public function calculate() {} } final class Child { use A, B { A::calculate insteadof B; } }');
        $this->assertSame('E_IMPACT_METHOD_UNRESOLVED', $this->query('Child::calculate')['m']);
    }

    public function test_dynamic_receivers_and_branch_mutations_remain_incomplete(): void
    {
        $this->fixture();
        $this->write('app/Dynamic.php', <<<'SRC'
<?php namespace App;
final class Dynamic {
    public function unknown($x, $method) { $x->calculate(); $x->$method(); }
    public function changed(InvoiceCalculator $x, $flag) { if ($flag) { $x = unknown(); } $x->calculate(); }
    public function ref(InvoiceCalculator $x) { mutate($x); $x->calculate(); }
}
SRC);
        $result = $this->query('InvoiceCalculator::calculate');
        $this->assertSame('incomplete', $result['analysis']['status']);
        $this->assertNotContains('App\Dynamic::changed', array_column($result['dependents'], 'symbol'));
        $this->assertNotContains('App\Dynamic::ref', array_column($result['dependents'], 'symbol'));
        $this->assertGreaterThanOrEqual(3, $result['analysis']['notice_total']);
    }

    public function test_callable_references_do_not_propagate_as_executed_method_chains(): void
    {
        $this->fixture();
        $this->write('routes/web.php', '<?php $handler = [\App\InvoiceCalculator::class, "calculate"]; $callable = \App\InvoiceCalculator::calculate(...);');
        $result = $this->query('InvoiceCalculator::calculate');
        $this->assertContains('(file) routes/web.php', array_column($result['references']['dependents'], 'symbol'));
        $this->assertNotContains('(file) routes/web.php', array_column($result['dependents'], 'symbol'));
        $this->assertNotEmpty($result['analysis']['limitations']);
    }

    public function test_no_relationships_framework_limit_and_empty_or_truncated_results_are_distinct(): void
    {
        $this->write('app/Alone.php', '<?php namespace App; final class Alone { public function handle() {} }');
        $none = $this->query('Alone::handle');
        $this->assertSame('none', $none['analysis']['status']);
        $this->assertStringContainsString('Framework dispatch', implode(' ', $none['analysis']['limitations']));
        $this->fixture();
        $limited = $this->query('InvoiceCalculator::calculate', limit: 1, depth: 1);
        $this->assertSame('limit', $limited['analysis']['status']);
        $this->assertTrue($limited['analysis']['truncated']);
        $this->assertCount(1, $limited['dependents']);
        $zero = $this->query('InvoiceCalculator::calculate', limit: 0);
        $this->assertSame('limit', $zero['analysis']['status']);
        $this->assertSame([], $zero['dependents']);
        $this->assertSame('E_IMPACT_LIMIT_INVALID', $this->query('Alone', depth: 0)['m']);
    }

    public function test_call_cycles_are_bounded_and_output_deterministic(): void
    {
        $this->write('app/Cycle.php', '<?php namespace App; final class Cycle { public function a() { $this->b(); } public function b() { $this->a(); } }');
        $result = $this->query('Cycle::a');
        $this->assertCount(1, $result['dependencies']);
        $this->assertCount(1, $result['dependents']);
        $this->assertSame($result, $this->query('Cycle::a'));
    }

    public function test_cache_disabled_cold_warm_and_changed_deleted_sources_agree(): void
    {
        $this->fixture();
        $disabled = $this->query('InvoiceCalculator::calculate');
        $cold = $this->query('InvoiceCalculator::calculate', true);
        $warm = $this->query('InvoiceCalculator::calculate', true);
        $this->assertSame('disabled', $disabled['cache']);
        $this->assertSame('missing', $cold['cache']);
        $this->assertSame('fresh', $warm['cache']);
        unset($disabled['cache'], $cold['cache'], $warm['cache']);
        $this->assertSame($disabled, $cold);
        $this->assertSame($cold, $warm);
        $this->write('app/PreviewInvoiceAction.php', '<?php namespace App; final class PreviewInvoiceAction { public function handle(InvoiceCalculator $c) { return $c->calculate(); } }');
        $changed = $this->query('InvoiceCalculator::calculate', true);
        $this->assertContains('App\PreviewInvoiceAction::handle', array_column($changed['dependents'], 'symbol'));
        $this->assertNotSame($warm['snapshot'], $changed['snapshot']);
        unlink($this->tempPath.'/app/PreviewInvoiceAction.php');
        clearstatcache();
        $deleted = $this->query('InvoiceCalculator::calculate', true);
        $this->assertNotContains('App\PreviewInvoiceAction::handle', array_column($deleted['dependents'], 'symbol'));
    }

    public function test_impact_channel_does_not_change_class_graph_or_existing_audit_findings(): void
    {
        $this->fixture();
        $this->write('app/Actions/BadAction.php', '<?php namespace App\Actions; final class BadAction { public function first() {} public function second() {} }');
        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $before = json_decode(Artisan::output(), true);
        $this->assertNotEmpty($before['find']);
        Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $guardBefore = json_decode(Artisan::output(), true);
        $files = new Filesystem;
        $normal = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $impact = (new ProjectGraphLoader($files, $this->tempPath, impact: true))->load();
        $this->assertEquals($normal->symbols, $impact->symbols);
        $this->assertEquals($normal->edges, $impact->edges);
        $this->assertSame([], $normal->impactFacts);
        $this->assertNotEmpty($impact->impactFacts);
        $this->query('InvoiceCalculator::calculate', true);
        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $after = json_decode(Artisan::output(), true);
        $this->assertSame($before['find'], $after['find']);
        Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $guardAfter = json_decode(Artisan::output(), true);
        $this->assertSame($guardBefore['find'], $guardAfter['find']);
    }

    public function test_cli_mcp_schema_and_agent_instructions_are_consistent(): void
    {
        $this->fixture();
        Artisan::call('architecture-kit:impact', ['subject' => 'InvoiceCalculator::calculate', '--agent' => true]);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($cli['ok'], json_encode($cli));
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'InvoiceCalculator::calculate'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('subject', $cli['subject'])->where('dependents', $cli['dependents'])->where('dependencies', $cli['dependencies'])
            ->where('possible', $cli['possible'])->where('references', $cli['references'])->where('analysis', $cli['analysis'])->where('tests', $cli['tests'])->where('snapshot', $cli['snapshot'])->etc());
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['--schema' => true]));
        $schema = json_decode(Artisan::output(), true);
        $this->assertSame('impact', $schema['oneOf'][0]['properties']['cmd']['const']);
        $context = (new ArchitectureKitServer(new FakeTransporter))->createContext();
        $tool = $context->tools()->first(fn ($tool) => $tool->name() === 'impact');
        $this->assertNotNull($tool);
        $this->assertSame('string', $tool->toArray()['inputSchema']['properties']['subject']['type']);
        $this->assertStringContainsString('impact', $context->instructions);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath);
        $this->assertStringContainsString('impact', $resources->guideline([])->contents);
        $this->assertSame(1, Artisan::call('architecture-kit:impact', ['subject' => 'Missing', '--agent' => true]));
        ArchitectureKitServer::tool(Impact::class, ['subject' => 12])->assertOk()->assertStructuredContent(fn ($json) => $json->where('ok', false)->where('m', 'E_INVALID_TOOL_INPUT')->etc());
    }

    public function test_application_code_is_never_executed_for_discovery(): void
    {
        $this->write('app/Poison.php', '<?php namespace App; file_put_contents('.var_export($this->tempPath.'/executed', true).', "bad"); final class Poison { public function handle() { throw new \RuntimeException("executed"); } }');
        $this->assertTrue($this->query('Poison::handle')['ok']);
        $this->assertFileDoesNotExist($this->tempPath.'/executed');
    }

    public function test_impact_cache_rejects_malformed_facts_and_respects_low_memory_budget(): void
    {
        $this->fixture();
        $files = new Filesystem;
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: $cache, impact: true);
        $plan = $loader->plan();
        $loader->build($plan);
        $stored = $cache->read($plan->signature)->graph->toArray();
        $path = array_key_first($stored['entries']);
        $stored['entries'][$path]['i']['calls'][] = ['from' => 5];
        $this->assertNull(CachedGraph::fromArray($stored));
        $limited = new ProjectGraphCache($files, $this->tempPath, memoryLimitBytes: 1, memoryUsage: fn () => 0);
        $this->assertSame('too-large', $limited->read($plan->signature)->status->value);
        $rebuilt = (new ProjectGraphLoader($files, $this->tempPath, cache: $limited, impact: true))->load();
        $this->assertNotEmpty($rebuilt->impactFacts);
        $this->assertFalse($limited->write($cache->read($plan->signature)->graph));
    }

    public function test_classless_app_script_is_queryable_without_adding_audit_symbols(): void
    {
        $this->fixture();
        $this->write('app/script.php', '<?php $c = new \App\InvoiceCalculator; $c->calculate();');
        $result = $this->query('app/script.php');
        $this->assertTrue($result['ok']);
        $this->assertSame('file', $result['subject']['kind']);
        $this->assertContains('App\InvoiceCalculator::calculate', array_column($result['dependencies'], 'symbol'));
        $graph = (new ProjectGraphLoader(new Filesystem, $this->tempPath))->load();
        $this->assertSame([], $graph->symbolsAt('app/script.php'));
    }

    public function test_foreach_unset_reference_and_property_writes_do_not_retain_stale_types(): void
    {
        $this->fixture();
        $this->write('app/Mutations.php', <<<'SRC'
<?php namespace App;
final class Mutations {
    public InvoiceCalculator $c;
    public function iteration(InvoiceCalculator $c, $items) { foreach ($items as $c) {} $c->calculate(); }
    public function removed(InvoiceCalculator $c) { unset($c); $c->calculate(); }
    public function reference(InvoiceCalculator $c) { $ref =& $c; $ref = unknown(); $c->calculate(); }
    public function property() { $this->c = unknown(); $this->c->calculate(); }
    public function matchChange(InvoiceCalculator $c, $value) { match ($value) { ($c = unknown()) => 1, default => 0 }; $c->calculate(); }
}
SRC);
        $result = $this->query('InvoiceCalculator::calculate');
        foreach (['iteration', 'removed', 'reference', 'property', 'matchChange'] as $method) {
            $this->assertNotContains('App\Mutations::'.$method, array_column($result['dependents'], 'symbol'));
        }
        $this->assertSame('incomplete', $result['analysis']['status']);
    }

    public function test_inherited_property_type_and_union_receiver_uncertainty(): void
    {
        $this->fixture();
        $this->write('app/Inherited.php', '<?php namespace App; class PropertyBase { protected InvoiceCalculator $c; } final class Inherited extends PropertyBase { public function run() { $this->c->calculate(); } public function union(InvoiceCalculator|PropertyBase $c) { $c->calculate(); } }');
        $result = $this->query('InvoiceCalculator::calculate');
        $this->assertContains('App\Inherited::run', array_column($result['dependents'], 'symbol'));
        $this->assertNotContains('App\Inherited::union', array_column($result['dependents'], 'symbol'));
        $this->assertSame('incomplete', $result['analysis']['status']);
    }

    public function test_excluded_file_does_not_contribute_cached_calls(): void
    {
        $this->fixture();
        $files = new Filesystem;
        $query = new ArchitectureImpact($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath));
        $query->inspect('InvoiceCalculator::calculate');
        $result = $query->inspect('InvoiceCalculator::calculate', ['app/CreateInvoiceAction.php']);
        $this->assertNotContains('App\CreateInvoiceAction::handle', array_column($result['dependents'], 'symbol'));
        $this->assertSame('E_IMPACT_OUT_OF_SCOPE', $query->inspect('app/CreateInvoiceAction.php', ['app/CreateInvoiceAction.php'])['m']);
    }

    public function test_corrupt_and_missing_impact_cache_channels_rebuild_instead_of_hiding_methods(): void
    {
        $this->fixture();
        $this->query('InvoiceCalculator::calculate', true);
        $files = new Filesystem;
        $stored = $files->glob($this->tempPath.'/'.ProjectGraphCache::DIRECTORY.'/*.cache')[0];
        $files->put($stored, 'corrupt');
        $rebuilt = $this->query('InvoiceCalculator::calculate', true);
        $this->assertSame('corrupt', $rebuilt['cache']);
        $raw = $files->get($stored);
        $data = unserialize(substr($raw, strpos($raw, "\n") + 1), ['allowed_classes' => false]);
        foreach ($data['entries'] as &$entry) {
            unset($entry['i']);
        }
        unset($entry);
        $payload = serialize($data);
        $files->put($stored, hash('xxh128', $payload)."\n".$payload);
        $restored = $this->query('InvoiceCalculator::calculate', true);
        $this->assertContains('App\CreateInvoiceAction::handle', array_column($restored['dependents'], 'symbol'));
    }

    public function test_source_and_dispatch_limits_are_explicit(): void
    {
        $this->write('app/Huge.php', '<?php namespace App; final class Huge { public function handle() {} } '.str_repeat(' ', 100001));
        $result = $this->query('Huge');
        $this->assertFalse($result['ok']);
        $this->assertSame('E_IMPACT_ANALYSIS_LIMIT', $result['m']);
        $this->assertStringContainsString('size limit', $result['msg']);
    }

    public function test_cli_terminal_reports_evidence_and_integer_options(): void
    {
        $this->fixture();
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'InvoiceCalculator::calculate', '--limit' => 2, '--depth' => 2]));
        $output = Artisan::output();
        $this->assertStringContainsString('App\CreateInvoiceAction::handle', $output);
        $this->assertStringContainsString('Class context, not method calls', $output);
        $this->assertStringContainsString('not coverage or PASS', $output);
        $this->assertSame(1, Artisan::call('architecture-kit:impact', ['subject' => 'InvoiceCalculator', '--agent' => true, '--limit' => 'abc']));
        $this->assertSame('E_IMPACT_LIMIT_INVALID', json_decode(Artisan::output(), true)['m']);
    }

    public function test_named_array_keys_do_not_invent_a_callable_reference(): void
    {
        $this->fixture();
        $this->write('routes/array.php', '<?php $data = ["class" => \App\InvoiceCalculator::class, "method" => "calculate"];');
        $this->assertSame([], $this->query('InvoiceCalculator::calculate')['references']['dependents']);
    }

    public function test_possible_contract_chains_stay_possible_at_later_hops(): void
    {
        $this->write('app/Contract.php', '<?php namespace App; interface Contract { public function calculate(); } final class Calculator implements Contract { public function calculate() {} } final class Action { public function handle(Contract $c) { $c->calculate(); } } final class Controller { public function run(Action $action, Contract $c) { $action->handle($c); } }');
        $result = $this->query('Calculator::calculate');
        $this->assertContains('App\Controller::run', array_column($result['possible']['dependents'], 'symbol'));
        $this->assertNotContains('App\Controller::run', array_column($result['dependents'], 'symbol'));
    }

    public function test_concurrent_source_change_marks_report_incomplete_and_next_query_is_fresh(): void
    {
        $this->fixture();
        $root = $this->tempPath;
        $files = new class($root) extends Filesystem
        {
            private bool $changed = false;

            public function __construct(private string $root) {}

            public function get($path, $lock = false)
            {
                $contents = parent::get($path, $lock);
                if (! $this->changed && str_ends_with($path, '/app/InvoiceCalculator.php')) {
                    $this->changed = true;
                    parent::put($this->root.'/app/PreviewInvoiceAction.php', '<?php namespace App; final class PreviewInvoiceAction { public function handle(InvoiceCalculator $c) { return $c->calculate(); } }');
                    clearstatcache();
                }

                return $contents;
            }
        };
        $result = (new ArchitectureImpact($files, $root))->inspect('InvoiceCalculator::calculate');
        $this->assertSame('incomplete', $result['analysis']['status']);
        $this->assertStringContainsString('changed during', implode(' ', array_column($result['analysis']['notices'], 'reason')));
        $this->assertContains('App\PreviewInvoiceAction::handle', array_column($this->query('InvoiceCalculator::calculate')['dependents'], 'symbol'));
    }

    public function test_memory_limited_extraction_is_explicit_and_is_not_reused_at_a_larger_budget(): void
    {
        $this->fixture();
        $previous = ini_get('memory_limit');
        try {
            ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
            $limited = $this->query('InvoiceCalculator::calculate', true);
            $this->assertFalse($limited['ok']);
            $this->assertSame('E_IMPACT_ANALYSIS_LIMIT', $limited['m']);
        } finally {
            ini_set('memory_limit', $previous);
        }
        $fresh = $this->query('InvoiceCalculator::calculate', true);
        $this->assertTrue($fresh['ok']);
        $this->assertContains('App\CreateInvoiceAction::handle', array_column($fresh['dependents'], 'symbol'));
    }

    public function test_condition_assignment_precedes_body_and_loop_writes_do_not_keep_stale_types(): void
    {
        $this->write('app/A.php', '<?php namespace App; class A { public function run() {} }');
        $this->write('app/B.php', '<?php namespace App; class B { public function run() {} }');
        $this->write('app/User.php', '<?php namespace App; class User { public function branch() { $x = new A; if ($x = new B) { $x->run(); } } public function loop() { $x = new A; while ($x = new B) { $x->run(); } } public function repeated() { $x = new A; do { $x->run(); $x = new B; } while (true); } }');
        $a = $this->query('A::run');
        $b = $this->query('B::run');
        $this->assertNotContains('App\\User::branch', array_column($a['dependents'], 'symbol'));
        $this->assertContains('App\\User::branch', array_column($b['dependents'], 'symbol'));
        $this->assertNotContains('App\\User::loop', array_column($a['dependents'], 'symbol'));
        $this->assertNotContains('App\\User::repeated', array_column($a['dependents'], 'symbol'));
        $this->assertNotEmpty($a['analysis']['notices']);
    }

    public function test_new_static_in_parent_includes_possible_child_override(): void
    {
        $this->write('app/BaseFactory.php', '<?php namespace App; class BaseFactory { public function make() { $x = new static; $x->run(); } public function run() {} }');
        $this->write('app/ChildFactory.php', '<?php namespace App; class ChildFactory extends BaseFactory { public function run() {} }');
        $result = $this->query('ChildFactory::run');
        $this->assertContains('App\\BaseFactory::make', array_column($result['possible']['dependents'], 'symbol'));
        $this->assertNotContains('App\\BaseFactory::make', array_column($result['dependents'], 'symbol'));
    }

    public function test_long_hierarchy_reports_boundary_instead_of_complete_absence(): void
    {
        $this->write('app/Level0.php', '<?php namespace App; class Level0 { public function run() {} }');
        for ($i = 1; $i <= 15; $i++) {
            $this->write('app/Level'.$i.'.php', '<?php namespace App; class Level'.$i.' extends Level'.($i - 1).' { '.($i === 15 ? 'public function run() {}' : '').' }');
        }
        $this->write('app/User.php', '<?php namespace App; class User { public function call(Level0 $x) { $x->run(); } }');
        $result = $this->query('Level15::run');
        $this->assertSame('limit', $result['analysis']['status']);
        $notices = array_values(array_filter($result['analysis']['notices'], fn ($notice) => str_contains($notice['reason'], 'hierarchy depth limit')));
        $this->assertNotEmpty($notices);
        $this->assertStringStartsWith('app/Level', $notices[0]['path']);
        $inherited = $this->query('Level14::run');
        $this->assertFalse($inherited['ok']);
        $this->assertSame('E_IMPACT_ANALYSIS_LIMIT', $inherited['m']);
    }

    public function test_dense_large_source_is_skipped_before_read_or_parse_and_never_claims_missing(): void
    {
        $this->write('app/Dense.php', '<?php namespace App; class Dense { public function run() {'.str_repeat('$x = new Dense;', 30000).'} }');
        $root = $this->tempPath;
        $files = new class extends Filesystem
        {
            public function get($path, $lock = false)
            {
                if (str_ends_with($path, '/Dense.php')) {
                    throw new \LogicException('Oversized source must not be read.');
                }

                return parent::get($path, $lock);
            }
        };
        foreach (['Dense::run', 'app/Dense.php'] as $subject) {
            $result = (new ArchitectureImpact($files, $root))->inspect($subject);
            $this->assertFalse($result['ok']);
            $this->assertSame('E_IMPACT_ANALYSIS_LIMIT', $result['m']);
        }
        $entry = (new ProjectGraphBuilder(impact: true))->collect(new FileContext('app/Invalid.php', '<?php invalid '.str_repeat('x', 100001)));
        $this->assertStringContainsString('source size limit', $entry->impact->notices[0]['reason']);
    }

    public function test_test_candidates_stream_sources_and_report_skipped_files_outside_graph(): void
    {
        $this->write('app/Target.php', '<?php namespace App; class Target { public function run() {} }');
        $this->write('app/User.php', '<?php namespace App; class User { public function call(Target $x) { $x->run(); } }');
        $this->write('tests/Direct.php', '<?php new \App\Target;');
        $this->write('tests/Indirect.php', '<?php new \App\User;');
        $this->write('tests/Huge.php', '<?php '.str_repeat('$x = new \App\Target;', 10000));
        $result = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('Target::run', limit: 100);
        $this->assertSame('limit', $result['analysis']['status']);
        $this->assertContains('tests/Direct.php', array_column($result['tests'], 'path'));
        $this->assertContains('tests/Indirect.php', array_column($result['tests'], 'path'));
        $this->assertNotContains('tests/Huge.php', array_column($result['tests'], 'path'));
        $this->assertStringContainsString('Test candidate analysis limit', implode(' ', array_column($result['analysis']['notices'], 'reason')));
    }

    public function test_loop_aliases_and_call_arguments_invalidate_receivers_before_the_first_body_visit(): void
    {
        $this->write('app/A.php', '<?php namespace App; class A { public function run() {} }');
        $this->write('app/B.php', '<?php namespace App; class B { public function run() {} }');
        $this->write('app/User.php', '<?php namespace App; class User { public function alias($flag) { $x = new A; do { $x->run(); $alias =& $x; $alias = new B; } while ($flag); } public function argument($flag) { $x = new A; do { $x->run(); mutate($x); } while ($flag); } public function unsetLoop($flag) { $x = new A; do { $x->run(); unset($x); } while ($flag); } public function destructure($flag) { $x = new A; do { $x->run(); [$x] = values(); } while ($flag); } }');
        $result = $this->query('A::run');
        foreach (['alias', 'argument', 'unsetLoop', 'destructure'] as $method) {
            $this->assertNotContains('App\\User::'.$method, array_column($result['dependents'], 'symbol'));
            $this->assertContains('App\\User::'.$method, array_column($result['analysis']['notices'], 'from'));
        }
        $this->assertSame('incomplete', $result['analysis']['status']);
    }
}
