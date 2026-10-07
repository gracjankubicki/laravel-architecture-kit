<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use PHPUnit\Framework\TestCase;

final class HttpCatalogTest extends TestCase
{
    /** @param array<string, string> $sources */
    private function index(array $sources): CatalogIndex
    {
        $files = [];
        foreach ($sources as $path => $source) {
            $files[] = new FileContext($path, '<?php '.$source);
        }

        return new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts);
    }

    /** @return list<array<string, mixed>> */
    private function routes(CatalogIndex $index): array
    {
        return array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route'));
    }

    public function test_final_inherited_method_and_trait_alias_prevent_provider_helper_override(): void
    {
        foreach ([
            'class Base extends \\Illuminate\\Support\\ServiceProvider { final protected function selected() {} }',
            'trait Loads { protected function original() {} } class Base extends \\Illuminate\\Support\\ServiceProvider { use Loads { original as final selected; } }',
        ] as $base) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Base.php' => 'namespace App; '.$base,
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { public function boot() { $this->selected(); } protected function selected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $registered = array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
            $this->assertSame([], $registered);
            $this->assertContains('http_provider_final_override', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_final_trait_alias_does_not_make_original_method_final(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Base.php' => 'namespace App; trait Loads { protected function selected() {} } class Base extends \\Illuminate\\Support\\ServiceProvider { use Loads { selected as final locked; } }',
            'app/Chosen.php' => 'namespace App; class Chosen extends Base { public function boot() { $this->selected(); } protected function selected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
            'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
        ]);
        $registered = array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
        $this->assertSame(['/selected'], $registered);
        $this->assertNotContains('http_provider_final_override', array_column($index->diagnostics, 'code'));
    }

    public function test_provider_extending_final_source_class_is_not_activated(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Base.php' => 'namespace App; final class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
            'app/Chosen.php' => 'namespace App; class Chosen extends Base {}',
            'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
        ]);
        $registered = array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
        $this->assertSame([], $registered);
        $this->assertContains('http_provider_final_parent', array_column($index->diagnostics, 'code'));
    }

    public function test_trait_alias_collision_with_original_method_remains_ambiguous(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Chosen.php' => 'namespace App; trait First { protected function first() { $this->loadRoutesFrom(__DIR__."/../routes/first.php"); } } trait Second { protected function selected() { $this->loadRoutesFrom(__DIR__."/../routes/second.php"); } } class Chosen extends \\Illuminate\\Support\\ServiceProvider { use First, Second { First::first as selected; } public function boot() { $this->selected(); } }',
            'routes/first.php' => '\\Illuminate\\Support\\Facades\\Route::get("first", fn () => 1);',
            'routes/second.php' => '\\Illuminate\\Support\\Facades\\Route::get("second", fn () => 1);',
        ]);
        $this->assertContains('http_provider_trait_ambiguous', array_column($index->diagnostics, 'code'));
        $this->assertContains('trait_method_ambiguous', array_column($index->diagnostics, 'code'));
        foreach ($this->routes($index) as $route) {
            $this->assertTrue($route['metadata']['activation_unknown']);
            $this->assertFalse($route['metadata']['execution_proven']);
        }
    }

    public function test_unrelated_provider_object_does_not_allow_protected_or_private_helper_calls(): void
    {
        foreach (['public' => true, 'protected' => false, 'private' => false] as $visibility => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Chosen.php' => 'namespace App; class Chosen extends \\Illuminate\\Support\\ServiceProvider { public function boot() { (new Other)->loadSelected(); } }',
                'app/Other.php' => 'namespace App; class Other extends \\Illuminate\\Support\\ServiceProvider { '.$visibility.' function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $registered = array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
            $this->assertSame($expected ? ['/selected'] : [], $registered);
            $this->assertSame(! $expected, in_array('http_provider_method_inaccessible', array_column($index->diagnostics, 'code'), true));
        }
    }

    public function test_overridden_provider_factory_does_not_activate_the_hidden_base_returned_callable(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function callback() { return $this->baseSelected(...); } private function baseSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
            'app/Chosen.php' => 'namespace App; class Chosen extends Base { public function boot() { $callback = $this->callback(); $callback(); } public function callback() { return $this->childSelected(...); } private function childSelected() { $this->loadRoutesFrom(__DIR__."/../routes/child.php"); } }',
            'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
            'routes/child.php' => '\\Illuminate\\Support\\Facades\\Route::get("child", fn () => 1);',
        ]);
        $registered = array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
        $this->assertSame(['/child'], $registered);
    }

    public function test_returned_trait_callable_keeps_the_factory_consuming_class(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Creates.php' => 'namespace App; trait Creates { public function callback() { return self::loadSelected(...); } }',
            'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { use Creates; protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
            'app/Chosen.php' => 'namespace App; class Chosen extends Base { public function boot() { $callback = $this->callback(); $callback(); } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/child.php"); } }',
            'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
            'routes/child.php' => '\\Illuminate\\Support\\Facades\\Route::get("child", fn () => 1);',
        ]);
        $registered = array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
        $this->assertSame(['/base'], $registered);
    }

    public function test_returned_bound_provider_callable_keeps_creator_private_scope(): void
    {
        foreach ([
            'return $this->loadSelected(...);' => ['/base'],
            '$callback = $this->loadSelected(...); return $callback;' => ['/base'],
            '$callback = [$this, "loadSelected"]; return $callback;' => ['/child'],
            '$callback = $this->loadSelected(...); $callback = unknown(); return $callback;' => [],
        ] as $factory => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function callback() { '.$factory.' } private function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { public function boot() { $callback = $this->callback(); $callback(); } private function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/child.php"); } }',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/child.php' => '\\Illuminate\\Support\\Facades\\Route::get("child", fn () => 1);',
            ]);
            $registered = array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
            $this->assertSame($expected, $registered, $factory);
        }
    }

    public function test_bound_callable_captured_by_invoked_closure_keeps_creator_scope(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $callback = $this->loadSelected(...); $invoke = function () use ($callback) { $callback(); }; $invoke(); } private function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
            'app/Chosen.php' => 'namespace App; class Chosen extends Base { private function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/child.php"); } }',
            'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
            'routes/child.php' => '\\Illuminate\\Support\\Facades\\Route::get("child", fn () => 1);',
        ]);
        $registered = array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
        $this->assertSame(['/base'], $registered);
    }

    public function test_provider_receiver_alias_loads_routes_only_while_bound_to_this(): void
    {
        foreach ([
            '$provider = $this; $provider->loadRoutesFrom(__DIR__."/../routes/selected.php");' => true,
            '$provider = $this; $callback = function () use ($provider) { $provider->loadRoutesFrom(__DIR__."/../routes/selected.php"); }; $callback();' => true,
            '$provider = $this; $provider = new Other; $provider->loadRoutesFrom(__DIR__."/../routes/selected.php");' => false,
            '$provider = $this; $alias =& $provider; $provider->loadRoutesFrom(__DIR__."/../routes/selected.php");' => false,
            '$provider = $this; if (unknown()) { $provider = new Other; } $provider->loadRoutesFrom(__DIR__."/../routes/selected.php");' => false,
            '$provider = $this; $callback = function () use ($provider) { $provider->loadRoutesFrom(__DIR__."/../routes/selected.php"); };' => false,
            '$provider = $this; $provider->loadRoutesFrom(...);' => false,
        ] as $body => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Provider.php' => 'namespace App; class Other {} class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { '.$body.' } }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($expected ? ['/selected'] : [], $registered, $body);
        }
    }

    public function test_registered_provider_inheritance_loads_source_routes_and_rejects_decoy_methods(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provider.php"); } }',
            'app/Provider.php' => 'namespace App; class Provider extends Base {}',
            'app/Decoy.php' => 'namespace App; class Decoy { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/decoy.php"); } }',
            'routes/provider.php' => '\\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);',
            'routes/decoy.php' => '\\Illuminate\\Support\\Facades\\Route::get("orphan", fn () => 1);',
        ]);
        $provided = array_values(array_filter($this->routes($index), fn ($row) => $row['metadata']['uri'] === '/provided'));
        $this->assertNotEmpty($provided);
        $registrations = array_column($provided[0]['metadata']['registration'], 'path');
        $this->assertContains('bootstrap/providers.php', $registrations);
        $this->assertContains('app/Base.php', $registrations);
        $orphan = array_values(array_filter($this->routes($index), fn ($row) => $row['metadata']['uri'] === '/orphan'));
        $this->assertCount(1, $orphan);
        $this->assertNotContains('app/Decoy.php', array_column($orphan[0]['metadata']['registration'], 'path'));
        $this->assertContains('http_provider_contract', array_column($index->diagnostics, 'code'));
    }

    public function test_provider_boot_override_and_parent_call_select_reachable_loaders(): void
    {
        foreach ([false, true] as $callParent) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/parent.php"); } }',
                'app/Provider.php' => 'namespace App; class Provider extends Base { public function boot() { '.($callParent ? 'parent::boot();' : '').' $this->loadOwnRoutes(); } protected function loadOwnRoutes() { $this->loadRoutesFrom(__DIR__."/../routes/own.php"); } protected function unused() { $this->loadRoutesFrom(__DIR__."/../routes/unused.php"); } }',
                'routes/parent.php' => '\\Illuminate\\Support\\Facades\\Route::get("parent", fn () => 1);',
                'routes/own.php' => '\\Illuminate\\Support\\Facades\\Route::get("own", fn () => 1);',
                'routes/unused.php' => '\\Illuminate\\Support\\Facades\\Route::get("unused", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            sort($registered);
            $this->assertSame($callParent ? ['/own', '/parent'] : ['/own'], $registered);
        }
    }

    public function test_reimported_trait_keeps_parent_and_child_consumption_contexts(): void
    {
        foreach (['parent::boot(); $this->traitBoot();' => ['/base', '/chosen'], 'parent::boot();' => ['/base'], '$this->traitBoot();' => ['/chosen']] as $body => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Boots.php' => 'namespace App; trait Boots { public function boot() { self::loadSelected(); } }',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { use Boots; protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { use Boots { boot as traitBoot; } public function boot() { '.$body.' } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/chosen.php' => '\\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            sort($registered);
            $this->assertSame($expected, $registered, $body);
        }
    }

    public function test_bound_method_callable_invocation_selects_the_registered_provider(): void
    {
        foreach (['$callback = $this->loadSelected(...); $callback();' => ['/chosen'],
            '$callback = [$this, "loadSelected"]; $callback();' => ['/chosen'],
            '$callback = static::loadSelected(...); $callback();' => ['/chosen'],
            '$callback = self::loadSelected(...); $callback();' => ['/base'],
            '$callback = $this->loadSelected(...);' => [],
            '$callback = $this->loadSelected(...); mutate($callback); $callback();' => []] as $body => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { '.$body.' } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }',
                'app/Sibling.php' => 'namespace App; class Sibling extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/sibling.php"); } }',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/chosen.php' => '\\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);',
                'routes/sibling.php' => '\\Illuminate\\Support\\Facades\\Route::get("sibling", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($expected, $registered, $body);
        }
    }

    public function test_provider_callback_direct_route_loading_requires_an_invocation_path(): void
    {
        foreach (['$callback = function () { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); }; $callback();' => true,
            '$callback = fn () => $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); $callback();' => true,
            '(function () { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); })();' => true,
            '$callback = function () { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); };' => false,
            '$callback = static function () { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); }; $callback();' => false] as $body => $invoked) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { '.$body.' } }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($invoked ? ['/selected'] : [], $registered, $body);
        }
    }

    public function test_provider_this_alias_and_invoked_bound_closure_select_the_registered_override(): void
    {
        foreach (['$alias = $this; $alias->loadSelected();', '$callback = function () { $this->loadSelected(); }; $callback();', '$callback = fn () => $this->loadSelected(); $callback();', '$callback = function () { $this->loadSelected(); };'] as $body) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { '.$body.' } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }',
                'app/Sibling.php' => 'namespace App; class Sibling extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/sibling.php"); } }',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/chosen.php' => '\\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);',
                'routes/sibling.php' => '\\Illuminate\\Support\\Facades\\Route::get("sibling", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame(str_contains($body, '$callback =') && ! str_contains($body, '$callback();') ? [] : ['/chosen'], $registered, $body);
        }
    }

    public function test_provider_this_binding_is_lost_after_reassignment_reference_or_static_closure(): void
    {
        foreach (['$alias = $this; $alias = new OtherProvider; $alias->loadSelected();' => ['/other'],
            '$alias = $this; mutate($alias); $alias->loadSelected();' => [],
            '$alias = $this; $callback = function () use (&$alias) { $alias->loadSelected(); }; $callback();' => [],
            '$callback = static function () { $this->loadSelected(); }; $callback();' => []] as $body => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { '.$body.' } public function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
                'app/OtherProvider.php' => 'namespace App; class OtherProvider extends \\Illuminate\\Support\\ServiceProvider { public function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/other.php"); } }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
                'routes/other.php' => '\\Illuminate\\Support\\Facades\\Route::get("other", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($expected, $registered, $body);
        }
    }

    public function test_private_trait_alias_is_callable_in_its_consumer_but_not_its_child(): void
    {
        foreach ([false, true] as $childBoot) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Loads.php' => 'namespace App; trait Loads { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { use Loads { loadSelected as private loadPrivate; } public function boot() { $this->loadPrivate(); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { '.($childBoot ? 'public function boot() { $this->loadPrivate(); }' : '').' }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($childBoot ? [] : ['/selected'], $registered);
            if ($childBoot) {
                $this->assertContains('http_provider_method_inaccessible', array_column($index->diagnostics, 'code'));
            }
        }
    }

    public function test_child_parent_call_cannot_activate_a_private_base_helper(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Chosen::class];',
            'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { private function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
            'app/Chosen.php' => 'namespace App; class Chosen extends Base { public function boot() { parent::loadSelected(); } }',
            'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
        ]);
        $registered = array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
        $this->assertSame([], $registered);
        $this->assertContains('http_provider_method_inaccessible', array_column($index->diagnostics, 'code'));
    }

    public function test_abstract_provider_registration_is_rejected_but_concrete_inheritor_can_load_routes(): void
    {
        foreach (['Base' => false, 'Concrete' => true] as $registered => $valid) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\'.$registered.'::class];',
                'app/Base.php' => 'namespace App; abstract class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }',
                'app/Concrete.php' => 'namespace App; class Concrete extends Base {}',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $routes = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($valid ? ['/selected'] : [], $routes);
            $this->assertSame(! $valid, in_array('http_provider_uninstantiable', array_column($index->diagnostics, 'code'), true));
        }
    }

    public function test_framework_does_not_activate_nonpublic_or_abstract_provider_boot(): void
    {
        foreach (['private function boot() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); }', 'abstract public function boot();'] as $method) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { '.$method.' }',
                'routes/selected.php' => '\\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);',
            ]);
            $registered = array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
            $this->assertSame([], $registered);
            $this->assertContains('http_provider_method_inaccessible', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_provider_trait_cycles_and_depth_limits_are_explicit(): void
    {
        $cycle = $this->index([
            'bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use First; }',
            'app/First.php' => 'namespace App; trait First { use Second; }',
            'app/Second.php' => 'namespace App; trait Second { use First; }',
        ]);
        $this->assertContains('http_provider_inheritance_cycle', array_column($cycle->diagnostics, 'code'));
        $this->assertSame([], $this->routes($cycle));
        $sources = ['bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use T0; }'];
        for ($i = 0; $i < 36; $i++) {
            $sources['app/T'.$i.'.php'] = 'namespace App; trait T'.$i.' { '.($i === 35 ? 'public function boot() {}' : 'use T'.($i + 1).';').' }';
        }
        $limited = $this->index($sources);
        $this->assertContains('http_composition_limit', array_column($limited->diagnostics, 'code'));
        $this->assertSame([], $this->routes($limited));
    }

    public function test_trait_self_parent_and_static_helpers_use_the_consuming_provider_class(): void
    {
        foreach (['self' => '/base', 'parent' => '/ancestor', 'static' => '/chosen'] as $receiver => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Boots.php' => 'namespace App; trait Boots { public function boot() { '.$receiver.'::loadSelected(); } } trait Outer { use Boots; }',
                'app/Ancestor.php' => 'namespace App; class Ancestor extends \\Illuminate\\Support\\ServiceProvider { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/ancestor.php"); } }',
                'app/Base.php' => 'namespace App; class Base extends Ancestor { use Outer; protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }',
                'routes/ancestor.php' => '\\Illuminate\\Support\\Facades\\Route::get("ancestor", fn () => 1);',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/chosen.php' => '\\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame([$expected], $registered, $receiver);
        }
    }

    public function test_trait_parent_outside_source_is_explicit_and_does_not_activate_its_other_helpers(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Boots.php' => 'namespace App; trait Boots { public function boot() { parent::loadSelected(); } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/unused.php"); } }',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use Boots; }',
            'routes/unused.php' => '\\Illuminate\\Support\\Facades\\Route::get("unused", fn () => 1);',
        ]);
        $registered = array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
        $this->assertSame([], $registered);
        $this->assertContains('http_provider_trait_parent_unknown', array_column($index->diagnostics, 'code'));
    }

    public function test_provider_trait_precedence_and_alias_select_source_method_bodies(): void
    {
        foreach (['$this->loadSelected();' => ['/first'], '$this->loadSecond();' => ['/second']] as $call => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use First, Second { First::loadSelected insteadof Second; Second::loadSelected as protected loadSecond; } public function boot() { '.$call.' } }',
                'app/First.php' => 'namespace App; trait First { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/first.php"); } }',
                'app/Second.php' => 'namespace App; trait Second { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/second.php"); } }',
                'routes/first.php' => '\\Illuminate\\Support\\Facades\\Route::get("first", fn () => 1);',
                'routes/second.php' => '\\Illuminate\\Support\\Facades\\Route::get("second", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($expected, $registered, $call);
            $this->assertNotContains('http_provider_trait_ambiguous', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_provider_trait_conflict_remains_explicit_instead_of_selecting_arbitrarily(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use First, Second; public function boot() { $this->loadSelected(); } }',
            'app/First.php' => 'namespace App; trait First { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/first.php"); } }',
            'app/Second.php' => 'namespace App; trait Second { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/second.php"); } }',
            'routes/first.php' => '\\Illuminate\\Support\\Facades\\Route::get("first", fn () => 1);',
            'routes/second.php' => '\\Illuminate\\Support\\Facades\\Route::get("second", fn () => 1);',
        ]);
        $this->assertContains('http_provider_trait_ambiguous', array_column($index->diagnostics, 'code'));
        foreach ($this->routes($index) as $route) {
            $this->assertTrue($route['metadata']['activation_unknown']);
            $this->assertFalse($route['metadata']['execution_proven']);
        }
    }

    public function test_provider_static_self_and_parent_helpers_keep_distinct_dispatch(): void
    {
        foreach (['static' => '/chosen', 'self' => '/base', 'parent' => '/ancestor'] as $receiver => $expected) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Ancestor.php' => 'namespace App; class Ancestor extends \\Illuminate\\Support\\ServiceProvider { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/ancestor.php"); } }',
                'app/Base.php' => 'namespace App; class Base extends Ancestor { public function boot() { '.$receiver.'::loadSelected(); } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }',
                'app/Sibling.php' => 'namespace App; class Sibling extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/sibling.php"); } }',
                'routes/ancestor.php' => '\\Illuminate\\Support\\Facades\\Route::get("ancestor", fn () => 1);',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/chosen.php' => '\\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);',
                'routes/sibling.php' => '\\Illuminate\\Support\\Facades\\Route::get("sibling", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame([$expected], $registered, $receiver);
        }
    }

    public function test_registered_provider_selects_virtual_helpers_and_preserves_private_lexical_helpers(): void
    {
        foreach ([false, true] as $private) {
            $index = $this->index([
                'bootstrap/providers.php' => 'return [App\\Chosen::class];',
                'app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadSelected(); } '.($private ? 'private' : 'protected').' function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }',
                'app/Chosen.php' => 'namespace App; class Chosen extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }',
                'app/Sibling.php' => 'namespace App; class Sibling extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/sibling.php"); } }',
                'routes/base.php' => '\\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);',
                'routes/chosen.php' => '\\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);',
                'routes/sibling.php' => '\\Illuminate\\Support\\Facades\\Route::get("sibling", fn () => 1);',
            ]);
            $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
            $this->assertSame($private ? ['/base'] : ['/chosen'], $registered);
        }
    }

    public function test_provider_trait_helper_is_reachable_and_first_class_reference_does_not_activate_loader(): void
    {
        $index = $this->index([
            'bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Helpers.php' => 'namespace App; trait Helpers { protected function loadOwnRoutes() { $this->loadRoutesFrom(__DIR__."/../routes/own.php"); } protected function unused() { $this->loadRoutesFrom(__DIR__."/../routes/unused.php"); } }',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use Helpers; public function boot() { $this->loadOwnRoutes(); $unused = $this->unused(...); } public function register() {} }',
            'routes/own.php' => '\\Illuminate\\Support\\Facades\\Route::get("own", fn () => 1);',
            'routes/unused.php' => '\\Illuminate\\Support\\Facades\\Route::get("unused", fn () => 1);',
        ]);
        $registered = array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
        $this->assertSame(['/own'], $registered);
    }

    public function test_with_providers_fluent_registration_preserves_source_evidence(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withProviders([App\\Provider::class])->create();',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provider.php"); } }',
            'routes/provider.php' => '\\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);',
        ]);
        $routes = $this->routes($index);
        $this->assertCount(1, $routes);
        $this->assertSame(['bootstrap/app.php', 'app/Provider.php', 'routes/provider.php'], array_column($routes[0]['metadata']['registration'], 'path'));
    }

    public function test_custom_provider_loading_method_and_dynamic_registration_do_not_prove_loading(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withProviders($dynamic)->create();',
            'bootstrap/providers.php' => 'return [App\\Provider::class, External\\MissingProvider::class];',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function loadRoutesFrom($path) {} public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/orphan.php"); } }',
            'routes/orphan.php' => '\\Illuminate\\Support\\Facades\\Route::get("orphan", fn () => 1);',
        ]);
        $routes = $this->routes($index);
        $this->assertCount(1, $routes);
        $this->assertNotContains('app/Provider.php', array_column($routes[0]['metadata']['registration'], 'path'));
        $this->assertContains('http_provider_override', array_column($index->diagnostics, 'code'));
        $this->assertContains('http_missing_provider', array_column($index->diagnostics, 'code'));
        $this->assertContains('Dynamic withProviders registration is unresolved.', array_column($index->diagnostics, 'message'));
    }

    public function test_bootstrap_provider_setting_and_fluent_routing_keep_explicit_providers(): void
    {
        foreach ([false, true] as $enabled) {
            $index = $this->index([
                'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withProviders([App\\Explicit::class], withBootstrapProviders: false)'.($enabled ? '->withProviders([], true)' : '').'->withRouting(web: __DIR__."/../routes/web.php")->create();',
                'bootstrap/providers.php' => 'return [App\\Bootstrapped::class];',
                'app/Explicit.php' => 'namespace App; class Explicit extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/explicit.php"); } }',
                'app/Bootstrapped.php' => 'namespace App; class Bootstrapped extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/bootstrap.php"); } }',
                'routes/explicit.php' => '\\Illuminate\\Support\\Facades\\Route::get("explicit", fn () => 1);',
                'routes/bootstrap.php' => '\\Illuminate\\Support\\Facades\\Route::get("bootstrap", fn () => 1);',
                'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::get("web", fn () => 1);',
            ]);
            $routes = $this->routes($index);
            $bootstrap = array_filter($routes, fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
            $this->assertCount($enabled ? 1 : 0, $bootstrap);
            $explicit = array_filter($routes, fn ($row) => $row['metadata']['uri'] === '/explicit' && in_array('bootstrap/app.php', array_column($row['metadata']['registration'], 'path'), true));
            $this->assertCount(1, $explicit);
            $web = array_filter($routes, fn ($row) => $row['metadata']['uri'] === '/web' && in_array('bootstrap/app.php', array_column($row['metadata']['registration'], 'path'), true));
            $this->assertCount(1, $web);
        }
    }

    public function test_provider_callable_reference_and_non_provider_class_do_not_register_providers(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withProviders([App\\Decoy::class], false); $reference = \\Illuminate\\Foundation\\Application::configure()->withProviders(...);',
            'bootstrap/providers.php' => 'return [App\\Provider::class];',
            'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provided.php"); } }',
            'app/Decoy.php' => 'namespace App; class Decoy { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/decoy.php"); } }',
            'routes/provided.php' => '\\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);',
            'routes/decoy.php' => '\\Illuminate\\Support\\Facades\\Route::get("decoy", fn () => 1);',
        ]);
        $registered = array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true));
        $this->assertSame([], $registered);
        foreach ($this->routes($index) as $route) {
            $this->assertNotContains('bootstrap/app.php', array_column($route['metadata']['registration'], 'path'));
        }
        $this->assertContains('http_provider_contract', array_column($index->diagnostics, 'code'));
        $this->assertContains('http_bootstrap_providers_disabled', array_column($index->diagnostics, 'code'));
    }

    public function test_dynamic_or_conditional_bootstrap_provider_setting_is_not_a_definite_disable(): void
    {
        foreach (['withProviders(withBootstrapProviders: $dynamic)', 'withProviders([], false)'] as $setting) {
            $source = str_contains($setting, '$dynamic') ? '\\Illuminate\\Foundation\\Application::configure()->'.$setting.';' : 'if ($condition) { \\Illuminate\\Foundation\\Application::configure()->'.$setting.'; }';
            $index = $this->index([
                'bootstrap/app.php' => $source,
                'bootstrap/providers.php' => 'return [App\\Provider::class];',
                'app/Provider.php' => 'namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provided.php"); } }',
                'routes/provided.php' => '\\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);',
            ]);
            $routes = array_values(array_filter($this->routes($index), fn ($row) => in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)));
            $this->assertCount(1, $routes);
            $this->assertContains('Bootstrap provider-file registration depends on a dynamic or conditional withProviders setting.', $routes[0]['metadata']['reasons']);
        }
    }

    public function test_cross_file_routing_context_preserves_prefix_name_middleware_and_inherited_handler(): void
    {
        $index = $this->index([
            'bootstrap/app.php' => 'use Illuminate\\Foundation\\Application; Application::configure()->withRouting(api: __DIR__."/../routes/api.php", apiPrefix: "v1");',
            'routes/api.php' => 'use Illuminate\\Support\\Facades\\Route as R; R::prefix("billing")->name("billing.")->middleware("auth")->group(__DIR__."/extra.php");',
            'routes/extra.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("invoices", [App\\Controller::class, "index"])->name("invoices");',
            'app/Controller.php' => 'namespace App; class ParentController { public function index() {} } class Controller extends ParentController {}',
        ]);
        $routes = $this->routes($index);
        $this->assertCount(1, $routes);
        $route = $routes[0];
        $this->assertSame('/v1/billing/invoices', $route['metadata']['uri']);
        $this->assertSame('billing.invoices', $route['metadata']['route_name']);
        $this->assertSame(['api', 'auth'], $route['metadata']['middleware']);
        $this->assertSame(['bootstrap/app.php', 'routes/api.php', 'routes/extra.php'], array_column($route['metadata']['registration'], 'path'));
        $handlers = array_values(array_filter($index->relations, fn ($row) => $row['from'] === $route['id'] && $row['kind'] === 'route-handler'));
        $this->assertCount(1, $handlers);
        $this->assertSame('App\\ParentController::index', $index->elements[$handlers[0]['to']]['name']);
        $this->assertSame('routes/extra.php', $handlers[0]['path']);
    }

    public function test_closure_invokable_and_resource_handlers_keep_real_body_scopes_and_omit_payloads(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("callback", fn () => App\\Service::run("payload-secret")); Route::post("invokable", App\\Controller::class); Route::apiResource("items", App\\ResourceController::class)->only(["index", "store"]);',
            'app/Types.php' => 'namespace App; class Service { public static function run($input) {} } class Controller { public function __invoke() {} } class ResourceController { public function index() {} public function store() {} } throw new \\RuntimeException("must not execute");',
        ]);
        $routes = $this->routes($index);
        $this->assertCount(4, $routes);
        $handlers = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'route-handler'));
        $this->assertCount(4, $handlers);
        $closure = array_values(array_filter($handlers, fn ($row) => $index->elements[$row['to']]['kind'] === 'closure'))[0];
        $calls = array_values(array_filter($index->relations, fn ($row) => $row['from'] === $closure['to'] && $row['kind'] === 'calls'));
        $this->assertSame('App\\Service::run', $index->elements[$calls[0]['to']]['name']);
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
        foreach ($routes as $route) {
            $this->assertTrue($route['metadata']['activation_unknown']);
            $this->assertFalse($route['metadata']['execution_proven']);
        }
    }

    public function test_full_resource_routes_select_only_their_seven_source_controller_actions(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::resource("items", App\\Controller::class);',
            'app/Controller.php' => 'namespace App; class Controller { public function index() {} public function create() {} public function store() {} public function show() {} public function edit() {} public function update() {} public function destroy() {} public function unrelated() {} }',
        ]);
        $this->assertCount(7, $this->routes($index));
        $handlers = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'route-handler'));
        $this->assertCount(7, $handlers);
        $this->assertEqualsCanonicalizing(array_map(fn ($method) => 'App\\Controller::'.$method, ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']),
            array_map(fn ($row) => $index->elements[$row['to']]['name'], $handlers));
        foreach ($handlers as $handler) {
            $this->assertSame('routes/web.php', $handler['path']);
            $this->assertFalse($handler['metadata']['execution_proven']);
        }
    }

    public function test_lookalike_route_methods_are_not_routes_and_dynamic_or_missing_handlers_are_explicit(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; App\\Route::get("decoy", "handler"); Route::get($uri, $handler); Route::get("missing", App\\Missing::class); if ($condition) { Route::get("conditional", [App\\Controller::class, "run"]); }',
            'app/Controller.php' => 'namespace App; class Controller { public function run() {} }',
        ]);
        $this->assertCount(3, $this->routes($index));
        $this->assertContains('dynamic_route_handler', array_column($index->diagnostics, 'code'));
        $this->assertContains('http_missing_handler', array_column($index->diagnostics, 'code'));
        $this->assertNotContains('/decoy', array_map(fn ($row) => $row['metadata']['uri'], $this->routes($index)));
    }

    public function test_include_cycles_and_sources_outside_the_declared_graph_are_explicit(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; require __DIR__."/nested.php"; require __DIR__."/missing.php"; require __DIR__."/../../outside.php"; Route::get("known", fn () => 1);',
            'routes/nested.php' => 'require __DIR__."/web.php";',
        ]);
        $this->assertCount(1, $this->routes($index));
        foreach (['http_source_cycle', 'http_missing_source', 'http_source_boundary'] as $code) {
            $this->assertContains($code, array_column($index->diagnostics, 'code'));
        }
    }

    public function test_route_source_depth_limit_does_not_claim_a_complete_registration_map(): void
    {
        $sources = [];
        for ($i = 0; $i < 14; $i++) {
            $sources['routes/source'.$i.'.php'] = $i === 13 ? 'use Illuminate\\Support\\Facades\\Route; Route::get("known", fn () => 1);'
                : 'require __DIR__."/source'.($i + 1).'.php";';
        }
        $index = $this->index($sources);
        $this->assertContains('http_composition_limit', array_column($index->diagnostics, 'code'));
        $this->assertNotEmpty($this->routes($index));
        foreach ($this->routes($index) as $route) {
            $this->assertTrue($route['metadata']['activation_unknown']);
        }
    }

    public function test_legacy_namespace_and_controller_group_preserve_handler_semantics(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::namespace("App\\\\Http")->group(function () { Route::get("legacy", "Billing\\\\Controller@run"); }); Route::controller(App\\Http\\Billing\\Controller::class)->group(function () { Route::get("group", "run"); }); Route::controller($dynamic)->group(function () { Route::get("unknown", "run"); });',
            'app/Controller.php' => 'namespace App\\Http\\Billing; class Controller { public function run() {} }',
        ]);
        $handlers = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'route-handler'));
        $this->assertCount(2, $handlers);
        foreach ($handlers as $handler) {
            $this->assertSame('App\\Http\\Billing\\Controller::run', $index->elements[$handler['to']]['name']);
        }
        $this->assertContains('dynamic_route_handler', array_column($index->diagnostics, 'code'));
    }

    public function test_match_any_and_typed_router_use_the_existing_http_semantics(): void
    {
        $index = $this->index([
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route as R; R::match(["GET", "POST"], "match", [App\\Controller::class, "run"]); R::any("any", [App\\Controller::class, "run"]); function registerRoutes(\\Illuminate\\Routing\\Router $router) { $router->get("typed", [App\\Controller::class, "run"]); }',
            'app/Controller.php' => 'namespace App; class Controller { public function run() {} }',
        ]);
        $routes = $this->routes($index);
        $this->assertCount(3, $routes);
        $this->assertSame(['GET', 'POST'], $routes[0]['metadata']['verbs']);
        $this->assertSame(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $routes[1]['metadata']['verbs']);
        $this->assertSame('/typed', $routes[2]['metadata']['uri']);
        $this->assertCount(3, array_filter($index->relations, fn ($row) => $row['kind'] === 'route-handler'));
    }
}
