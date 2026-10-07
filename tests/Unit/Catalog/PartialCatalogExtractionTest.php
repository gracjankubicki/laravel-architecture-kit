<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ContainerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\ExecutionCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\HttpCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\ValidationCatalogExtractor;
use PHPUnit\Framework\TestCase;

final class PartialCatalogExtractionTest extends TestCase
{
    public function test_memory_limits_remain_retryable_and_the_reason_survives_serialization(): void
    {
        $file = new FileContext('app/Memory.php', '<?php namespace App; function run() { return json_encode([]); }');
        $php = (new PhpCatalogExtractor)->extract($file);
        $previous = ini_get('memory_limit');
        try {
            // The AST already exists. Leave allocation headroom while forcing the
            // extraction guard's 65% threshold, without allocating a large fixture.
            ini_set('memory_limit', (string) (memory_get_usage(true) + 2 * 1024 * 1024));
            foreach ([new HttpCatalogExtractor, new ValidationCatalogExtractor] as $extractor) {
                $facts = $extractor->extract($file, $php);
                $this->assertNotEmpty($facts->diagnostics);
                foreach ($facts->diagnostics as $diagnostic) {
                    $this->assertSame('memory', $diagnostic->limitReason);
                }
                $restored = CatalogFacts::fromArray($facts->path, $facts->toArray());
                $this->assertFalse($restored->cacheable());
                $this->assertSame($facts->toArray(), $restored->toArray());
            }
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    public function test_diagnostic_limit_reasons_are_checked_and_unknown_limits_remain_retryable(): void
    {
        $plain = new CatalogDiagnostic('catalog_limit', 'Unclassified budget.', 1);
        $this->assertNull(CatalogDiagnostic::fromArray($plain->toArray())->limitReason);
        $this->assertFalse((new CatalogFacts('app/File.php', diagnostics: [$plain]))->cacheable());
        $bounded = new CatalogDiagnostic('catalog_limit', 'Source bound.', 1, limitReason: 'structure');
        $this->assertTrue((new CatalogFacts('app/File.php', diagnostics: [CatalogDiagnostic::fromArray($bounded->toArray())]))->cacheable());
        $raw = $bounded->toArray();
        $raw['limit_reason'] = 'untrusted';
        $this->expectException(\InvalidArgumentException::class);
        CatalogDiagnostic::fromArray($raw);
    }

    private function file(): FileContext
    {
        return new FileContext('routes/web.php', <<<'SOURCE'
<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\App as Container;
Route::get('orders', [\App\OrdersController::class, 'show']);
Container::bind(\App\Port::class, \App\Adapter::class);
Event::dispatch(new \App\Saved);
$object->{$method}();
SOURCE);
    }

    public function test_local_dynamic_call_does_not_suppress_known_classless_laravel_operations(): void
    {
        $file = $this->file();
        $php = (new PhpCatalogExtractor)->extract($file);
        $this->assertCount(1, $php->elements);
        $this->assertSame('file', $php->elements[0]->kind);
        $this->assertContains('dynamic_call', array_map(fn ($diagnostic) => $diagnostic->code, $php->diagnostics));
        $http = (new HttpCatalogExtractor)->extract($file, $php);
        $this->assertCount(1, $http->relations);
        $this->assertSame('http-template', $http->relations[0]->kind);
        $this->assertSame('orders', $http->relations[0]->metadata['uri']);
        $this->assertSame(5, $http->relations[0]->line);
        $container = (new ContainerCatalogExtractor)->extract($file, $php);
        $this->assertCount(1, $container->relations);
        $this->assertSame(['App\\Port'], $container->relations[0]->metadata['abstracts']);
        $this->assertSame(['App\\Adapter'], $container->relations[0]->metadata['implementations']);
        $this->assertSame(6, $container->relations[0]->line);
        $execution = (new ExecutionCatalogExtractor)->extract($file, $php);
        $this->assertCount(1, $execution->relations);
        $this->assertSame('execution-template', $execution->relations[0]->kind);
        $this->assertContains('event', array_column($execution->relations[0]->metadata['operations'], 'kind'));
    }

    public function test_parse_and_budget_failures_still_stop_extraction_without_reusing_incomplete_php_facts(): void
    {
        $file = $this->file();
        $php = (new PhpCatalogExtractor)->extract($file);
        foreach (['source_limit', 'parse_error', 'catalog_limit'] as $code) {
            $failed = new CatalogFacts($file->path, $php->elements, diagnostics: [new CatalogDiagnostic($code, 'Source extraction incomplete.', 1)]);
            foreach ([new HttpCatalogExtractor, new ContainerCatalogExtractor, new ExecutionCatalogExtractor] as $extractor) {
                $this->assertSame([], $extractor->extract($file, $failed)->relations);
            }
        }
    }

    public function test_builder_retains_route_container_and_event_links_with_the_dynamic_call_diagnostic(): void
    {
        $source = $this->file();
        $source = new FileContext($source->path, $source->contents."\nEvent::listen(\\App\\Saved::class, \\App\\Listener::class);");
        $declarations = new FileContext('app/Declarations.php', <<<'SOURCE'
<?php namespace App;
interface Port {}
class Adapter implements Port {}
class OrdersController { public function show() {} }
class Saved {}
class Listener { public function handle(Saved $event) {} }
SOURCE);
        $index = new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build([$source, $declarations])->catalogFacts);
        foreach (['route-handler', 'registers-binding', 'provides', 'event-dispatch', 'event-listener'] as $kind) {
            $edges = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
            $this->assertCount(1, $edges, $kind);
            $this->assertSame('routes/web.php', $edges[0]['path']);
        }
        $this->assertContains('dynamic_call', array_column($index->diagnostics, 'code'));
    }
}
