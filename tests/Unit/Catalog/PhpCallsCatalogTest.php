<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogKinds;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PHPUnit\Framework\TestCase;

final class PhpCallsCatalogTest extends TestCase
{
    public function test_factory_returned_by_value_callback_preserves_argument_type(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
function factory() { return function ($worker) {}; }
function entry() {
    $worker = new Worker;
    $callback = factory();
    $callback($worker);
    $worker->perform();
}
PHP);
        $this->assertContains('App\\Worker::perform', $this->targets($index, 'App\\entry'));
    }

    public function test_factory_returned_reference_or_unknown_callback_does_not_preserve_argument_type(): void
    {
        foreach ([
            'return function (&$worker) {};',
            'if ($condition) { return function ($worker) {}; } return unknown();',
            'return unknown();',
        ] as $body) {
            $index = $this->index('namespace App; class Worker { public function perform() {} } '
                .'function factory(bool $condition = false): \\Closure { '.$body.' } '
                .'function entry() { $worker = new Worker; $callback = factory(); $callback($worker); $worker->perform(); }');
            $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\entry'), $body);
        }
    }

    public function test_factory_returned_callbacks_keep_source_signature_and_creation_scope(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
function accept($worker) {}
function replace(&$worker) {}
function namedFactory() { return accept(...); }
function arrowFactory() { return fn ($worker) => null; }
function nestedFactory() { return arrowFactory(); }
function mixedFactory(bool $condition = false) { if ($condition) { return accept(...); } return replace(...); }
function alternativeFactory(bool $condition = false) { if ($condition) { return accept(...); } return function ($worker) {}; }
function generatorFactory() { yield null; return accept(...); }
class Factory {
    private function accept($worker) {}
    private function replace(&$worker) {}
    public function safe() { return $this->accept(...); }
    public function unsafe() { return $this->replace(...); }
    public function nested() { return function () { return $this->accept(...); }; }
    private function hidden() { return accept(...); }
}
function named() { $worker = new Worker; $callback = namedFactory(); $callback($worker); $worker->perform(); }
function arrow() { $worker = new Worker; $callback = arrowFactory(); $callback(worker: $worker); $worker->perform(); }
function nested() { $worker = new Worker; $callback = nestedFactory(); $callback($worker); $worker->perform(); }
function alternatives() { $worker = new Worker; $callback = alternativeFactory(); $callback($worker); $worker->perform(); }
function privateBound() { $worker = new Worker; $callback = (new Factory)->safe(); $callback($worker); $worker->perform(); }
function nestedPrivateBound() { $worker = new Worker; $factory = (new Factory)->nested(); $callback = $factory(); $callback($worker); $worker->perform(); }
function privateReference() { $worker = new Worker; $callback = (new Factory)->unsafe(); $callback($worker); $worker->perform(); }
function inaccessibleFactory() { $worker = new Worker; $callback = (new Factory)->hidden(); $callback($worker); $worker->perform(); }
function mixed() { $worker = new Worker; $callback = mixedFactory(); $callback($worker); $worker->perform(); }
function generator() { $worker = new Worker; $callback = generatorFactory(); $callback($worker); $worker->perform(); }
PHP);
        foreach (['named', 'arrow', 'nested', 'alternatives', 'privateBound', 'nestedPrivateBound'] as $name) {
            $this->assertContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
        foreach (['privateReference', 'inaccessibleFactory', 'mixed', 'generator'] as $name) {
            $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
    }

    public function test_invokable_and_bound_method_factories_preserve_only_proven_callback_arguments(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
class Maker { public function __invoke() { return fn ($worker) => null; } }
class ReferenceMaker { public function __invoke() { return function (&$worker) {}; } }
class UnknownMaker { public function __invoke($condition = false) { if ($condition) { return fn ($worker) => null; } return unknown(); } }
class MethodMaker {
    private function make() { return fn ($worker) => null; }
    private function replace() { return function (&$worker) {}; }
    public function safe() { return $this->make(...); }
    public function unsafe() { return $this->replace(...); }
}
function objectFactory() { return new Maker; }
function wrappedObjectFactory() { return objectFactory(); }
function referenceFactory() { return new ReferenceMaker; }
function unknownFactory() { return new UnknownMaker; }
function mixedFactory($condition = false) { if ($condition) { return new Maker; } return unknown(); }
function wrappedMixedFactory() { return mixedFactory(); }
function methodFactory() { return (new MethodMaker)->safe(); }
function referenceMethodFactory() { return (new MethodMaker)->unsafe(); }
function objectValue() { $worker = new Worker; $factory = objectFactory(); $callback = $factory(); $callback($worker); $worker->perform(); }
function wrappedObjectValue() { $worker = new Worker; $factory = wrappedObjectFactory(); $callback = $factory(); $callback($worker); $worker->perform(); }
function objectReference() { $worker = new Worker; $factory = referenceFactory(); $callback = $factory(); $callback($worker); $worker->perform(); }
function unknownValue() { $worker = new Worker; $factory = unknownFactory(); $callback = $factory(); $callback($worker); $worker->perform(); }
function wrappedUnknownValue() { $worker = new Worker; $factory = wrappedMixedFactory(); $callback = $factory(); $callback($worker); $worker->perform(); }
function boundValue() { $worker = new Worker; $factory = methodFactory(); $callback = $factory(); $callback(worker: $worker); $worker->perform(); }
function boundReference() { $worker = new Worker; $factory = referenceMethodFactory(); $callback = $factory(); $callback($worker); $worker->perform(); }
PHP);
        foreach (['objectValue', 'boundValue', 'wrappedObjectValue'] as $name) {
            $this->assertContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
        foreach (['objectReference', 'unknownValue', 'boundReference', 'wrappedUnknownValue'] as $name) {
            $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
    }

    public function test_factory_callback_proof_has_bounded_return_sites_and_recursion(): void
    {
        $prefix = 'namespace App; class Worker { public function perform() {} } function factory($condition = false) { ';
        $suffix = ' } function entry() { $worker = new Worker; $callback = factory(); $callback($worker); $worker->perform(); }';
        $branch = 'if ($condition) { return function ($worker) {}; } ';
        $boundary = $this->index($prefix.str_repeat($branch, 127).'return function ($worker) {};'.$suffix);
        $this->assertContains('App\\Worker::perform', $this->targets($boundary, 'App\\entry'));
        $limited = $this->index($prefix.str_repeat($branch, 128).'return function ($worker) {};'.$suffix);
        $this->assertNotContains('App\\Worker::perform', $this->targets($limited, 'App\\entry'));
        $this->assertContains('Callable return summary reached its node, return count or memory budget.', array_column($limited->diagnostics, 'message'));

        $source = 'namespace App; class Worker { public function perform() {} } function factory0() { return function ($worker) {}; } ';
        for ($level = 1; $level <= 17; $level++) {
            $source .= 'function factory'.$level.'() { return factory'.($level - 1).'(); } ';
        }
        $deep = $this->index($source.'function entry() { $worker = new Worker; $callback = factory17(); $callback($worker); $worker->perform(); }');
        $this->assertNotContains('App\\Worker::perform', $this->targets($deep, 'App\\entry'));
        $this->assertContains('dispatch_limit', array_column($deep->diagnostics, 'code'));

        $source = 'namespace App; class Worker { public function perform() {} } function factory0() { return function ($worker) {}; } ';
        for ($level = 1; $level <= 3; $level++) {
            $source .= 'function factory'.$level.'($condition = false) { '
                .str_repeat('if ($condition) { return factory'.($level - 1).'(); } ', 29)
                .'return factory'.($level - 1).'(); } ';
        }
        $wide = $this->index($source.'function entry() { $worker = new Worker; $callback = factory3(); $callback($worker); $worker->perform(); }');
        $this->assertNotContains('App\\Worker::perform', $this->targets($wide, 'App\\entry'));
        $this->assertContains('dispatch_limit', array_column($wide->diagnostics, 'code'));
    }

    public function test_argument_flow_limits_are_explicit_and_do_not_invent_downstream_calls(): void
    {
        $prefix = 'namespace App; class Worker { public function perform() {} } function accept(...$workers) {} function entry() { $worker = new Worker; accept(';
        $suffix = '); $worker->perform(); }';
        $boundary = $this->index($prefix.implode(',', ['$worker', ...array_fill(0, 127, 'null')]).$suffix);
        $this->assertContains('App\\Worker::perform', $this->targets($boundary, 'App\\entry'));

        $countLimited = $this->index($prefix.implode(',', ['$worker', ...array_fill(0, 128, 'null')]).$suffix);
        $this->assertNotContains('App\\Worker::perform', $this->targets($countLimited, 'App\\entry'));
        $this->assertContains('Argument value flow exceeded its argument count budget.', array_column($countLimited->diagnostics, 'message'));

        $arguments = ['$worker'];
        for ($position = 0; $position < 100; $position++) {
            $arguments[] = 'argument'.$position.str_repeat('x', 100).': null';
        }
        $sizeLimited = $this->index($prefix.implode(',', $arguments).$suffix);
        $this->assertNotContains('App\\Worker::perform', $this->targets($sizeLimited, 'App\\entry'));
        $this->assertContains('Argument value flow descriptor exceeded its source budget.', array_column($sizeLimited->diagnostics, 'message'));
    }

    public function test_callback_and_static_trait_signatures_preserve_only_by_value_arguments(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
class Receiver { public function __invoke($worker) {} }
class ReferenceReceiver { public function __invoke(&$worker) {} }
class PublicReceiver { public function accept($worker) {} }
class ReferenceMethodReceiver { public function accept(&$worker) {} }
trait Accepts { public static function accept($worker) {} }
class Consumer { use Accepts; public static function replace(&$worker) {} }
class BoundReceiver {
    private function accept($worker) {}
    private function replace(&$worker) {}
    public function safe() { $worker = new Worker; $callback = $this->accept(...); $callback($worker); $worker->perform(); }
    public function unsafe() { $worker = new Worker; $callback = $this->replace(...); $callback($worker); $worker->perform(); }
}
function accept($worker) {}
function closureValue() { $worker = new Worker; $callback = function ($worker) {}; $copy = $callback; $copy($worker); $worker->perform(); }
function arrowValue() { $worker = new Worker; $callback = fn ($worker) => null; $callback($worker); $worker->perform(); }
function closureReference() { $worker = new Worker; $callback = function (&$worker) {}; $callback($worker); $worker->perform(); }
function functionReference() { $worker = new Worker; $callback = accept(...); $callback($worker); $worker->perform(); }
function invokableValue() { $worker = new Worker; $callback = new Receiver; $callback($worker); $worker->perform(); }
function invokableReference() { $worker = new Worker; $callback = new ReferenceReceiver; $callback($worker); $worker->perform(); }
function staticTraitValue() { $worker = new Worker; Consumer::accept($worker); $worker->perform(); }
function staticReference() { $worker = new Worker; Consumer::replace($worker); $worker->perform(); }
function staticCallableValue() { $worker = new Worker; $callback = Consumer::accept(...); $callback($worker); $worker->perform(); }
function staticCallableReference() { $worker = new Worker; $callback = Consumer::replace(...); $callback($worker); $worker->perform(); }
function instanceCallableValue() { $worker = new Worker; $callback = (new PublicReceiver)->accept(...); $copy = $callback; $copy(worker: $worker); $worker->perform(); }
function ambiguousCallableReference(PublicReceiver|ReferenceMethodReceiver $receiver) { $worker = new Worker; $callback = $receiver->accept(...); $callback($worker); $worker->perform(); }
function inaccessibleCallable() { $worker = new Worker; $callback = (new BoundReceiver)->accept(...); $callback($worker); $worker->perform(); }
PHP);
        foreach (['closureValue', 'arrowValue', 'functionReference', 'invokableValue', 'staticTraitValue', 'staticCallableValue', 'instanceCallableValue', 'BoundReceiver::safe'] as $name) {
            $this->assertContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
        foreach (['closureReference', 'invokableReference', 'staticReference', 'staticCallableReference', 'ambiguousCallableReference', 'inaccessibleCallable', 'BoundReceiver::unsafe'] as $name) {
            $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
    }

    public function test_argument_type_preservation_does_not_ignore_reference_captures_or_assignment_aliases(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
function invoke($worker, $callback) { $callback(); }
function mixed($worker, &$other) { $other = null; }
function captured() {
    $worker = new Worker;
    $callback = function () use (&$worker) { $worker = null; };
    invoke($worker, $callback);
    $worker->perform();
}
function aliased() {
    $worker = new Worker;
    $other =& $worker;
    $worker = new Worker;
    mixed($worker, $other);
    $worker->perform();
}
PHP);
        $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\captured'));
        $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\aliased'));
        $this->assertContains('Argument type preservation requires unresolved reference or global alias analysis.', array_column($index->diagnostics, 'message'));
    }

    public function test_mixed_parameter_modes_preserve_only_slots_that_cannot_receive_the_variable_by_reference(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
function mixed($worker, &$status) { $status = null; }
function referenceFirst(&$status, $worker) { $status = null; }
function variadic($worker, &...$statuses) {}
function valueVariadic(&$status, ...$workers) {}
function safePositional() { $worker = new Worker; $status = new Worker; mixed($worker, $status); $worker->perform(); $status->perform(); }
function safeReordered() { $worker = new Worker; $status = new Worker; mixed(status: $status, worker: $worker); $worker->perform(); $status->perform(); }
function safeAfterReference() { $worker = new Worker; $status = new Worker; referenceFirst($status, $worker); $worker->perform(); }
function duplicated() { $worker = new Worker; mixed($worker, $worker); $worker->perform(); }
function variadicValue() { $worker = new Worker; $status = new Worker; valueVariadic($status, extra: $worker); $worker->perform(); }
function variadicReference() { $worker = new Worker; variadic(null, extra: $worker); $worker->perform(); }
function duplicateVariadic() { $worker = new Worker; variadic($worker, extra: $worker); $worker->perform(); }
PHP);
        foreach (['safePositional', 'safeReordered', 'safeAfterReference', 'variadicValue'] as $name) {
            $calls = array_filter($this->targets($index, 'App\\'.$name), fn ($target) => $target === 'App\\Worker::perform');
            $this->assertCount(1, $calls, $name);
        }
        foreach (['duplicated', 'variadicReference', 'duplicateVariadic'] as $name) {
            $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name), $name);
        }
    }

    public function test_by_value_source_signatures_preserve_argument_types_but_reference_and_unknown_calls_do_not(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Worker { public function perform() {} }
class Consumer {
    public function accept($worker) {}
    public function replace(&$worker) { $worker = null; }
}
function accept($worker) {}
function replace(&$worker) { $worker = null; }
function safeFunction() { $worker = new Worker; accept(worker: $worker); $worker->perform(); }
function safeMethod(Consumer $consumer) { $worker = new Worker; $consumer->accept($worker); $worker->perform(); }
function unsafeFunction() { $worker = new Worker; replace($worker); $worker->perform(); }
function unsafeMethod(Consumer $consumer) { $worker = new Worker; $consumer->replace($worker); $worker->perform(); }
function unknownFunction() { $worker = new Worker; unknown($worker); $worker->perform(); }
function wrongName() { $worker = new Worker; accept(other: $worker); $worker->perform(); }
function unpacked() { $worker = new Worker; accept(...$worker); $worker->perform(); }
PHP);
        foreach (['safeFunction', 'safeMethod'] as $name) {
            $this->assertContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name));
        }
        foreach (['unsafeFunction', 'unsafeMethod', 'unknownFunction', 'wrongName', 'unpacked'] as $name) {
            $this->assertNotContains('App\\Worker::perform', $this->targets($index, 'App\\'.$name));
        }
    }

    public function test_source_instance_origins_distinguish_constructions_and_survive_only_proved_local_copies(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
class Worker {}
function run(Worker $parameter, $condition) {
    $first = new Worker;
    $copy = $first;
    $second = new Worker;
    $first->first();
    $copy->copy();
    $second->second();
    $first = new Worker;
    $first->reassigned();
    $parameter->parameter();
    (new Worker)->direct();
    consume($copy);
    $copy->afterUnknownCall();
    $cloned = clone $second;
    $cloned->afterClone();
    if ($condition) { $second = new Worker; }
    $second->afterBranch();
}
PHP;
        $file = new FileContext('app/Example.php', $source);
        $facts = (new ProjectGraphBuilder(catalog: true))->build([$file])->catalogFacts;
        $local = $facts['app/Example.php'] ?? array_values($facts)[0];
        $roundtrip = CatalogFacts::fromArray($local->path, $local->toArray());
        $this->assertEquals($local, $roundtrip);
        $calls = [];
        $creations = [];
        foreach ($roundtrip->relations as $relation) {
            if ($relation->kind !== 'calls') {
                continue;
            }
            if ($relation->metadata['form'] === 'new') {
                $creations[] = $relation->metadata['end_offset'];
            } else {
                $calls[$relation->metadata['method']] = $relation->metadata;
            }
        }
        $this->assertCount(5, $creations);
        $this->assertSame($creations[0], $calls['first']['receiver_instance_origin']);
        $this->assertSame($creations[0], $calls['copy']['receiver_instance_origin']);
        $this->assertSame($creations[1], $calls['second']['receiver_instance_origin']);
        $this->assertSame($creations[2], $calls['reassigned']['receiver_instance_origin']);
        $this->assertSame($creations[3], $calls['direct']['receiver_instance_origin']);
        $this->assertArrayNotHasKey('receiver_instance_origin', $calls['parameter']);
        $this->assertStringStartsWith('@after-argument:', $calls['afterUnknownCall']['receiver']);
        $index = new CatalogIndex([$roundtrip]);
        $this->assertNotContains('App\\Worker::afterUnknownCall', $this->targets($index, 'App\\run'));
        $this->assertArrayNotHasKey('afterClone', $calls);
        $this->assertArrayNotHasKey('afterBranch', $calls);
        foreach (['first', 'copy', 'second', 'reassigned', 'direct'] as $method) {
            $this->assertArrayNotHasKey('receiver_origin', $calls[$method]);
        }
        $legacy = (new ImpactExtractor)->extract(new FileContext('app/Legacy.php', '<?php namespace App; class Worker {} class Caller { public function run() { $worker = new Worker; $worker->run(); } }'));
        $this->assertNotEmpty($legacy->calls);
        foreach ($legacy->calls as $call) {
            $this->assertArrayNotHasKey('receiver_instance_origin', $call);
        }
    }

    private function index(string $source): CatalogIndex
    {
        return new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build([new FileContext('app/Example.php', '<?php '.$source)])->catalogFacts);
    }

    /** @return list<string> */
    private function targets(CatalogIndex $index, string $from, string $kind = 'calls'): array
    {
        $ids = $index->names[strtolower($from)] ?? [];

        return array_values(array_filter(array_map(fn ($edge) => $index->elements[$edge['to']]['name'] ?? null,
            array_filter($index->relations, fn ($edge) => in_array($edge['from'], $ids, true) && $edge['kind'] === $kind)), 'is_string'));
    }

    public function test_assigned_returned_callables_keep_value_flow_and_creator_evidence(): void
    {
        $index = $this->index('namespace App; class Worker { private function run() {} public function factory(): \\Closure { $callback = $this->run(...); return $callback; } } function delegated() { $callback = (new Worker)->factory(); return $callback; } function invoked() { $callback = delegated(); $callback(); } function referenceOnly() { delegated(); }');
        $this->assertSame(['App\\delegated', 'App\\Worker::run'], $this->targets($index, 'App\\invoked'));
        $this->assertSame(['App\\delegated'], $this->targets($index, 'App\\referenceOnly'));
        $invoked = $index->names['app\\invoked'][0];
        $edge = array_values(array_filter($index->relations, fn ($row) => $row['from'] === $invoked && ($index->elements[$row['to']]['name'] ?? null) === 'App\\Worker::run'))[0];
        $this->assertSame(['App\\delegated', 'App\\Worker::factory'], array_map(fn ($row) => $index->elements[$row['producer']]['name'], $edge['metadata']['return_sources']));
        $this->assertSame('App\\Worker::factory', $index->elements[$edge['metadata']['bound_callable']['creator']]['name']);
        $this->assertFalse($edge['metadata']['execution_proven']);
        $this->assertContains('returns-value', CatalogKinds::STRUCTURAL_RELATIONS);
    }

    public function test_returned_closure_body_is_only_linked_when_value_is_invoked(): void
    {
        $index = $this->index('namespace App; class Worker { public function run() {} } function factory(): \\Closure { $callback = function () { (new Worker)->run(); }; return $callback; } function invoked() { $callback = factory(); $callback(); } function referenceOnly() { $callback = factory(); }');
        $closure = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'closure'))[0];
        $this->assertSame(['App\\factory', $closure['name']], $this->targets($index, 'App\\invoked'));
        $this->assertSame(['App\\factory'], $this->targets($index, 'App\\referenceOnly'));
        $this->assertSame(['App\\Worker::run'], $this->targets($index, $closure['name']));
    }

    public function test_recursive_returned_value_reports_budget_without_inventing_a_callable(): void
    {
        $index = $this->index('namespace App; function recursive() { return recursive(); } function invoked() { $callback = recursive(); $callback(); }');
        $this->assertContains('dispatch_limit', array_column($index->diagnostics, 'code'));
        $this->assertSame(['App\\recursive'], $this->targets($index, 'App\\invoked'));
    }

    public function test_trait_precedence_alias_and_class_override_resolve_to_real_declarations(): void
    {
        $index = $this->index('namespace App; trait First { public function run() {} } trait Second { public function run() {} } class Worker { use First, Second { First::run insteadof Second; Second::run as other; run as protected; } } class OverrideWorker extends Worker { public function run() {} } function entry() { (new Worker)->run(); (new Worker)->other(); (new OverrideWorker)->run(); }');
        $this->assertSame(['App\\Second::run', 'App\\OverrideWorker::run'], $this->targets($index, 'App\\entry'));
        $this->assertContains('inaccessible_dispatch', array_column($index->diagnostics, 'code'));
    }

    public function test_inaccessible_abstract_and_wrong_static_calls_do_not_reach_method_effects(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Effects { public static function write() {} }
abstract class Worker {
    private function hidden() { Effects::write(); }
    protected function guarded() { Effects::write(); }
    public function instanceOnly() { Effects::write(); }
    private function factory(): Effects { return new Effects; }
    abstract public function pending();
    public function internal() { $this->hidden(); $this->guarded(); self::instanceOnly(); }
    public static function invalidStatic() { self::instanceOnly(); }
}
class Child extends Worker { public function pending() {} public function allowed() { $this->guarded(); parent::instanceOnly(); } }
function blocked(Worker $worker) { $worker->hidden(); $worker->guarded(); Worker::instanceOnly(); Worker::pending(); $worker->factory()->write(); }
PHP);
        $this->assertSame([], $this->targets($index, 'App\\blocked'));
        $this->assertSame([], $this->targets($index, 'App\\Worker::invalidStatic'));
        $this->assertSame(['App\\Worker::hidden', 'App\\Worker::guarded', 'App\\Worker::instanceOnly'], $this->targets($index, 'App\\Worker::internal'));
        $this->assertSame(['App\\Worker::guarded', 'App\\Worker::instanceOnly'], $this->targets($index, 'App\\Child::allowed'));
        $this->assertNotEmpty($this->targets($index, 'App\\blocked', 'references-call'));
        $query = new GraphQuery($index);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names['app\\blocked'][0], 'path', $index->names['app\\effects::write'][0], 8)['status']);
    }

    public function test_method_callable_creation_scope_and_static_form_are_preserved(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
class Effects { public static function write() {} }
class Worker {
    private function hidden() {}
    public function instanceOnly() { Effects::write(); }
    public function factory() { return $this->hidden(...); }
    public function boundFactory() { return Worker::hidden(...); }
    public function invalidInside() { $callback = [Worker::class, 'instanceOnly']; $callback(); }
}
function entry() { $callback = (new Worker)->factory(); $callback(); }
function invalid() { $callback = [Worker::class, 'instanceOnly']; $callback(); }
function boundEntry() { $callback = (new Worker)->boundFactory(); $callback(); }
PHP);
        $this->assertSame(['App\\Worker::factory', 'App\\Worker::hidden'], $this->targets($index, 'App\\entry'));
        $this->assertSame([], $this->targets($index, 'App\\invalid'));
        $this->assertSame([], $this->targets($index, 'App\\Worker::invalidInside'));
        $this->assertSame(['App\\Worker::boundFactory', 'App\\Worker::hidden'], $this->targets($index, 'App\\boundEntry'));
        $query = new GraphQuery($index);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names['app\\worker::invalidinside'][0], 'path', $index->names['app\\effects::write'][0], 8)['status']);
    }

    public function test_standalone_functions_and_aliases_have_calls_with_function_owners(): void
    {
        $index = $this->index('namespace App; use function App\\helper as delegated; class Service { public function run() {} } function helper(Service $service) { $service->run(); } function entry(Service $service) { delegated($service); }');
        $this->assertSame(['App\\Service::run'], $this->targets($index, 'App\\helper'));
        $this->assertSame(['App\\helper'], $this->targets($index, 'App\\entry'));
        $this->assertSame([], $index->diagnostics);
    }

    public function test_closure_body_is_separate_and_only_invocation_links_the_parent_to_it(): void
    {
        $index = $this->index('namespace App; class Service { public function run() {} } function definition(Service $service) { $callback = function () use ($service) { $service->run(); }; } function invocation(Service $service) { $callback = function () use ($service) { $service->run(); }; $callback(); }');
        $closures = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'closure'));
        $this->assertCount(2, $closures);
        $this->assertSame(['App\\Service::run'], $this->targets($index, $closures[0]['name']));
        $this->assertSame(['App\\Service::run'], $this->targets($index, $closures[1]['name']));
        $this->assertSame([], $this->targets($index, 'App\\definition'));
        $this->assertSame([$closures[1]['name']], $this->targets($index, 'App\\invocation'));
        $this->assertSame([], $index->diagnostics);
    }

    public function test_branch_conflicts_and_reference_capture_do_not_keep_a_stale_receiver_type(): void
    {
        $index = $this->index('namespace App; class A { public function run() {} } class B { public function run() {} } function branch($condition) { $value = new A; if ($condition) { $value = new B; } $value->run(); } function capture(A $value) { $callback = function () use (&$value) { $value->run(); }; $value = new B; $callback(); }');
        $this->assertSame([], $this->targets($index, 'App\\branch'));
        $closure = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'closure'))[0];
        $this->assertSame([], $this->targets($index, $closure['name']));
        $this->assertContains('unresolved_call', array_column($index->diagnostics, 'code'));
    }

    public function test_properties_promoted_properties_and_return_types_resolve_across_declarations(): void
    {
        $index = $this->index('namespace App; class Service { public function run() {} } class Holder { public function __construct(public Service $service) {} public function resolve(): Service { return new Service; } } function factory(): Holder { return new Holder(new Service); } function flow(Holder $holder) { $holder->service->run(); $holder->resolve()->run(); factory()->resolve()->run(); }');
        $this->assertSame(['App\\Service::run', 'App\\Holder::resolve', 'App\\Service::run', 'App\\factory', 'App\\Holder::resolve', 'App\\Service::run'], $this->targets($index, 'App\\flow'));
        $this->assertSame([], $index->diagnostics);
    }

    public function test_union_types_and_virtual_dispatch_keep_source_candidates_conditional(): void
    {
        $index = $this->index('namespace App; interface Port { public function run(); } class A implements Port { public function run() {} } class B implements Port { public function run() {} } function union(A|B $service) { $service->run(); } function virtual(Port $port) { $port->run(); }');
        $this->assertSame(['App\\A::run', 'App\\B::run'], $this->targets($index, 'App\\union'));
        $this->assertSame(['App\\Port::run', 'App\\A::run', 'App\\B::run'], $this->targets($index, 'App\\virtual'));
        foreach (array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls') as $edge) {
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_first_class_callable_references_are_not_calls_until_invoked(): void
    {
        $index = $this->index('namespace App; class Service { public static function run() {} } function helper() {} function referenceOnly() { $value = Service::run(...); $function = helper(...); } function invocation() { $value = Service::run(...); $value(); $function = helper(...); $function(); }');
        $this->assertSame([], $this->targets($index, 'App\\referenceOnly'));
        $this->assertSame(['App\\Service::run', 'App\\helper'], $this->targets($index, 'App\\referenceOnly', 'callable-reference'));
        $this->assertSame(['App\\Service::run', 'App\\helper'], $this->targets($index, 'App\\invocation'));
        $this->assertSame([], $index->diagnostics);
    }

    public function test_anonymous_classes_and_invokable_objects_keep_their_own_scopes(): void
    {
        $index = $this->index('namespace App; class Target { public static function run() {} } class Invocation { public function __invoke() { Target::run(); } } function entry() { $object = new class { public function run() { Target::run(); } }; $object->run(); $callable = new Invocation; $callable(); }');
        $anonymousMethod = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'method' && str_starts_with($row['name'], '(anonymous)')))[0];
        $this->assertSame(['App\\Target::run'], $this->targets($index, $anonymousMethod['name']));
        $this->assertSame([$anonymousMethod['name'], 'App\\Invocation::__invoke'], $this->targets($index, 'App\\entry'));
    }

    public function test_catalog_mode_does_not_change_the_default_impact_extractor_contract(): void
    {
        $file = new FileContext('app/Example.php', '<?php namespace App; function helper() {} class Service { public function run() { $callback = fn () => helper(); } }');
        $extractor = new ImpactExtractor;
        $before = $extractor->extract($file)->toArray();
        $catalog = $extractor->extract($file, catalog: true);
        $this->assertNotEmpty($catalog->calls);
        $this->assertSame($before, $extractor->extract($file)->toArray());
        $this->assertSame([], $before['calls']);
        $this->assertCount(2, $before['notices']);
    }

    public function test_literal_source_returns_resolve_objects_and_callbacks_without_executing_factories(): void
    {
        $index = $this->index('namespace App; class Service { public function run() {} } function factory() { return new Service; } function callbackFactory() { return fn () => factory()->run(); } function entry() { factory()->run(); $callback = callbackFactory(); $callback(); }');
        $closure = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'closure'))[0];
        $this->assertSame(['App\\factory', 'App\\Service::run', 'App\\callbackFactory', $closure['name']], $this->targets($index, 'App\\entry'));
        $this->assertSame(['App\\factory', 'App\\Service::run'], $this->targets($index, $closure['name']));
        $this->assertSame([], $index->diagnostics);
    }

    public function test_external_inherited_constructor_is_not_reported_as_a_known_implicit_constructor(): void
    {
        $index = $this->index('namespace App; class Plain {} class ExternalChild extends \\Vendor\\Base {} class Grandchild extends ExternalChild {} class OverrideChild extends \\Vendor\\Base { public function __construct() {} } function entry() { new Plain; new ExternalChild; new Grandchild; new OverrideChild; }');
        $constructed = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'constructs'));
        $this->assertCount(3, $constructed);
        $this->assertTrue($constructed[0]['metadata']['implicit_constructor']);
        $this->assertSame('resolved', $constructed[0]['resolution']);
        foreach (array_slice($constructed, 1) as $edge) {
            $this->assertFalse($edge['metadata']['implicit_constructor']);
            $this->assertTrue($edge['metadata']['constructor_unresolved']);
            $this->assertSame('conditional', $edge['resolution']);
        }
        $this->assertContains('App\\OverrideChild::__construct', $this->targets($index, 'App\\entry'));
        $this->assertCount(2, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'unresolved_constructor'));
    }
}
