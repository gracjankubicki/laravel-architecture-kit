<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\SaloonPoolCatalogExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SaloonPoolCatalogTest extends TestCase
{
    private function facts(string $body): CatalogFacts
    {
        $file = new FileContext('app/Action.php', '<?php namespace App; use App\Requests\Invoice as Request; throw new \RuntimeException("never execute"); class Action { public function run($connector) { '.$body.'; } }');

        return (new SaloonPoolCatalogExtractor)->extract($file, (new PhpCatalogExtractor)->extract($file));
    }

    public function test_returned_member_lists_survive_cache_with_source_origins_and_without_payloads(): void
    {
        $file = new FileContext('app/Factory.php', <<<'PHP'
<?php
namespace App;
throw new \RuntimeException('never execute');
class Factory {
    public function requests() { $request = new Invoice('body-secret'); return [$request]; }
    public function empty() { return []; }
    public function partial($dynamic) { return [new Invoice, $dynamic, 'value-secret']; }
}
PHP);
        $facts = (new ProjectGraphBuilder(catalog: true))->build([$file])->catalogFacts[0];
        $facts = CatalogFacts::fromArray($facts->path, $facts->toArray());
        $returns = array_values(array_filter($facts->relations, fn ($edge) => $edge->kind === 'returns-value' && isset($edge->metadata['saloon_pool_members'])));
        $this->assertCount(3, $returns);
        $this->assertSame(['complete' => true, 'members' => [['receiver' => 'App\\Invoice', 'origin' => strpos($file->contents, "new Invoice('body-secret')") + strlen("new Invoice('body-secret')") - 1]]], $returns[0]->metadata['saloon_pool_members']);
        $this->assertSame(['complete' => true, 'members' => []], $returns[1]->metadata['saloon_pool_members']);
        $this->assertFalse($returns[2]->metadata['saloon_pool_members']['complete']);
        $this->assertCount(1, $returns[2]->metadata['saloon_pool_members']['members']);
        $this->assertStringNotContainsString('-secret', json_encode($facts->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_literal_members_and_named_arguments_survive_cache_without_payloads(): void
    {
        $facts = $this->facts('$connector->pool(concurrency: 5, requests: [new Request("body-secret"), new Request("other-secret")])');
        $roundtrip = CatalogFacts::fromArray($facts->path, $facts->toArray());
        $this->assertCount(1, $roundtrip->elements);
        $site = $roundtrip->elements[0];
        $this->assertTrue($site->metadata['valid']);
        $this->assertTrue($site->metadata['resolved']);
        $this->assertSame(['App\Requests\Invoice'], $site->metadata['targets']);
        $this->assertFalse($site->metadata['generator']);
        $this->assertNull($site->metadata['factory']);
        $this->assertStringNotContainsString('-secret', json_encode($roundtrip->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame([], $roundtrip->relations);
    }

    public function test_generator_detection_respects_callable_scope_and_unmodeled_branches(): void
    {
        $cases = [
            ['fn () => [new Request]', false, true, ['App\Requests\Invoice']],
            ['fn () => yield new Request', true, true, ['App\Requests\Invoice']],
            ['function () { yield new Request; return [new Other]; }', true, true, ['App\Requests\Invoice']],
            ['function () { if (random_int(0, 1)) { yield new Request; } return [new Other]; }', true, false, []],
            ['function () { $value = yield new Request; }', true, false, []],
            ['function () { yield from [new Request]; }', true, false, []],
            ['function () { $nested = function () { yield new Request; }; return [new Other]; }', false, false, ['App\Other']],
            ['function () { return [new Request]; }', false, true, ['App\Requests\Invoice']],
        ];
        foreach ($cases as [$callback, $generator, $resolved, $targets]) {
            $facts = $this->facts('$connector->pool('.$callback.')');
            $this->assertCount(1, $facts->elements, $callback);
            $metadata = $facts->elements[0]->metadata;
            $this->assertNotNull($metadata['factory'], $callback);
            $this->assertSame($generator, $metadata['generator'], $callback);
            $this->assertSame($resolved, $metadata['resolved'], $callback);
            $this->assertSame($targets, $metadata['targets'], $callback);
        }
    }

    public function test_invalid_and_dynamic_arguments_never_become_complete_member_lists(): void
    {
        foreach (['$connector->pool(...$args)', '$connector->pool(payload: [new Request])', '$connector->pool(requests: [], requests: [])'] as $call) {
            $facts = $this->facts($call);
            $this->assertFalse($facts->elements[0]->metadata['valid'], $call);
        }
        foreach (['$connector->pool($requests)', '$connector->pool([new Request, ...$other])', '$connector->pool([new $type])'] as $call) {
            $facts = $this->facts($call);
            $this->assertFalse($facts->elements[0]->metadata['resolved'], $call);
        }
        $this->assertSame([], $this->facts('$connector->pool(...)')->elements);
    }

    public function test_oversized_member_list_has_explicit_cacheable_structure_limit(): void
    {
        $facts = $this->facts('$connector->pool(['.implode(',', array_fill(0, 129, 'new Request')).'])');
        $this->assertFalse($facts->elements[0]->metadata['resolved']);
        $this->assertSame('catalog_limit', $facts->diagnostics[0]->code);
        $this->assertSame('structure', $facts->diagnostics[0]->limitReason);
        $this->assertTrue($facts->cacheable());
    }

    public function test_builder_collects_pool_facts_using_existing_ast(): void
    {
        $file = new FileContext('app/Action.php', '<?php class Action { function run($connector) { $connector->pool([]); } }');
        $snapshot = (new ProjectGraphBuilder(catalog: true))->build([$file]);
        $sites = array_values(array_filter($snapshot->catalogFacts[0]->elements, fn ($element) => $element->kind === 'saloon-pool-site'));
        $this->assertCount(1, $sites);
        $this->assertTrue($sites[0]->metadata['resolved']);
    }

    public function test_cache_rejects_payload_injection_into_member_type(): void
    {
        $facts = $this->facts('$connector->pool([new Request])');
        $data = $facts->toArray();
        $data['elements'][0]['metadata']['targets'] = ['https://user:password@example.test'];
        $this->expectException(InvalidArgumentException::class);
        CatalogFacts::fromArray($facts->path, $data);
    }

    public function test_chained_sites_have_distinct_identity_and_conditional_setter_survives_cache(): void
    {
        $facts = $this->facts('$pool = $connector->pool([])->setRequests([new Request]); if ($flag) { $pool->setRequests(requests: []); }');
        $facts = CatalogFacts::fromArray($facts->path, $facts->toArray());
        $this->assertCount(3, $facts->elements);
        $this->assertCount(3, array_unique(array_map(fn ($site) => $site->id, $facts->elements)));
        $setters = array_values(array_filter($facts->elements, fn ($site) => $site->metadata['form'] === 'setrequests'));
        $this->assertSame([false, true], array_map(fn ($site) => $site->metadata['conditional'], $setters));
        foreach (['$connector->setRequests()', '$connector->setRequests(other: [])', '$connector->setRequests(...$args)'] as $call) {
            $this->assertFalse($this->facts($call)->elements[0]->metadata['valid']);
        }
    }

    public function test_callback_shape_omits_strings_and_rejects_incompatible_scalars_and_names(): void
    {
        foreach (['$connector->pool(responseHandler: "callback-secret")', '$connector->withResponseHandler("callback-secret")'] as $call) {
            $facts = $this->facts($call);
            $this->assertTrue($facts->elements[0]->metadata['valid']);
            $this->assertFalse($facts->elements[0]->metadata['callbacks']['response']['resolved']);
            $this->assertStringNotContainsString('-secret', json_encode($facts->toArray(), JSON_THROW_ON_ERROR));
        }
        foreach (['$connector->withResponseHandler(null)', '$connector->withExceptionHandler(false)', '$connector->setConcurrency(null)',
            '$connector->withResponseHandler(responseHandler: fn () => 1)', '$connector->setConcurrency(...$args)'] as $call) {
            $this->assertFalse($this->facts($call)->elements[0]->metadata['valid'], $call);
        }
        $facts = $this->facts('$connector->pool([], responseHandler: function () { yield 1; })');
        $this->assertFalse($facts->elements[0]->metadata['callbacks']['response']['resolved']);
        $this->assertNull($facts->elements[0]->metadata['callbacks']['response']['id']);
    }

    public function test_promise_span_cache_validation_rejects_a_different_source_site(): void
    {
        $facts = $this->facts('$connector->pool([$connector->sendAsync(new Request("body-secret"))])');
        $this->assertCount(1, $facts->elements[0]->metadata['promise_sites']);
        $this->assertTrue($facts->elements[0]->metadata['resolved']);
        $this->assertStringNotContainsString('-secret', json_encode($facts->toArray(), JSON_THROW_ON_ERROR));
        $data = $facts->toArray();
        $data['elements'][0]['metadata']['promise_sites'][0]['offset'] = 0;
        $this->expectException(InvalidArgumentException::class);
        CatalogFacts::fromArray($facts->path, $data);
    }
}
