<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;

final class NativeBasicParityTest extends NativeBasicFixture
{
    public function test_foundation_and_calls_preserve_all_eight_ordered_sets(): void
    {
        $this->load([
            'app/Services/Target.php' => '<?php namespace App; interface Port { public function run(); } trait Work { public function run() {} } class Target implements Port { use Work; public function __construct(public Other $other) {} } class Other {public function act() {}}',
            'app/Caller.php' => '<?php namespace App; function helper() {} class Caller { public function call(Target $t) { helper(); $t->run(); $t->other->act(); $t->missing(); } }',
            'app/Child.php' => '<?php namespace App; class Child extends Target {}',
            'tests/TargetTest.php' => '<?php class TargetTest extends \\PHPUnit\\Framework\\TestCase { public function testRun(){ (new \\App\\Target(new \\App\\Other))->run(); }}',
            'app/Empty.php' => '<?php',
        ]);
    }

    public function test_traits_visibility_functions_and_implicit_constructors(): void
    {
        $this->load(['app/Example.php' => '<?php namespace App; trait A { public function run() {} protected function hidden() {} } trait B {public function run() {}} class C { use A,B { A::run insteadof B; B::run as alias; A::hidden as public exposed; } public function call() { $this->exposed(); self::run(); }} class D extends \\Vendor\\Base {} function helper() {} function caller(C $c){$c->run();$c->alias();$c->hidden();new D;helper();}']);
    }

    public function test_composer_states_symbolic_laravel_modules_blade_and_parse_error(): void
    {
        [, $oracle] = $this->load([
            'composer.json' => json_encode(['name' => 'fixture/library', 'require' => ['laravel/ai' => '^0.11'], 'require-dev' => ['fixture/dev' => '*'], 'autoload' => ['psr-4' => ['' => '', 'App\\' => 'app/']], 'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]], JSON_THROW_ON_ERROR),
            'composer.lock' => '{"packages":[{"name":"laravel/ai","version":"v0.11.2","source":{"reference":"wrong"}}],"packages-dev":[{"name":"fixture/dev","version":"v1.0.0"}]}',
            'vendor/composer/installed.json' => '{"packages":[{"name":"laravel/ai","version":"v0.11.1","source":{"reference":"other"}}],"dev":true}',
            'Modules/Billing/ServiceProvider.php' => '<?php namespace Modules\\Billing; class ServiceProvider extends \\Illuminate\\Support\\ServiceProvider {public function register(){ $this->app->bind(Port::class,Adapter::class);}}',
            'routes/web.php' => '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/example",[App\\Controller::class,"show"]);',
            'app/Controller.php' => '<?php namespace App; class Controller extends \\Illuminate\\Routing\\Controller {public function show(){}}',
            'resources/views/page.blade.php' => '<p>{{ (new \\App\\Controller)->show() }}</p>',
            'app/Broken.php' => '<?php class Broken {',
        ]);
        $this->assertContains('autoload-mapping', array_column($oracle->elements, 'kind'));
        $this->assertContains('composer-dependency', array_column($oracle->elements, 'kind'));
    }

    public function test_argument_descriptors_properties_unions_returns_and_closure_scopes(): void
    {
        $this->load(['app/Example.php' => '<?php namespace App; class A {public function run(){} public static function stat(){} private function hidden(){} public function closure(){return function(){ $this->hidden();};}} class B extends A {} class Box {public function __construct(public A|B $value){}} function value($arg){} function ref(&$arg){} function factory(){return new A;} function callbacks(){return function($arg){};} function caller(A $a,Box $b){$a->run();value($a);$a->run();ref($a);$a->run();$b->value->run();factory()->run();$cb=callbacks();$cb($b);$b->value->run();$fn=A::stat(...);$fn();$closure=function()use($b){$b->value->run();};$closure();}']);
    }

    public function test_shadowed_external_ancestry_cycles_and_depth_limit(): void
    {
        $source = '<?php namespace App; class A extends B {} class B extends A {} class Pivot extends \\Illuminate\\Database\\Eloquent\\Relations\\MorphPivot {}';
        for ($i = 0; $i < 35; $i++) {
            $source .= ' class Level'.$i.' extends Level'.($i + 1).' {}';
        }
        $source .= ' class Level35 extends \\Illuminate\\Database\\Eloquent\\Model {}';
        [$generation, $oracle] = $this->load(['app/Types.php' => $source]);
        $this->assertNotEmpty($oracle->exportState()['hierarchyLimits']);
        $files = ['app/Types.php' => $source, 'app/Shadow.php' => '<?php namespace Illuminate\\Database\\Eloquent; class Model {}'];
        $this->patch($generation, $files, ['app/Shadow.php']);
    }

    public function test_every_contract_role_and_queueability_reject_lookalike_names(): void
    {
        $source = '<?php namespace RoleFixture; ';
        foreach (array_keys(CatalogIndex::CONTRACT_ROLES) as $i => $contract) {
            $source .= 'class Role'.$i.' extends \\'.$contract.' {} ';
        }
        $source .= 'class Queued implements \\Illuminate\\Contracts\\Queue\\ShouldQueue {} class Lookalike extends \\Other\\Model {}';
        [, $oracle] = $this->load(['app/Types.php' => $source]);
        foreach (array_values(CatalogIndex::CONTRACT_ROLES) as $i => $role) {
            $row = $oracle->elements[$oracle->namedTypes('RoleFixture\\Role'.$i)[0]];
            $this->assertContains($role, $row['roles']);
        }
        $this->assertTrue($oracle->elements[$oracle->namedTypes('RoleFixture\\Queued')[0]]['metadata']['queueable']);
        $this->assertSame([], $oracle->elements[$oracle->namedTypes('RoleFixture\\Lookalike')[0]]['roles']);
    }

    public function test_malformed_composer_metadata_keeps_explicit_diagnostics(): void
    {
        [, $oracle] = $this->load(['composer.json' => '{bad JSON', 'composer.lock' => '{"packages":"wrong"}', 'vendor/composer/installed.json' => '{"packages":[{"name":false}]}']);
        $this->assertNotEmpty($oracle->diagnostics);
    }

    public function test_abstract_lexical_static_parent_invokable_anonymous_and_conflicting_branches(): void
    {
        $this->load(['app/Dispatch.php' => '<?php namespace App; abstract class Base {abstract public function run(); protected static function stat(){} private function secret(){} public function bound(){$this->secret();return fn()=>self::stat();}} class Child extends Base {public function run(){parent::bound();self::stat();} public function __invoke(Target $target){$target->act();}} class Target {public function act(){}} function factory(bool $condition){if($condition){return new Target;}return new Child;} function caller(Child $child,Target $target,bool $flag){$child($target);$anonymous=new class extends Child {};$anonymous->run();$closure=$child->bound();$closure();$value=$flag?new Target:new Child;$value->act();factory($flag)->act();$callable=[$target,"act"];$callable();}']);
    }
}
