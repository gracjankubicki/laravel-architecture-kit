<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\FactoryResolver;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\MethodReachability;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestReachabilityResult;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class MethodReachabilityTest extends TestCase
{
    public function test_typed_property_static_new_return_type_and_inherited_methods(): void
    {
        $this->write('Root', 'class Root extends Base { public function __construct(private Service $service) {} public function handle(): void { $this->service->selected(); Maker::make()->run(); $this->inherited(); } }');
        $this->write('Base', 'class Base { protected function inherited(): void { (new InheritedAction)->run(); } }');
        $this->write('Service', 'class Service { public function selected(): void { (new Selected)->run(); } public function unused(): void { (new Unused)->run(); } }');
        $this->write('Maker', 'class Maker { public static function make(): Product { return new Product; } }');
        foreach (['Product', 'Selected', 'Unused', 'InheritedAction'] as $class) {
            $this->write($class, 'class '.$class.' { public function run(): void {} }');
        }
        $result = $this->analyze();
        $this->assertSame([], $result->diagnostics);
        foreach (['Root', 'Base', 'Service', 'Selected', 'Maker', 'Product', 'InheritedAction'] as $class) {
            $this->assertArrayHasKey(strtolower('App\\'.$class), $result->symbols);
        }
        $this->assertArrayNotHasKey('app\\unused', $result->symbols);
    }

    public function test_cycles_interfaces_and_dynamic_receivers_are_reported(): void
    {
        $this->write('Root', 'class Root { public function handle(Port $port): void { $port->run(); $this->loop(); $unknown->run(); } private function loop(): void { $this->loop(); } }');
        $this->write('Port', 'interface Port { public function run(): void; }');
        $result = $this->analyze();
        $this->assertCount(3, $result->diagnostics);
        $this->assertArrayNotHasKey('app\\port', $result->symbols);
        $this->assertStringContainsString('cycle', implode(' ', array_column($result->diagnostics, 'message')));
    }

    public function test_a_deep_call_chain_stops_at_the_bound(): void
    {
        $this->write('Root', 'class Root { public function handle(): void { (new Step0)->run(); } }');
        for ($i = 0; $i < 20; $i++) {
            $this->write('Step'.$i, 'class Step'.$i.' { public function run(): void { (new Step'.($i + 1).')->run(); } }');
        }
        $result = $this->analyze();
        $this->assertNotSame([], $result->diagnostics);
        $this->assertArrayNotHasKey('app\\step19', $result->symbols);
    }

    public function test_source_budget_reason_survives_method_and_property_lookup(): void
    {
        $this->write('Root', 'class Root { public function handle(Huge $huge): void { $huge->run(); $huge->worker->run(); } }');
        $this->write('Huge', 'class Huge { public Worker $worker; public function run(): void {} } /*'.str_repeat('x', 100_001).'*/');
        $result = $this->analyze();
        $messages = implode(' ', array_column($result->diagnostics, 'message'));
        $this->assertStringContainsString('Source or memory budget exceeded for App\\Huge', $messages);
        $this->assertStringNotContainsString('Unresolved project method', $messages);
        $this->assertArrayNotHasKey('app\\huge', $result->symbols);
    }

    private function analyze(): TestReachabilityResult
    {
        $files = new Filesystem;
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $sources = new SourceIndex($files, $this->tempPath, $graph);

        return (new MethodReachability($sources, new FactoryResolver($sources)))->analyze('App\\Root', 'handle');
    }

    private function write(string $class, string $body): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/'.$class.'.php', '<?php namespace App; '.$body);
    }
}
