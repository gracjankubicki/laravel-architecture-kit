<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use PHPUnit\Framework\TestCase;

final class ContainerCatalogTest extends TestCase
{
    private function index(string $source): CatalogIndex
    {
        return new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build([new FileContext('app/Example.php', '<?php '.$source)])->catalogFacts);
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
    }

    public function test_provider_ancestry_facade_alias_helper_and_typed_container_register_source_candidates(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
use Illuminate\Support\Facades\App as Container;
use Illuminate\Support\ServiceProvider as BaseProvider;
interface Port {}
class Adapter implements Port {}
class ParentProvider extends BaseProvider {}
class Provider extends ParentProvider {
    public function register() {
        $this->app->bind(Port::class, Adapter::class);
        $this->app->singleton(Port::class, fn () => new Adapter('payload-secret'));
        $this->app->scoped(fn (): Port => new Adapter('payload-secret'));
        $this->app->instance(Port::class, new Adapter('payload-secret'));
    }
}
function registration(\Illuminate\Contracts\Container\Container $container) {
    $container->bind(Port::class, Adapter::class);
    Container::singleton(Port::class, Adapter::class);
    app()->scoped(Port::class, Adapter::class);
}
class Consumer { public function __construct(Port $port) {} }
throw new \RuntimeException('source must not execute');
PHP);
        $this->assertCount(7, $this->edges($index, 'registers-binding'));
        $this->assertCount(7, $this->edges($index, 'provides'));
        $this->assertCount(2, $this->edges($index, 'container-factory'));
        $injected = $this->edges($index, 'injected-binding');
        $this->assertCount(7, $injected);
        foreach ($injected as $edge) {
            $this->assertSame('App\\Consumer::__construct', $index->elements[$edge['from']]['name']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('app/Example.php', $edge['path']);
        }
        foreach ($this->edges($index, 'provides') as $edge) {
            $this->assertSame('App\\Adapter', $index->elements[$edge['to']]['name']);
        }
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
        $this->assertCount(7, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'competing_bindings'));
    }

    public function test_contextual_binding_reaches_only_the_named_consumer_and_keeps_other_contexts_separate(): void
    {
        $index = $this->index('namespace App; use Illuminate\\Support\\Facades\\App as Container; interface Port {} class A implements Port {} class B implements Port {} class Consumer { public function __construct(Port $port) {} } class Other { public function __construct(Port $port) {} } Container::when(Consumer::class)->needs(Port::class)->give(A::class); app()->when(Other::class)->needs(Port::class)->give(fn () => new B);');
        $this->assertCount(2, $this->edges($index, 'registers-binding'));
        $injected = $this->edges($index, 'injected-binding');
        $this->assertCount(2, $injected);
        $this->assertSame(['App\\Consumer::__construct', 'App\\Other::__construct'], array_map(fn ($row) => $index->elements[$row['from']]['name'], $injected));
        foreach ($injected as $edge) {
            $binding = $index->elements[$edge['to']];
            $this->assertSame([substr($index->elements[$edge['from']]['name'], 0, -strlen('::__construct'))], $binding['metadata']['contexts']);
        }
        $this->assertNotContains('competing_bindings', array_column($index->diagnostics, 'code'));
    }

    public function test_lookalike_receivers_static_this_and_reassigned_parameters_do_not_prove_container_bindings(): void
    {
        $index = $this->index('namespace App; class Decoy { public $app; public function register() { $this->app->bind("port", "adapter"); } } class Provider extends \\Illuminate\\Support\\ServiceProvider { public static function wrong() { $this->app->bind("port", "adapter"); } } function entry(Decoy $container) { $container->bind("port", "adapter"); } function changed(\\Illuminate\\Container\\Container $container) { $container = new Decoy; $container->bind("port", "adapter"); }');
        $this->assertSame([], $this->edges($index, 'registers-binding'));
        $this->assertSame([], $this->edges($index, 'provides'));
    }

    public function test_dynamic_and_ambiguous_factories_preserve_known_candidates_and_omit_primitive_payloads(): void
    {
        $index = $this->index('namespace App; use Illuminate\\Support\\Facades\\App as Container; interface Port {} class A implements Port {} class B implements Port {} class Consumer {} Container::bind(Port::class, fn () => $flag ? new A("payload-secret") : new B("payload-secret")); Container::singleton(Port::class, fn () => runtimeFactory("payload-secret")); Container::bind($abstract, A::class); Container::when($consumer)->needs(Port::class)->give(A::class); Container::when(Consumer::class)->needs("$token")->give("primitive-secret"); Container::instance("token", "instance-secret");');
        $provided = $this->edges($index, 'provides');
        $this->assertSame(['App\\A', 'App\\B'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $provided));
        foreach (['ambiguous_binding_factory', 'dynamic_binding', 'dynamic_binding_implementation'] as $code) {
            $this->assertContains($code, array_column($index->diagnostics, 'code'));
        }
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations, $index->diagnostics]));
    }

    public function test_global_app_function_shadowing_does_not_disable_the_app_facade(): void
    {
        $index = $this->index('use Illuminate\\Support\\Facades\\App as Container; function app() { return new Decoy; } class Decoy {} app()->bind("port", "adapter"); Container::bind("port", "adapter");');
        $this->assertCount(1, $this->edges($index, 'registers-binding'));
    }

    public function test_invalid_array_binding_arguments_are_not_treated_as_framework_multi_bindings(): void
    {
        $index = $this->index('use Illuminate\\Support\\Facades\\App; interface Port {} class Adapter implements Port {} App::bind([Port::class], fn () => new Adapter); App::singleton(Port::class, [Adapter::class]);');
        $this->assertSame([], $this->edges($index, 'provides'));
        $this->assertContains('dynamic_binding', array_column($index->diagnostics, 'code'));
        $this->assertContains('dynamic_binding_implementation', array_column($index->diagnostics, 'code'));
    }
}
