<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use PHPUnit\Framework\TestCase;

class StaticGraphPackagePennantTest extends TestCase
{
    /** @return list<CatalogFacts> */
    private function facts(string $source, string $path = 'app/Pennant.php'): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build([new FileContext($path, '<?php '.$source)])->catalogFacts;
    }

    private function package(string $version = '1.26.0', string $path = 'composer.lock'): CatalogFacts
    {
        return (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [['name' => 'laravel/pennant', 'version' => $version]]], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
    }

    public function test_definitions_reads_activations_and_conditional_callbacks_are_scope_and_cache_candidates(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Pennant\Feature as Flags;
class NewCheckout { public function resolve($user) { return true; } }
class Controller {
    public function show($user) {
        Flags::define(feature: 'new-checkout', resolver: fn ($user) => Service::eligible($user));
        Flags::define(NewCheckout::class);
        Flags::active('new-checkout');
        Flags::for($user)->value('new-checkout');
        Flags::store('redis')->for($user)->activate('new-checkout', 'activation-secret-sentinel');
        Flags::deactivate('new-checkout');
        Flags::when('new-checkout', fn () => Service::newFlow(), fn () => Service::oldFlow());
        Flags::active(NewCheckout::class);
    }
}
class Service { public static function eligible($user) {} public static function newFlow() {} public static function oldFlow() {} }
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $json = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('activation-secret-sentinel', $json);
        $this->assertStringNotContainsString('source-only-sentinel', $json);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'defines-feature'));
        $this->assertCount(4, $this->edges($index, 'reads-feature'));
        $this->assertCount(2, $this->edges($index, 'changes-feature'));
        $resolvers = $this->edges($index, 'feature-value-resolver');
        $this->assertCount(3, $resolvers);
        foreach ($resolvers as $edge) {
            $this->assertTrue($edge['metadata']['uncached_value_required']);
            $this->assertTrue($edge['metadata']['compatible_scope_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $callbacks = $this->edges($index, 'feature-conditional-callback');
        $this->assertCount(2, $callbacks);
        $this->assertSame(['active', 'inactive'], array_column(array_column($callbacks, 'metadata'), 'feature_condition'));
        $this->assertContains('feature-definition', $index->elements[$index->namedTypes('App\\NewCheckout')[0]]['roles']);
        foreach ([[], [$this->package('99.0.0')], [$this->package(), $this->package('1.0.0', 'vendor/composer/installed.json')]] as $packages) {
            $index = new CatalogIndex([...$facts, ...$packages]);
            $this->assertSame([], $this->edges($index, 'reads-feature'));
            $this->assertSame([], $this->edges($index, 'feature-value-resolver'));
            $this->assertContains('package_pennant_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_lookalikes_dynamic_selectors_and_contract_shadows_do_not_invent_feature_results(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Decoy { public static function active($feature) {} }
Decoy::active('not-a-feature');
\Laravel\Pennant\Feature::active($dynamic);
\Laravel\Pennant\Feature::store($store)->active('unknown-store');
\Laravel\Pennant\Feature::when('flag', true);
\Laravel\Pennant\Feature::define('dynamic-resolver', $resolver);
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($index, 'reads-feature'));
        $this->assertSame([], $this->edges($index, 'feature-conditional-callback'));
        $this->assertSame([], $this->edges($index, 'feature-value-resolver'));
        $this->assertContains('package_pennant_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Laravel\\Pennant; class Feature {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$facts, ...$shadow, $this->package()]);
        $this->assertSame([], $this->edges($index, 'defines-feature'));
    }

    public function test_cached_class_feature_callers_follow_handler_edits_and_selection_limits(): void
    {
        $caller = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts('\\Laravel\\Pennant\\Feature::active(\\App\\NewCheckout::class);', 'app/Controller.php'));
        $feature = $this->facts('namespace App; class NewCheckout { public function resolve($scope) {} }', 'app/Feature.php');
        $index = new CatalogIndex([...$caller, ...$feature, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'feature-value-resolver'));
        $edited = $this->facts('namespace App; class NewCheckout { private function resolve($scope) {} }', 'app/Feature.php');
        $index = new CatalogIndex([...$caller, ...$edited, $this->package()]);
        $this->assertSame([], $this->edges($index, 'feature-value-resolver'));
        $this->assertCount(1, $this->edges($index, 'reads-feature'));
        $limited = $this->facts('\\Laravel\\Pennant\\Feature::values(['.implode(',', array_fill(0, 129, "'flag'")).']);');
        $index = new CatalogIndex([...$limited, $this->package()]);
        $this->assertSame([], $this->edges($index, 'reads-feature'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_class_string_as_explicit_resolver_value_does_not_call_the_class_handler(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Feature { public function resolve($scope) {} }
\Laravel\Pennant\Feature::define('constant-value', Feature::class);
\Laravel\Pennant\Feature::define(Feature::class, false);
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'defines-feature'));
        $this->assertSame([], $this->edges($index, 'feature-value-resolver'));
    }
}
