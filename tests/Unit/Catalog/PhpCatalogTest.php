<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCatalogExtractor;
use PHPUnit\Framework\TestCase;

final class PhpCatalogTest extends TestCase
{
    public function test_final_method_class_and_trait_alias_flags_are_typed_source_facts(): void
    {
        $facts = (new PhpCatalogExtractor)->extract(new FileContext('app/Final.php', '<?php namespace App; trait Loads { protected function selected() {} } final class FinalProvider { use Loads { selected as final locked; selected as protected; } final public function boot() {} }'));
        $this->assertSame([], $facts->diagnostics);
        $type = array_values(array_filter($facts->elements, fn ($row) => $row->kind === 'class'))[0];
        $this->assertTrue($type->metadata['final']);
        $this->assertTrue($type->metadata['trait_rules'][0]['final']);
        $this->assertNull($type->metadata['trait_rules'][0]['visibility']);
        $this->assertSame('protected', $type->metadata['trait_rules'][1]['visibility']);
        $method = array_values(array_filter($facts->elements, fn ($row) => $row->name === 'App\\FinalProvider::boot'))[0];
        $this->assertTrue($method->metadata['final']);
        $this->assertEquals($facts, CatalogFacts::fromArray($facts->path, $facts->toArray()));
        $stored = $type->toArray();
        $stored['metadata']['final'] = 'corrupt';
        $this->expectException(\InvalidArgumentException::class);
        CatalogElement::fromArray($stored);
    }

    public function test_trait_rules_round_trip_and_malformed_rules_are_rejected(): void
    {
        $facts = (new PhpCatalogExtractor)->extract(new FileContext('app/Example.php', '<?php namespace App; trait First { public function run() {} } trait Second { public function run() {} } class Example { use First, Second { First::run insteadof Second; Second::run as private other; } }'));
        $rows = $facts->toArray();
        $this->assertSame($rows, CatalogFacts::fromArray('app/Example.php', $rows)->toArray());
        foreach ($rows['elements'] as &$row) {
            if ($row['name'] === 'App\\Example') {
                $this->assertSame(['App\\Second'], $row['metadata']['trait_rules'][0]['excluded']);
                $this->assertSame('other', $row['metadata']['trait_rules'][1]['alias']);
                $this->assertSame('private', $row['metadata']['trait_rules'][1]['visibility']);
                $row['metadata']['trait_rules'][0]['excluded'] = [false];
            }
        }
        unset($row);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray('app/Example.php', $rows);
    }

