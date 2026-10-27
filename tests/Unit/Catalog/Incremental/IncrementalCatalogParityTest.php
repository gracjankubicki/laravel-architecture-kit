<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class IncrementalCatalogParityTest extends IncrementalFixture
{
    public function test_real_patch_recomputes_unchanged_dependent_and_reuses_independent_call(): void
    {
        $files = ['app/A.php' => '<?php namespace App; class A { public function apply() {} }',
            'app/Caller.php' => '<?php namespace App; class Caller { public function run(A $a) { $a->apply(); $a->apply(); } }',
            'app/Independent.php' => '<?php namespace App; class Independent { public function other() {} public function run() { $this->other(); } }',
            'tests/AOnlyTest.php' => '<?php class AOnlyTest extends \\PHPUnit\\Framework\\TestCase { public function test_a() { (new \\App\\A)->apply(); } }'];
        $base = $this->prepare($files);
        $old = $base->exportState();
        $this->assertOracle($base, $files, ['App\\A::apply', 'App\\Caller::run']);
        $files['app/A.php'] = '<?php namespace App; class A { public function replaced() {} }';
        $next = $this->update($base, $files, ['app/A.php' => $files['app/A.php']]);
        $this->assertOracle($next, $files, ['App\\A::apply', 'App\\Caller::run']);
        $this->assertSame($old, $base->exportState());
        $trace = $next->measurements['trace'];
        $this->assertSame(['resolve', 'resolve'], array_column(array_values(array_filter($trace, fn ($row) => $row['path'] === 'app/Caller.php')), 'action'));
        $this->assertSame(['reuse'], array_column(array_values(array_filter($trace, fn ($row) => $row['path'] === 'app/Independent.php')), 'action'));
        $this->assertSame($base->hashes()['app/Caller.php'], $next->hashes()['app/Caller.php']);
        $this->assertContains('unresolved_dispatch', array_column($next->exportState()['diagnostics'], 'code'));
        $this->assertNotEmpty((new GraphQuery($base->index()))->query('App\\A::apply', 'impact')['records']);
    }

    public function test_parent_trait_alias_property_descriptor_and_basic_registrations(): void
    {
        $files = ['app/ParentType.php' => '<?php namespace App; class ParentType { public function act() {} }',
            'app/Feature.php' => '<?php namespace App; trait Feature { public function feature() {} }',
            'app/Child.php' => '<?php namespace App; class Child extends ParentType { use Feature { feature as alias; } }',
            'app/Holder.php' => '<?php namespace App; class Holder { public Child $child; }',
            'app/Caller.php' => '<?php namespace App; class Caller { public function run(Child $c, Holder $h) { $c->act(); $c->alias(); $h->child->act(); } }',
            'app/Provider.php' => '<?php namespace App; class Provider extends \Illuminate\Support\ServiceProvider { public function register() { $this->app->bind(Child::class, ParentType::class); } }'];
        $generation = $this->prepare($files);
        $this->assertOracle($generation, $files, ['App\\Child', 'App\\Caller::run']);
        foreach (['app/ParentType.php' => '<?php namespace App; class ParentType { protected function act() {} }',
            'app/Child.php' => '<?php namespace App; class Child extends ParentType { use Feature { feature as renamed; } }',
            'app/Holder.php' => '<?php namespace App; class Holder { public ParentType $child; }',
            'app/Provider.php' => '<?php namespace App; class Provider extends \Illuminate\Support\ServiceProvider { public function register() { $this->app->singleton(Child::class, ParentType::class); } }'] as $path => $source) {
            $files[$path] = $source;
            $generation = $this->update($generation, $files, [$path => $source]);
            $this->assertOracle($generation, $files, ['App\\Child', 'App\\Caller::run', 'App\\ParentType::act']);
        }
        $this->assertSame('basic', $generation->index()->mode->value);
        $this->assertNotEmpty(array_filter($generation->exportState()['relations'], fn ($row) => str_starts_with($row['to'], 'container:')));
    }

    public function test_add_delete_move_sources_and_reclassification_start_from_original_facts(): void
    {
        $files = ['app/Services/Thing.php' => '<?php namespace App; class Thing implements \Illuminate\Contracts\Queue\ShouldQueue { public function run() {} }',
            'app/Caller.php' => '<?php namespace App; function call(Thing $t) { $t->run(); }',
            'tests/ThingTest.php' => '<?php class ThingTest extends \PHPUnit\Framework\TestCase { public function test_run() { (new \App\Thing)->run(); } }',
            'composer.json' => '{"require":{"laravel/framework":"^13.0"},"autoload":{"psr-4":{"Modules\\\\":"Modules/"}}}'];
        $generation = $this->prepare($files);
        $this->assertOracle($generation, $files, ['App\\Thing', 'App\\Thing::run']);
        $source = '<?php namespace App; class Thing { public function run() {} }';
        unset($files['app/Services/Thing.php']);
        $files['Modules/Sales/Thing.php'] = $source;
        $generation = $this->update($generation, $files, ['Modules/Sales/Thing.php' => $source], ['app/Services/Thing.php']);
        $this->assertOracle($generation, $files, ['App\\Thing', 'App\\Thing::run']);
        $thing = $generation->index()->elements[$generation->index()->namedTypes('App\\Thing')[0]];
        $this->assertNotContains('service', $thing['roles']);
        $this->assertArrayNotHasKey('queueable', $thing['metadata']);
        $this->assertSame('Modules/Sales/Thing.php', $thing['path']);
        foreach (range(1, 3) as $_) {
            $generation = $this->update($generation, $files, []);
            $this->assertOracle($generation, $files, ['App\\Thing']);
        }
        unset($files['Modules/Sales/Thing.php']);
        $generation = $this->update($generation, $files, [], ['Modules/Sales/Thing.php']);
        $this->assertOracle($generation, $files, ['App\\Thing', 'App\\call']);
        $this->assertSame([], $generation->index()->namedTypes('App\\Thing'));
    }

    public function test_basic_reverse_adjacency_updates_direct_indirect_users_and_restores(): void
    {
        $files = ['app/DiscountService.php' => '<?php namespace App; class DiscountService { public function apply() {} }',
            'app/OrderService.php' => '<?php namespace App; class OrderService { public function place(DiscountService $s) { $s->apply(); } }',
            'app/OrderController.php' => '<?php namespace App; class OrderController { public function store(OrderService $s, DiscountService $d) { $s->place($d); } }',
            'app/helper.php' => '<?php namespace App; function helper(DiscountService $s) { $s->apply(); }'];
        $original = $files['app/DiscountService.php'];
        $generation = $this->prepare($files);
        foreach (['<?php namespace App; class DiscountService { public function changed() {} }', $original] as $source) {
            $files['app/DiscountService.php'] = $source;
            $generation = $this->update($generation, $files, ['app/DiscountService.php' => $source]);
            $this->assertOracle($generation, $files, ['App\\DiscountService::apply', 'App\\OrderService::place']);
        }
        $query = (new GraphQuery($generation->index()))->query('App\\DiscountService::apply', 'impact');
        $users = array_column(array_column($query['records'], 'element'), 'name');
        $this->assertContains('App\\OrderService::place', $users);
        $this->assertContains('App\\OrderController::store', $users);
        $this->assertContains('App\\helper', $users);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pinned_real_package_sources_use_the_production_patch_and_full_oracle(): void
    {
        $files = [];
        foreach (['CatalogFacts', 'CatalogRelation', 'CatalogDiagnostic', 'CatalogSelector', 'CatalogTraitSelection', 'ReceiverDescriptors', 'CatalogPackageVersions', 'Incremental/CatalogContribution', 'Incremental/CatalogIdentity'] as $name) {
            $path = 'src/Catalog/'.$name.'.php';
            $files[$path] = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        }
        $base = $this->prepare($files);
        $this->assertOracle($base, $files, ['GracjanKubicki\\ArchitectureKit\\Catalog\\CatalogFacts::toArray']);
        $this->assertGreaterThan(5, count($base->operations));
        $path = 'src/Catalog/CatalogFacts.php';
        $files[$path] = str_replace('function toArray()', 'function renamedArray()', $files[$path]);
        $next = $this->update($base, $files, [$path => $files[$path]]);
        $this->assertOracle($next, $files, ['GracjanKubicki\\ArchitectureKit\\Catalog\\CatalogFacts::toArray', 'GracjanKubicki\\ArchitectureKit\\Catalog\\CatalogFacts::renamedArray']);
        $dependent = array_values(array_filter($next->measurements['trace'], fn ($row) => $row['path'] === 'src/Catalog/Incremental/CatalogContribution.php'));
        $this->assertContains('resolve', array_column($dependent, 'action'));
        $this->assertGreaterThan(0, $next->measurements['reused_calls']);
    }
}