    public function test_relative_includes_keep_caller_and_project_candidates_without_choosing_a_runtime_search_path(): void
    {
        $graph = (new ProjectGraphBuilder(catalog: true))->build([
            new FileContext('routes/web.php', '<?php require "extra.php";'),
            new FileContext('routes/extra.php', '<?php throw new \\RuntimeException("do not execute");'),
            new FileContext('extra.php', '<?php throw new \\RuntimeException("do not execute");'),
        ]);
        $index = new CatalogIndex($graph->catalogFacts);
        $includes = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'includes-file'));
        $this->assertCount(2, $includes);
        $this->assertSame(['extra.php', 'routes/extra.php'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $includes));
        $this->assertSame(['conditional', 'conditional'], array_column($includes, 'resolution'));
        $this->assertSame(['include_search_path'], array_column($index->diagnostics, 'code'));
    }

    public function test_literal_include_is_a_source_relationship_and_dynamic_or_outside_inputs_remain_explicit(): void
    {
        $builder = new ProjectGraphBuilder(catalog: true);
        $graph = $builder->build([
            new FileContext('routes/web.php', '<?php require __DIR__."/extra.php"; include $dynamic; include __DIR__."/../../outside.php"; require __DIR__."/missing.php";'),
            new FileContext('routes/extra.php', '<?php throw new \\RuntimeException("do not execute included source");'),
        ]);
        $index = new CatalogIndex($graph->catalogFacts);
        $includes = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'includes-file'));
        $this->assertCount(2, $includes);
        $this->assertSame('routes/extra.php', $index->elements[$includes[0]['to']]['name']);
        $this->assertContains('dynamic_include', array_column($index->diagnostics, 'code'));
        $this->assertContains('include_outside_graph', array_column($index->diagnostics, 'code'));
    }

    public function test_declarations_have_lexical_owners_and_resolved_evidence_without_execution(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
use Vendor\Base as Base;
use Vendor\Marker as Marker;
throw new \RuntimeException('This source must never execute');
interface Port {}
trait Shared {}
enum State { case Open; }
#[Marker('secret argument is omitted')]
class Worker extends Base implements Port {
    use Shared;
    public string $name;
    public function __construct(public int $count) {}
    public function handle() {
        $callback = function () { return fn () => 1; };
        $object = new class { public function run() {} };
        $this->{$method}();
    }
}
function helper() { return fn () => 2; }
PHP;
        $file = new FileContext('app/Worker.php', $source);
        $facts = (new PhpCatalogExtractor)->extract($file);
        $byName = [];
        foreach ($facts->elements as $element) {
            $byName[$element->name] = $element;
            $this->assertGreaterThanOrEqual($element->line, $element->endLine);
            if ($element->parent !== null) {
                $this->assertContains($element->parent, array_column($facts->elements, 'id'));
            }
        }
        foreach (['App\\Port' => 'interface', 'App\\Shared' => 'trait', 'App\\State' => 'enum', 'App\\State::Open' => 'enum-case',
            'App\\Worker' => 'class', 'App\\Worker::$name' => 'property', 'App\\Worker::$count' => 'property',
            'App\\Worker::handle' => 'method', 'App\\helper' => 'function', 'Vendor\\Marker' => 'attribute'] as $name => $kind) {
            $this->assertSame($kind, $byName[$name]->kind, $name);
        }
        $this->assertSame($byName['App\\Worker']->id, $byName['App\\Worker::handle']->parent);
        $this->assertTrue($byName['App\\Worker::$count']->metadata['promoted']);
        $closures = array_values(array_filter($facts->elements, fn ($item) => $item->kind === 'closure'));
        $this->assertCount(3, $closures);
        $this->assertSame($byName['App\\Worker::handle']->id, $closures[0]->parent);
        $this->assertSame($closures[0]->id, $closures[1]->parent);
        $this->assertSame($byName['App\\helper']->id, $closures[2]->parent);
        $relations = array_map(fn ($item) => $item->toArray(), $facts->relations);
        $this->assertContains('php:Vendor\\Base', array_column($relations, 'to'));
        $this->assertContains('uses-trait', array_column($relations, 'kind'));
        $this->assertSame(['dynamic_call'], array_column($facts->diagnostics, 'code'));
        $this->assertStringNotContainsString('secret argument', json_encode($facts->toArray()));
        $this->assertSame($facts->toArray(), CatalogFacts::fromArray($file->path, $facts->toArray())->toArray());
        $this->assertSame($facts->toArray(), (new PhpCatalogExtractor)->extract($file)->toArray());
    }

    public function test_bad_source_is_a_known_file_with_a_diagnostic_not_a_complete_empty_result(): void
    {
        $facts = (new PhpCatalogExtractor)->extract(new FileContext('app/Broken.php', '<?php class {'));
        $this->assertCount(1, $facts->elements);
        $this->assertSame('file', $facts->elements[0]->kind);
        $this->assertSame('parse_error', $facts->diagnostics[0]->code);
    }

    public function test_supplement_is_opt_in_and_does_not_change_legacy_symbols_edges_or_test_links(): void
    {
        $file = new FileContext('app/Actions/Run.php', '<?php namespace App; class Run { public function handle(Dependency $value) {} }');
        $legacy = (new ProjectGraphBuilder(impact: true))->build([$file]);
        $catalog = (new ProjectGraphBuilder(impact: true, catalog: true))->build([$file]);
        $this->assertEquals($legacy->symbols, $catalog->symbols);
        $this->assertEquals($legacy->edges, $catalog->edges);
        $this->assertEquals($legacy->testInvocations, $catalog->testInvocations);
        $this->assertEquals($legacy->impactFacts, $catalog->impactFacts);
        $this->assertSame([], $legacy->catalogFacts);
        $this->assertCount(1, $catalog->catalogFacts);
    }

    public function test_resource_identity_is_distinct_from_declaration_and_kind_namespaces(): void
    {
        $this->assertNotSame(CatalogElement::resourceIdentity('table', 'users'), CatalogElement::resourceIdentity('view', 'users'));
        $this->assertNotSame(CatalogElement::resourceIdentity('table', 'users'), CatalogElement::identity('app/User.php', 'table', 'users'));
        $this->assertNotSame(CatalogElement::identity('app/A.php', 'closure', 'same', 10), CatalogElement::identity('app/A.php', 'closure', 'same', 20));
    }
}
