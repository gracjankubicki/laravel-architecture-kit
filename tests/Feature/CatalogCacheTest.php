<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogCacheTest extends TestCase
{
    public function test_returned_callback_argument_proof_recomposes_after_factory_edit(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; function factory() { return function ($worker) {}; }');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; class Worker { public function perform() {} } function entry() { $worker = new Worker; $callback = factory(); $callback($worker); $worker->perform(); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $calls = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls'
            && ($index->elements[$edge['from']]['name'] ?? '') === 'App\\entry'
            && ($index->elements[$edge['to']]['name'] ?? '') === 'App\\Worker::perform'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $calls($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));

        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; function factory() { return function (&$worker) {}; }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $calls($current));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_invokable_factory_callback_proof_refreshes_with_cached_factory_and_consumer(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; function factory() { return new Maker; }');
        $maker = '<?php namespace App; class Maker { public function __invoke() { return function (%s$worker) {}; } }';
        $files->put($this->tempPath.'/app/Maker.php', sprintf($maker, ''));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; class Worker { public function perform() {} } function entry() { $worker = new Worker; $factory = factory(); $callback = $factory(); $callback($worker); $worker->perform(); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $calls = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls'
            && ($index->elements[$edge['from']]['name'] ?? '') === 'App\\entry'
            && ($index->elements[$edge['to']]['name'] ?? '') === 'App\\Worker::perform'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $calls($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Maker.php', sprintf($maker, '&'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Maker.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Factory.php', $changed->reusable);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $calls($current));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_observer_event_selection_refreshes_after_custom_property_or_getter_edit(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Observer.php', '<?php namespace App; class Observer { public function approved($model) {} public function refused($model) {} }');
        $files->put($this->tempPath.'/app/Registration.php', '<?php namespace App; Invoice::observe(Observer::class);');
        foreach (['protected $observables = ["%s"];', 'public function getObservableEvents() { return ["%s"]; }'] as $declaration) {
            $model = '<?php namespace App; class Invoice extends \\Illuminate\\Database\\Eloquent\\Model { '.sprintf($declaration, '%s').' }';
            $files->put($this->tempPath.'/app/Invoice.php', sprintf($model, 'approved'));
            clearstatcache();
            $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
            $targets = fn ($index) => array_values(array_map(fn ($edge) => $index->elements[$edge['to']]['name'],
                array_filter($index->relations, fn ($edge) => $edge['kind'] === 'event-registration')));
            $first = new CatalogIndex($loader->load()->catalogFacts);
            $this->assertSame(['App\\Observer::approved'], $targets($first));
            $warm = $loader->plan();
            $this->assertSame([], $warm->toParse);
            $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
            $files->put($this->tempPath.'/app/Invoice.php', sprintf($model, 'refused'));
            clearstatcache();
            $changed = $loader->plan();
            $this->assertSame(['app/Invoice.php'], $changed->toParse);
            $this->assertArrayHasKey('app/Observer.php', $changed->reusable);
            $this->assertArrayHasKey('app/Registration.php', $changed->reusable);
            $current = new CatalogIndex($loader->build($changed)->catalogFacts);
            $this->assertSame(['App\\Observer::refused'], $targets($current));
            $warm = $loader->plan();
            $this->assertSame([], $warm->toParse);
            $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
        }
    }

    public function test_catalog_build_observes_one_real_parse_and_warm_cache_observes_none(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Worker.php', '<?php namespace App; class Worker { public function run() { return json_encode([]); } }');
        $calls = [];
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true,
            onParse: static function (string $path) use (&$calls): void {
                $calls[$path] = ($calls[$path] ?? 0) + 1;
            });
        $loader->load();
        $this->assertSame(['app/Worker.php' => 1], $calls);
        $calls = [];
        $loader->load();
        $this->assertSame([], $calls);
        $files->append($this->tempPath.'/app/Worker.php', "\n// changed source");
        clearstatcache();
        $loader->load();
        $this->assertSame(['app/Worker.php' => 1], $calls);
    }

    public function test_source_bounded_partial_results_are_reused_with_diagnostics_and_refreshed_after_edit(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $path = 'app/Deep.php';
        $source = '<?php namespace App; function deep() { '.str_repeat('if (true) { ', 40).'return json_encode([]);'.str_repeat('}', 40).' }';
        $files->put($this->tempPath.'/'.$path, $source);
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = $loader->load();
        $diagnostics = array_values(array_filter($first->catalogFacts[0]->diagnostics, fn ($row) => in_array($row->code, ['http_limit', 'catalog_limit'], true)));
        $this->assertNotEmpty($diagnostics);
        foreach ($diagnostics as $diagnostic) {
            $this->assertSame('structure', $diagnostic->limitReason);
        }
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals(new CatalogIndex($first->catalogFacts), new CatalogIndex($loader->build($warm)->catalogFacts));

        $files->put($this->tempPath.'/'.$path, '<?php namespace App; function deep() { return json_encode([]); }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame([$path], $changed->toParse);
        $current = $loader->build($changed);
        $this->assertSame([], array_values(array_filter($current->catalogFacts[0]->diagnostics, fn ($row) => in_array($row->code, ['http_limit', 'catalog_limit'], true))));
        $this->assertSame([], $loader->plan()->toParse);
    }

    public function test_memory_or_unclassified_limits_are_retried_even_when_other_limits_are_source_bounded(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $path = 'app/Limited.php';
        $files->put($this->tempPath.'/'.$path, '<?php namespace App; class Limited {}');
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: $cache, sourceOnly: true, catalog: true);
        $loader->load();
        foreach (['memory', null] as $reason) {
            $plan = $loader->plan();
            $entry = $plan->reusable[$path];
            $facts = new CatalogFacts($path, $entry->catalog->elements, $entry->catalog->relations, [
                new CatalogDiagnostic('catalog_limit', 'Source bound reached.', 1, limitReason: 'structure'),
                new CatalogDiagnostic('http_limit', 'Incomplete extraction.', 1, limitReason: $reason),
            ]);
            $entries = $plan->reusable;
            $entries[$path] = new FileGraphEntry(
                $entry->symbols, $entry->edges, $entry->testInvocations, $entry->impact, $facts,
            );
            $this->assertTrue($cache->write(new CachedGraph($plan->signature, $entries)));
            $retry = $loader->plan();
            $this->assertSame([$path], $retry->toParse);
            $this->assertArrayNotHasKey($path, $retry->reusable);
            $this->assertSame(array_keys(array_diff_key($entries, [$path => true])), array_keys($retry->reusable));
            $loader->build($retry);
        }
    }

    public function test_sdk_setter_argument_proof_refreshes_after_source_subtype_override_is_added(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Gateway.php', '<?php namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway { public function getFile($provider, $fileId) {} }');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { $gateway = new Gateway; $provider->useFileGateway($gateway); $gateway->getFile(null, null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls-ai-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Override.php', '<?php namespace App; class Override extends \\Laravel\\Ai\\Providers\\OpenAiProvider { public function useFileGateway(&$gateway) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Override.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $edges($current));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_argument_value_proof_recomposes_when_callee_parameter_becomes_by_reference(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $signature = '<?php namespace App; function accept(%s$worker) {}';
        $files->put($this->tempPath.'/app/Callee.php', sprintf($signature, ''));
        $files->put($this->tempPath.'/app/Worker.php', '<?php namespace App; class Worker { public function perform() {} }');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { $worker = new Worker; accept($worker); $worker->perform(); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $calls = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls' && ($index->elements[$edge['to']]['name'] ?? null) === 'App\\Worker::perform'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $calls($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Callee.php', sprintf($signature, '&'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Callee.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $calls($current));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_earlier_fluent_channel_configuration_recomposes_after_gateway_change_with_cached_consumer(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $gateway = '<?php namespace App; class Files %s {}';
        $files->put($this->tempPath.'/app/Files.php', sprintf($gateway, 'implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway'));
        $files->put($this->tempPath.'/app/Audio.php', '<?php namespace App; class Audio implements \\Laravel\\Ai\\Contracts\\Gateway\\AudioGateway {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { $provider->useFileGateway(new Files)->useAudioGateway(new Audio)->fileGateway()->getFile(null, null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $links = $edges($first, 'uses-ai-provider-setter-gateway');
        $this->assertCount(1, $links);
        $this->assertSame('App\\Files', $links[0]['metadata']['gateway_type']);
        $this->assertCount(1, $edges($first, 'calls-ai-gateway'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Files.php', sprintf($gateway, ''));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Files.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $edges($current, 'uses-ai-provider-setter-gateway'));
        $this->assertSame([], $edges($current, 'calls-ai-gateway'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_fluent_setter_return_recomposes_after_source_override_without_reparsing_consumer(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $provider = '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\OpenAiProvider { %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Gateway.php', '<?php namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; class Fake {} function run(Provider $provider) { $provider->useFileGateway(new Gateway)->fileGateway()->getFile(null, null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first, 'uses-ai-provider-setter-gateway'));
        $this->assertCount(1, $edges($first, 'calls-ai-gateway'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, 'public function useFileGateway($gateway) { return new Fake; }'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $edges($current, 'uses-ai-provider-setter-gateway'));
        $this->assertSame([], $edges($current, 'calls-ai-gateway'));
        $this->assertCount(1, $edges($current, 'invokes-ai-provider-setter'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_constructor_getter_fallback_recomposes_cached_instance_origins_after_gateway_contract_change(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $gateway = '<?php namespace App; class Gateway %s {}';
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'implements \\Laravel\\Ai\\Contracts\\Gateway\\Gateway'));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run() { $provider = new \\Laravel\\Ai\\Providers\\OpenAiProvider(new Gateway, [], null); $copy = $provider; $copy->textGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $links = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'uses-ai-provider-constructor-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $links($first));
        $this->assertSame('App\\Gateway', $links($first)[0]['metadata']['gateway_type']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, ''));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Gateway.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $links($current));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($current, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_source_provider_getter_return_recomposes_after_gateway_contract_change(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $gateway = '<?php namespace App; class Gateway %s { public function getFile($provider, $fileId) {} }';
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway'));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider implements \\Laravel\\Ai\\Contracts\\Providers\\FileProvider { public function fileGateway() { return new Gateway; } }');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Provider $provider, $remote) { $provider->fileGateway()->getFile($remote, null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $links = $edges($first, 'uses-ai-provider-selected-gateway');
        $this->assertCount(1, $links);
        $this->assertSame('app/Provider.php', $links[0]['metadata']['getter_return_sources'][0]['path']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, ''));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Gateway.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertArrayHasKey('app/Provider.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $edges($current, 'uses-ai-provider-selected-gateway'));
        $this->assertSame([], $edges($current, 'calls-ai-gateway'));
    }

    public function test_provider_getter_return_chain_recomposes_when_source_getter_is_overridden(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $provider = '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\OpenAiProvider { %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Provider $provider, \\Laravel\\Ai\\Contracts\\Providers\\FileProvider $remote) { $provider->fileGateway()->getFile($remote, "private-id"); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first, 'uses-ai-provider-selected-gateway'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, 'public function fileGateway() { return null; }'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $edges($current, 'uses-ai-provider-selected-gateway'));
        $this->assertSame([], $edges($current, 'calls-ai-gateway'));
        $this->assertCount(1, $edges($current, 'invokes-ai-provider-getter'));
    }

    public function test_provider_contract_getter_finds_inherited_sdk_implementation_from_cached_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Contracts\\Providers\\FileProvider $provider) { $provider->fileGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'selects-ai-provider-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame([], $edges($first));
        $this->assertContains('package_ai_analysis', array_column($first->diagnostics, 'code'));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\OpenAiProvider {}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $edges($current));
        $this->assertTrue($edges($current)[0]['metadata']['provider_contract_runtime_implementation_required']);
        $files->delete($this->tempPath.'/app/Provider.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertSame([], $removed->toParse);
        $current = new CatalogIndex($loader->build($removed)->catalogFacts);
        $this->assertSame([], $edges($current));
        $this->assertContains('package_ai_analysis', array_column($current->diagnostics, 'code'));
    }

    public function test_provider_contract_getter_finds_new_implementation_without_reparsing_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Contracts\\Providers\\FileProvider $provider) { $provider->fileGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'invokes-ai-provider-getter'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame([], $edges($first));
        $this->assertContains('package_ai_analysis', array_column($first->diagnostics, 'code'));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider implements \\Laravel\\Ai\\Contracts\\Providers\\FileProvider { public function fileGateway() {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $edges($current));
        $this->assertTrue($edges($current)[0]['metadata']['provider_contract_runtime_implementation_required']);
        $files->delete($this->tempPath.'/app/Provider.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertSame([], $removed->toParse);
        $current = new CatalogIndex($loader->build($removed)->catalogFacts);
        $this->assertSame([], $edges($current));
        $this->assertContains('package_ai_analysis', array_column($current->diagnostics, 'code'));
    }

    public function test_sdk_typed_provider_getter_recomposes_source_helper_override_from_cached_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Providers\\AzureOpenAiProvider $provider) { $provider->textGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first, 'selects-ai-provider-gateway'));
        $files->put($this->tempPath.'/app/Override.php', '<?php namespace App; class Override extends \\Laravel\\Ai\\Providers\\AzureOpenAiProvider { protected function azureGateway() {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Override.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(2, $edges($current, 'selects-ai-provider-gateway'));
        $this->assertCount(1, $edges($current, 'invokes-ai-provider-gateway-factory'));
        $files->delete($this->tempPath.'/app/Override.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertSame([], $removed->toParse);
        $current = new CatalogIndex($loader->build($removed)->catalogFacts);
        $this->assertCount(1, $edges($current, 'selects-ai-provider-gateway'));
        $this->assertSame([], $edges($current, 'invokes-ai-provider-gateway-factory'));
    }

    public function test_sdk_typed_provider_getter_recomposes_source_override_from_cached_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { $provider->fileGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first, 'selects-ai-provider-gateway'));
        $files->put($this->tempPath.'/app/Override.php', '<?php namespace App; class Override extends \\Laravel\\Ai\\Providers\\OpenAiProvider { public function fileGateway() {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Override.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(2, $edges($current, 'selects-ai-provider-gateway'));
        $this->assertCount(1, $edges($current, 'invokes-ai-provider-getter'));
        $files->delete($this->tempPath.'/app/Override.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertSame([], $removed->toParse);
        $current = new CatalogIndex($loader->build($removed)->catalogFacts);
        $this->assertCount(1, $edges($current, 'selects-ai-provider-gateway'));
        $this->assertSame([], $edges($current, 'invokes-ai-provider-getter'));
    }

    public function test_provider_getter_subtype_addition_and_removal_keep_sdk_and_source_branches_distinct(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\OpenAiProvider {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Provider $provider) { $provider->fileGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first, 'selects-ai-provider-gateway'));
        $files->put($this->tempPath.'/app/Override.php', '<?php namespace App; class Override extends Provider { public function fileGateway() { return null; } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Override.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $selected = $edges($current, 'selects-ai-provider-gateway');
        $this->assertCount(2, $selected);
        $this->assertSame([true, false], array_column(array_column($selected, 'metadata'), 'runtime_standard_provider_required'));
        $this->assertCount(1, $edges($current, 'invokes-ai-provider-getter'));
        $files->delete($this->tempPath.'/app/Override.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertSame([], $removed->toParse);
        $current = new CatalogIndex($loader->build($removed)->catalogFacts);
        $this->assertCount(1, $edges($current, 'selects-ai-provider-gateway'));
        $this->assertSame([], $edges($current, 'invokes-ai-provider-getter'));
    }

    public function test_provider_gateway_source_shadow_addition_and_removal_recompose_cached_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { $provider->fileGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $selection = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'selects-ai-provider-gateway'))[0]['metadata'];
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertNotNull($selection($first)['default_gateway_candidate']);
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Gateway\\OpenAi; class OpenAiFileGateway {}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertNull($selection($current)['default_gateway_candidate']);
        $this->assertTrue($selection($current)['default_gateway_resolution_required']);
        $files->delete($this->tempPath.'/app/Shadow.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertSame([], $removed->toParse);
        $current = new CatalogIndex($loader->build($removed)->catalogFacts);
        $this->assertNotNull($selection($current)['default_gateway_candidate']);
        $this->assertFalse($selection($current)['default_gateway_resolution_required']);
    }

    public function test_provider_gateway_helper_override_recomposes_from_cache_without_reparsing_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $provider = '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\AzureOpenAiProvider { %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Provider $provider) { $provider->textGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $selected = $edges($first, 'selects-ai-provider-gateway');
        $this->assertSame('Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiGateway', $selected[0]['metadata']['default_gateway_candidate']['type']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, 'protected function azureGateway() { return null; }'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $selected = $edges($current, 'selects-ai-provider-gateway');
        $this->assertNull($selected[0]['metadata']['default_gateway_candidate']);
        $this->assertTrue($selected[0]['metadata']['default_gateway_resolution_required']);
        $factory = $edges($current, 'invokes-ai-provider-gateway-factory');
        $this->assertCount(1, $factory);
        $this->assertSame('App\\Provider::azureGateway', $current->elements[$factory[0]['to']]['name']);
    }

    public function test_provider_getter_cache_recomposes_after_source_override_without_reparsing_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $provider = '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\OpenAiProvider { %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Provider $provider) { $provider->fileGateway(); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertTrue($edges($first, 'selects-ai-provider-gateway')[0]['metadata']['runtime_standard_provider_required']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, 'public function fileGateway() { return null; }'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $selection = $edges($current, 'selects-ai-provider-gateway');
        $this->assertCount(1, $selection);
        $this->assertFalse($selection[0]['metadata']['runtime_standard_provider_required']);
        $this->assertTrue($selection[0]['metadata']['source_method_body_controls_selection']);
        $this->assertCount(1, $edges($current, 'invokes-ai-provider-getter'));
    }

    public function test_legacy_gateway_subtype_callback_branches_recompose_without_reparsing_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Gateway.php', '<?php namespace App; class Gateway extends \\Laravel\\Ai\\Gateway\\OpenAi\\OpenAiGateway {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Gateway $gateway) { $gateway->onToolInvocation(fn () => null, fn () => null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.8.1"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $edges($first, 'ai-gateway-tool-callback'));
        $this->assertSame([], $edges($first, 'passes-ai-gateway-callback'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Override.php', '<?php namespace App; class Override extends Gateway { public function onToolInvocation($before, $after) { $before(); } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Override.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(2, $edges($current, 'ai-gateway-tool-callback'));
        $this->assertCount(2, $edges($current, 'passes-ai-gateway-callback'));
        $this->assertCount(1, $edges($current, 'invokes-ai-gateway-method'));
        $files->delete($this->tempPath.'/app/Override.php');
        clearstatcache();
        $restored = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $edges($restored, 'ai-gateway-tool-callback'));
        $this->assertSame([], $edges($restored, 'passes-ai-gateway-callback'));
    }

    public function test_provider_config_constructor_cache_recomposes_source_override_and_rejects_corruption(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $provider = '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\CohereProvider { %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run($events) { new Provider(config: ["key" => "private-key"], events: $events); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $constructors = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'constructs-ai-provider'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $constructors($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['provider_constructor_arguments'])) {
                $relation['metadata']['provider_constructor_arguments'][0]['name'] = [];
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, 'public function __construct($config, $events) {}'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $constructors(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_concrete_sdk_provider_cache_recomposes_trait_and_class_shadows(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider, $events) { $provider->useFileGateway(new \\Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway); new \\Laravel\\Ai\\Providers\\OpenAiProvider(new \\Laravel\\Ai\\Gateway\\OpenAi\\OpenAiGateway($events), [], $events); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $bindings = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'passes-ai-provider-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $bindings($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Providers\\Concerns; trait HasFileGateway { public function useFileGateway($gateway) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertCount(1, $bindings(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->delete($this->tempPath.'/app/Shadow.php');
        $files->put($this->tempPath.'/app/ClassShadow.php', '<?php namespace Laravel\\Ai\\Providers; class OpenAiProvider {}');
        clearstatcache();
        $this->assertSame([], $bindings(new CatalogIndex($loader->load()->catalogFacts)));
        $files->delete($this->tempPath.'/app/ClassShadow.php');
        clearstatcache();
        $this->assertCount(2, $bindings(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public function test_concrete_sdk_gateway_cache_recomposes_after_source_ancestor_shadow(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Gateway.php', '<?php namespace App; class Gateway extends \\Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Gateway $gateway, \\Laravel\\Ai\\Contracts\\Providers\\FileProvider $provider) { $gateway->getFile(null, "private-id"); $provider->useFileGateway(new Gateway); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => in_array($edge['kind'], ['calls-ai-gateway', 'passes-ai-provider-gateway'], true)));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $edges($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Gateway\\OpenAi; class OpenAiFileGateway {}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $edges(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->delete($this->tempPath.'/app/Shadow.php');
        clearstatcache();
        $this->assertCount(2, $edges(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public function test_provider_constructor_cache_recomposes_after_override_and_rejects_malformed_shape(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $provider = '<?php namespace App; class Provider extends \\Laravel\\Ai\\Providers\\Provider { %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Gateway.php', '<?php namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\Gateway {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run($events) { new Provider(new Gateway, ["key" => "private-credential"], $events); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $bindings = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'passes-ai-provider-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $bindings($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['provider_constructor_arguments'])) {
                $relation['metadata']['provider_constructor_arguments'][0]['unpack'] = 'invalid';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, 'public function __construct($gateway, $config, $events) {}'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $bindings(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($provider, ''));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Providers; class Provider {}');
        clearstatcache();
        $this->assertSame([], $bindings(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public function test_provider_gateway_trait_cache_recomposes_after_shadow_and_source_override(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Provider implements \\Laravel\\Ai\\Contracts\\Providers\\FileProvider { use \\Laravel\\Ai\\Providers\\Concerns\\HasFileGateway; %s }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, ''));
        $files->put($this->tempPath.'/app/Gateway.php', '<?php namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway {}');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Provider $provider) { $provider->useFileGateway(new Gateway); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $bindings = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'passes-ai-provider-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $bindings($first));
        $this->assertTrue($bindings($first)[0]['metadata']['runtime_standard_provider_required']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Providers\\Concerns; trait HasFileGateway { public function useFileGateway($gateway) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $shadow = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $bindings($shadow));
        $this->assertFalse($bindings($shadow)[0]['metadata']['runtime_standard_provider_required']);
        $this->assertTrue($bindings($shadow)[0]['metadata']['source_method_body_controls_configuration']);
        $files->delete($this->tempPath.'/app/Shadow.php');
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, 'private function useFileGateway($gateway) {}'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $bindings(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_provider_gateway_binding_cache_recomposes_contract_changes_and_rejects_corrupt_values(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $gateway = '<?php namespace App; class Gateway %s {}';
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway'));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Contracts\\Providers\\FileProvider $provider) { $provider->useFileGateway(new Gateway); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $bindings = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'passes-ai-provider-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $bindings($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['provider_gateway_value'])) {
                $relation['metadata']['provider_gateway_value']['exact'] = 'invalid';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, ''));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Gateway.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $bindings(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway'));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Contracts\\Providers; interface FileProvider { public function useFileGateway($gateway); }');
        clearstatcache();
        $this->assertSame([], $bindings(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public function test_gateway_returned_closures_recompose_after_cross_file_factory_change(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $factory = '<?php namespace App; function before() {} function callbackFactory() { return %s; }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($factory, 'before(...)'));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Contracts\\Gateway\\TextGateway $gateway) { $gateway->onToolInvocation(callbackFactory(), fn () => null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.8.1"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-gateway-tool-callback'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $callbacks($first));
        $returned = array_values(array_filter($callbacks($first), fn ($edge) => $edge['metadata']['callback_return_path_choice_required']));
        $this->assertCount(1, $returned);
        $this->assertSame('app/Factory.php', $returned[0]['metadata']['callback_return_sources'][0]['path']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($factory, '"private-callback"'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $current = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $callbacks($current));
        $this->assertStringNotContainsString('private-callback', json_encode($current, JSON_THROW_ON_ERROR));
    }

    public function test_gateway_stored_closures_refresh_after_assignment_edit_and_reject_corrupt_cache(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; function run(\\Laravel\\Ai\\Contracts\\Gateway\\TextGateway $gateway) { $before = fn () => null; $after = fn () => null; %s $gateway->onToolInvocation($before, $after); }';
        $files->put($this->tempPath.'/app/Consumer.php', sprintf($source, ''));
        $files->put($this->tempPath.'/app/Unrelated.php', '<?php namespace App; class Unrelated {}');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.8.1"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-gateway-tool-callback'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $callbacks($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['gateway_callback_values'])) {
                $relation['metadata']['gateway_callback_values'][0]['closure'] = 'invalid';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Consumer.php', sprintf($source, '$before = null; $after = null;'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Consumer.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Unrelated.php', $changed->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_legacy_gateway_callbacks_survive_cache_and_recompose_after_source_contract_shadow(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(\\Laravel\\Ai\\Contracts\\Gateway\\TextGateway $gateway) { $gateway->onToolInvocation(invoked: fn () => null, invoking: fn () => null); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.8.1"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-gateway-tool-callback'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $callbacks($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'ai-gateway-call-site') {
                $element['metadata']['arguments'][0]['callback'] = [];
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Contracts\\Gateway; interface TextGateway { public function onToolInvocation($a, $b); }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->delete($this->tempPath.'/app/Shadow.php');
        clearstatcache();
        $this->assertCount(2, $callbacks(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public function test_ai_gateway_named_argument_mapping_recomposes_after_source_parameter_rename(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $gateway = '<?php namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway { public function getFile($provider, $%s) {} }';
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'fileId'));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Gateway $gateway, $provider) { $gateway->getFile(provider: $provider, fileId: "private-id"); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $operations = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls-ai-gateway'));
        $this->assertCount(1, $operations(new CatalogIndex($loader->load()->catalogFacts)));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Gateway.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'method') {
                $element['metadata']['call_parameters']['parameters'][0]['name'] = [];
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'identifier'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Gateway.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $operations(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_ai_gateway_cache_recomposes_source_method_visibility_and_rejects_argument_corruption(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $gateway = '<?php namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway { %s function getFile($provider, $fileId) {} }';
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'public'));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Gateway $gateway, $provider) { $gateway->getFile($provider, "private-id"); }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $operations = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls-ai-gateway'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $operations($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'ai-gateway-call-site') {
                $element['metadata']['arguments'][0]['unpack'] = 'invalid';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Gateway.php', sprintf($gateway, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Gateway.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $operations(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->delete($this->tempPath.'/composer.lock');
        clearstatcache();
        $this->assertSame([], $operations(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public static function aiAttachmentForms(): array
    {
        return [
            'direct' => ['$agent->prompt("private", [\\Laravel\\Ai\\Files\\Document::fromString("private-content")]);'],
            'stored' => ['$files = [\\Laravel\\Ai\\Files\\Document::fromString("private-content")]; $agent->prompt("private", $files);'],
            'factory' => ['$files = Factory::files(); $agent->prompt("private", $files);'],
        ];
    }

    #[DataProvider('aiAttachmentForms')]
    public function test_ai_attachment_cache_roundtrip_rejects_corruption_and_recomposes_after_sdk_shadow(string $consumer): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Agent.php', '<?php namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } }');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Agent $agent) { '.$consumer.' }');
        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; class Factory { public static function files() { return [\\Laravel\\Ai\\Files\\Document::fromString("private-content")]; } }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $attachments = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'uses-ai-attachment'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $attachments($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $valueStored = $stored;
        $valueMutated = false;
        foreach ($valueStored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['ai_attachments'])) {
                $relation['metadata']['ai_attachments']['resolved'] = 'invalid';
                $valueMutated = true;
            } elseif (isset($relation['metadata']['ai_attachment_factory'])) {
                $relation['metadata']['ai_attachment_factory']['receiver'] = '@return:invalid';
                $valueMutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($valueMutated);
        $this->assertNull(CachedGraph::fromArray($valueStored));
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'ai-call-site') {
                $element['metadata']['attachments']['files'][0]['offset'] = -1;
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Files; class Document { public static function fromString($content) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $attachments(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->delete($this->tempPath.'/app/Shadow.php');
        clearstatcache();
        $deleted = $loader->plan();
        $this->assertSame([], $deleted->toParse);
        $this->assertCount(1, $attachments(new CatalogIndex($loader->build($deleted)->catalogFacts)));
        if (str_contains($consumer, 'Factory::files')) {
            $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; class Factory { public static function files() { return []; } }');
            clearstatcache();
            $factoryChanged = $loader->plan();
            $this->assertSame(['app/Factory.php'], $factoryChanged->toParse);
            $this->assertArrayHasKey('app/Consumer.php', $factoryChanged->reusable);
            $this->assertSame([], $attachments(new CatalogIndex($loader->build($factoryChanged)->catalogFacts)));
        }
    }

    public function test_bound_class_ai_callbacks_recompose_after_ancestor_change_without_reparsing_consumer(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Agent.php', '<?php namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } }');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base { protected function complete($response) {} }');
        $files->put($this->tempPath.'/app/Consumer.php', <<<'SOURCE'
<?php namespace App;
class Consumer extends Base {
    public function callback() { return Base::complete(...); }
    public function run(Agent $agent) {
        $agent->stream('private')->then(Base::complete(...));
        $stored = Base::complete(...);
        $agent->queue('private')->then($stored);
        $returned = $this->callback();
        $agent->stream('private')->then($returned);
    }
}
SOURCE);
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-response-then'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(3, $callbacks($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base { private function complete($response) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->delete($this->tempPath.'/app/Base.php');
        clearstatcache();
        $deleted = $loader->plan();
        $this->assertSame([], $deleted->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $deleted->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($deleted)->catalogFacts)));
    }

    public static function aiResponseFactoryForms(): array
    {
        return [
            [' $response = Factory::response($agent); $response->then(fn ($result) => null); '],
            [' Factory::response($agent)->then(fn ($result) => null); '],
            [' response($agent)->then(fn ($result) => null); '],
        ];
    }

    #[DataProvider('aiResponseFactoryForms')]
    public function test_ai_response_return_origins_refresh_without_reparsing_consumer_and_reject_corruption(string $body): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Agent.php', '<?php namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } }');
        $factory = '<?php namespace App; class Factory { public static function response(Agent $agent) { return $agent->%s("private"); } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($factory, 'stream'));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function response(Agent $agent) { return Factory::response($agent); } function consume(Agent $agent) {'.$body.'}');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-response-then'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $callbacks($first));
        $this->assertFalse($callbacks($first)[0]['metadata']['queue_delivery_required']);
        $this->assertSame('app/Factory.php', $first->elements[$callbacks($first)[0]['from']]['path']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Factory.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['value_origin'])) {
                $relation['metadata']['value_origin'] = 'invalid';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($factory, 'queue'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $callbacks($next));
        $this->assertTrue($callbacks($next)[0]['metadata']['queue_delivery_required']);
        $this->assertTrue($callbacks($next)[0]['metadata']['response_return_path_choice_required']);
        $files->delete($this->tempPath.'/app/Factory.php');
        clearstatcache();
        $removed = $loader->plan();
        $this->assertArrayHasKey('app/Consumer.php', $removed->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($removed)->catalogFacts)));
    }

    public function test_typed_ai_response_callbacks_recompose_after_method_visibility_sdk_shadow_and_package_removal(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', <<<'SOURCE'
<?php namespace App;
function consume(\Laravel\Ai\Responses\StreamableAgentResponse $response) { $response->then(Service::finish(...)); }
SOURCE);
        $service = '<?php namespace App; class Service { %s static function finish($response) {} }';
        $files->put($this->tempPath.'/app/Service.php', sprintf($service, 'public'));
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-response-then'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $callbacks($first));
        $this->assertTrue($callbacks($first)[0]['metadata']['agent_origin_unknown']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Service.php', sprintf($service, 'private'));
        clearstatcache();
        $hidden = $loader->plan();
        $this->assertSame(['app/Service.php'], $hidden->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $hidden->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($hidden)->catalogFacts)));
        $files->put($this->tempPath.'/app/Service.php', sprintf($service, 'public'));
        clearstatcache();
        $this->assertCount(1, $callbacks(new CatalogIndex($loader->load()->catalogFacts)));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Responses; class StreamableAgentResponse {}');
        clearstatcache();
        $shadow = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $shadow->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $shadow->reusable);
        $shadowed = new CatalogIndex($loader->build($shadow)->catalogFacts);
        $this->assertSame([], $callbacks($shadowed));
        $this->assertContains('package_ai_analysis', array_column($shadowed->diagnostics, 'code'));
        $files->delete($this->tempPath.'/app/Shadow.php');
        $files->delete($this->tempPath.'/composer.lock');
        clearstatcache();
        $absent = $loader->plan();
        $this->assertArrayHasKey('app/Consumer.php', $absent->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($absent)->catalogFacts)));
    }

    public function test_ai_callable_cache_recomposes_visibility_and_deletion_and_rejects_corrupt_selectors(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Agent.php', '<?php namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } }');
        $files->put($this->tempPath.'/app/Consumer.php', <<<'SOURCE'
<?php namespace App;
function run(Agent $agent, Service $service) {
    $agent->stream('private-prompt')->then(Service::finish(...));
    $agent->stream('private-prompt')->then([Service::class, 'finish']);
    $agent->stream('private-prompt')->then('App\\Service::finish');
    $agent->stream('private-prompt')->each([$service, 'delta']);
    $callback = Service::finish(...);
    $agent->stream('private-prompt')->then($callback);
    $instance = [$service, 'delta'];
    $agent->stream('private-prompt')->each($instance);
    $named = 'App\\Service::finish';
    $agent->stream('private-prompt')->then($named);
    $namedArray = [1 => 'finish', 0 => 'App\\Service'];
    $agent->stream('private-prompt')->then($namedArray);
    $agent->queue('private-prompt')->then(Factory::callback());
    $agent->stream('private-prompt')->then(Factory::named());
}
SOURCE);
        $service = '<?php namespace App; class Service { %s static function finish($response) {} public function delta($event) {} }';
        $files->put($this->tempPath.'/app/Service.php', sprintf($service, 'public'));
        $factory = '<?php namespace App; class Handler { public function __invoke($response) {} } class Factory { public static function callback() { %s } public static function named() { %s } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($factory, 'return Service::finish(...);', 'return "App\\Service::finish";'));
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => str_starts_with($edge['kind'], 'ai-response-')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(10, $callbacks($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $valueStored = $stored;
        $nullStored = $stored;
        foreach ($nullStored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['callback_value'])) {
                $relation['metadata']['callback_value'] = null;
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($nullStored));
        $namedStored = $stored;
        $namedMutated = false;
        foreach ($namedStored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['metadata']['callback_value']['receiver'] ?? '', '@named-callable:')) {
                $relation['metadata']['callback_value']['receiver'] = '@named-callable:["invalid",null,null]';
                $namedMutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($namedMutated);
        $this->assertNull(CachedGraph::fromArray($namedStored));
        $returnedStored = $stored;
        $returnedMutated = false;
        foreach ($returnedStored['entries']['app/Factory.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['metadata']['receiver'] ?? '', '@named-callable:')) {
                $relation['metadata']['receiver'] = '@named-callable:["invalid",null,null]';
                $returnedMutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($returnedMutated);
        $this->assertNull(CachedGraph::fromArray($returnedStored));
        $valueMutated = false;
        foreach ($valueStored['entries']['app/Consumer.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['callback_value'])) {
                $relation['metadata']['callback_value']['closure'] = 'invalid';
                $valueMutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($valueMutated);
        $this->assertNull(CachedGraph::fromArray($valueStored));
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['elements'] as &$element) {
            if ($element['metadata']['named_method'] ?? null) {
                $element['metadata']['named_method']['method_hash'] = 'invalid';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($factory, 'return new Handler;', 'return "missing";'));
        clearstatcache();
        $factoryChanged = $loader->plan();
        $this->assertSame(['app/Factory.php'], $factoryChanged->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $factoryChanged->reusable);
        $this->assertArrayHasKey('app/Service.php', $factoryChanged->reusable);
        $this->assertCount(8, $callbacks(new CatalogIndex($loader->build($factoryChanged)->catalogFacts)));
        $files->put($this->tempPath.'/app/Service.php', sprintf($service, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Service.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $hidden = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(2, $callbacks($hidden));
        $this->assertSame('App\\Service::delta', $hidden->elements[$callbacks($hidden)[0]['to']]['name']);
        $files->delete($this->tempPath.'/app/Service.php');
        clearstatcache();
        $deleted = $loader->plan();
        $this->assertArrayHasKey('app/Consumer.php', $deleted->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($deleted)->catalogFacts)));
    }

    public static function aiResponseStorageForms(): array
    {
        return [
            [' $agent->stream("private-prompt")->then(fn ($response) => null); $agent->queue("private-prompt")->catch(fn ($error) => null); ', 2],
            [' $stream = $agent->stream("private-prompt"); $queued = $agent->queue("private-prompt"); $stream->then(fn ($response) => null); $queued->catch(fn ($error) => null); ', 2],
            [' $stream = $agent->stream("private-prompt")->each(fn ($event) => null); $stream->then(fn ($response) => null); $queued = $agent->queue("private-prompt"); $queued->catch(fn ($error) => null); ', 3],
        ];
    }

    #[DataProvider('aiResponseStorageForms')]
    public function test_ai_response_callbacks_survive_cache_and_recompose_after_agent_override_and_response_shadow(string $consumer, int $expected): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $agent = '<?php namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } %s }';
        $files->put($this->tempPath.'/app/Agent.php', sprintf($agent, ''));
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; function run(Agent $agent) {'.$consumer.'}');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $callbacks = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => str_starts_with($edge['kind'], 'ai-response-')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount($expected, $callbacks($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Consumer.php']['g']['elements'] as &$element) {
            if (in_array($element['kind'], ['ai-response-callback', 'source-response-callback'], true)) {
                $element['metadata']['chain_valid'] = 'true';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Agent.php', sprintf($agent, 'public function stream($prompt) {}'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Agent.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $callbacks($next));
        $this->assertSame('ai-response-catch', $callbacks($next)[0]['kind']);
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Laravel\\Ai\\Responses; class QueuedAgentResponse {}');
        clearstatcache();
        $shadow = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $shadow->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $shadow->reusable);
        $this->assertSame([], $callbacks(new CatalogIndex($loader->build($shadow)->catalogFacts)));
    }

    public function test_aliased_ai_tools_trait_edit_recomposes_delivery_without_reparsing_agent(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $trait = '<?php namespace App; trait Supplies { public function available(): iterable { return [new %s]; } } class First implements \\Laravel\\Ai\\Contracts\\Tool { public function handle($request) {} } class Second implements \\Laravel\\Ai\\Contracts\\Tool { public function handle($request) {} }';
        $files->put($this->tempPath.'/app/Supplies.php', sprintf($trait, 'First'));
        $agent = '<?php namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent, \\Laravel\\Ai\\Contracts\\HasTools { use \\Laravel\\Ai\\Promptable; use Supplies { available as %s tools; } public function instructions(): string { return ""; } }';
        $files->put($this->tempPath.'/app/Agent.php', sprintf($agent, 'public'));
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $handlers = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-selected-tool-handler'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $handlers($first));
        $this->assertSame('App\\First::handle', $first->elements[$handlers($first)[0]['to']]['name']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Supplies.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'source-method-object-list') {
                unset($element['metadata']['conditional']);
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Supplies.php', sprintf($trait, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Supplies.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Agent.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $handlers($next));
        $this->assertSame('App\\Second::handle', $next->elements[$handlers($next)[0]['to']]['name']);
        $files->put($this->tempPath.'/app/Agent.php', sprintf($agent, 'private'));
        clearstatcache();
        $hidden = $loader->plan();
        $this->assertSame(['app/Agent.php'], $hidden->toParse);
        $this->assertArrayHasKey('app/Supplies.php', $hidden->reusable);
        $this->assertSame([], $handlers(new CatalogIndex($loader->build($hidden)->catalogFacts)));
    }

    public function test_nested_ai_agent_prompt_override_and_package_change_recompose_without_reparsing_parent(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $child = '<?php namespace App; class Child implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return "private-instructions"; } %s }';
        $files->put($this->tempPath.'/app/Child.php', sprintf($child, ''));
        $files->put($this->tempPath.'/app/ParentAgent.php', '<?php namespace App; class ParentAgent implements \\Laravel\\Ai\\Contracts\\Agent, \\Laravel\\Ai\\Contracts\\HasTools { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } public function tools(): iterable { return [new Child]; } }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.8.0","source":{"reference":"7da9fd8cf7b66c755902f77498232c938b52af10"}}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $selected = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'ai-selected-agent'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $selected($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Child.php', sprintf($child, 'public function prompt($prompt) { return null; }'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Child.php'], $changed->toParse);
        $this->assertArrayHasKey('app/ParentAgent.php', $changed->reusable);
        $this->assertSame([], $selected(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->put($this->tempPath.'/app/Child.php', sprintf($child, ''));
        clearstatcache();
        $this->assertCount(1, $selected(new CatalogIndex($loader->load()->catalogFacts)));
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/ai","version":"0.11.2","source":{"reference":"ee2c5162838d440c4e2e629ea93c8c87e838eaed"}}]}');
        clearstatcache();
        $upgraded = $loader->plan();
        $this->assertSame(['composer.lock'], $upgraded->toParse);
        $this->assertArrayHasKey('app/ParentAgent.php', $upgraded->reusable);
        $this->assertArrayHasKey('app/Child.php', $upgraded->reusable);
        $next = new CatalogIndex($loader->build($upgraded)->catalogFacts);
        $this->assertCount(1, $selected($next));
        $this->assertSame('0.11.2.0', $selected($next)[0]['metadata']['version']);
        $files->delete($this->tempPath.'/composer.lock');
        clearstatcache();
        $this->assertSame([], $selected(new CatalogIndex($loader->load()->catalogFacts)));
    }

    public function test_conditionable_source_return_edit_recomposes_receiver_fallback_and_rejects_corrupt_descriptor(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Custom implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($a, $v, $f) {} } class Factory { public static function build($rule) { %s } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'return $rule;'));
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::file()->when(true, Factory::build(...))]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $rules = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $rules($first));
        $this->assertSame('file', $rules($first)[0]['metadata']['factory_method']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['conditionable_fallback'])) {
                $relation['metadata']['conditionable_fallback'] = null;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'return new Custom;'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $rules($next));
        $this->assertSame('App\\Custom', $next->elements[$rules($next)[0]['to']]['name']);
        $this->assertArrayNotHasKey('factory_method', $rules($next)[0]['metadata']);
    }

    public function test_conditionable_first_class_callback_visibility_edit_reuses_callers(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Custom implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($a, $v, $f) {} } class Factory { %s static function build($rule) { return new Custom; } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { $callback = Factory::build(...); \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::file()->when(true, $callback)]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $relations = fn ($index) => array_values(array_filter($index->relations, fn ($row) => in_array($row['kind'], ['validation-rule', 'validation-rule-builder-callback'], true)));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $relations($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returned-rule-builder-callable') {
                $relation['metadata']['callback_exact'] = 'invalid';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame([], $relations(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_conditionable_callbacks_recompose_after_shadow_contract_edit_and_reject_corrupt_context(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; class Custom implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($a, $v, $f) {} } function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::file()->when(true, fn () => new Custom)]]); }');
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Illuminate\\Validation\\Rules; class Unrelated {}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $builders = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule-builder-callback'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $builders($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['conditionable_contexts'])) {
                $relation['metadata']['conditionable_contexts'][0]['method'] = 'execute';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Shadow.php', '<?php namespace Illuminate\\Validation\\Rules; class File {}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Shadow.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame([], $builders(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_nested_file_rule_contract_edit_reuses_caller_and_rejects_deferred_context_corruption(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Custom.php', '<?php namespace App; class Custom implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} }');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::file()->rules([new Custom])]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $customRules = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule' && $index->elements[$row['to']]['name'] === 'App\\Custom'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $customRules($first));
        $this->assertSame([['factory' => 'file', 'modifier' => 'rules']], $customRules($first)[0]['metadata']['deferred_rule_contexts']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['deferred_rule_contexts'])) {
                $relation['metadata']['deferred_rule_contexts'][0]['modifier'] = 'execute';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Custom.php', '<?php namespace App; class Custom { public function validate($attribute, $value, $fail) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Custom.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame([], $customRules(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_rule_fluent_database_callback_visibility_edit_reuses_chain_and_rejects_corruption(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Queries { %s static function restrict($query) {} }';
        $files->put($this->tempPath.'/app/Queries.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::unique("users")->using(Queries::restrict(...))->ignore(1)]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $rules = $edges($first, 'validation-rule');
        $this->assertCount(1, $rules);
        $this->assertSame(['using', 'ignore'], $rules[0]['metadata']['fluent_methods']);
        $this->assertCount(1, $edges($first, 'validation-rule-query-callback'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returned-framework-rule') {
                $relation['metadata']['fluent_methods'] = ['using', true];
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Queries.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Queries.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $edges($next, 'validation-rule'));
        $this->assertSame([], $edges($next, 'validation-rule-query-callback'));
    }

    public function test_first_class_rule_condition_visibility_edit_recomposes_reusable_validation_site(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Condition { %s static function check() {} }';
        $files->put($this->tempPath.'/app/Condition.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::requiredIf(Condition::check(...))]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $conditions = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule-condition'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $conditions($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returned-rule-condition-callable') {
                $relation['metadata']['callback_exact'] = 'invalid';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Condition.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Condition.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame([], $conditions(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_standard_rule_profile_edit_reuses_php_and_rejects_corrupt_factory_descriptor(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/framework","version":"12.41.1"}]}');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { \\validator([], ["field" => [\\Illuminate\\Validation\\Rule::requiredUnless(fn () => true)]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame([], $edges($first, 'validation-rule'));
        $this->assertSame([], $edges($first, 'validation-rule-condition'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'framework-validation-rule') {
                $element['metadata']['factory_method'] = true;
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/framework","version":"v13.18.0"}]}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['composer.lock'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $rules = $edges($next, 'validation-rule');
        $this->assertCount(1, $rules);
        $this->assertSame([13], $rules[0]['metadata']['framework_versions']);
        $this->assertCount(1, $edges($next, 'validation-rule-condition'));
    }

    public function test_custom_resource_fluent_return_edit_recomposes_cached_caller(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Other extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($request) {} } class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { %s function additional($data) { return new Other([]); } public function toArray($request) {} }';
        $files->put($this->tempPath.'/app/Resources.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function one() { return Item::make([])->additional([]); } function two() { Item::make([])->additional([])->resolve(); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $returns = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'returns-resource' && $index->elements[$row['from']]['name'] === 'App\\one'));
        $operations = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'invokes-resource-operation'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $returns($first));
        $this->assertSame('App\\Other', $first->elements[$returns($first)[0]['to']]['name']);
        $this->assertCount(1, $operations($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Resources.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Resources.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $returns($next));
        $this->assertSame([], $operations($next));
    }

    public function test_json_encoding_refreshes_source_contract_and_rejects_corrupt_receiver(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Item.php', '<?php namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($request) {} }');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { $item = new Item([]); \\json_encode($item); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $operations = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'invokes-resource-operation'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $operations($first));
        $this->assertSame('json_encode', $operations($first)[0]['metadata']['invocation_form']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Caller.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['json_serialization_receiver'])) {
                $relation['metadata']['json_serialization_receiver'] = true;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Item.php', '<?php namespace App; class Item { public function toArray($request) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Item.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame([], $operations(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_resource_framework_profile_edit_recomposes_reusable_php_facts(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/framework","version":"12.41.1"}]}');
        $files->put($this->tempPath.'/app/Resources.php', '<?php namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function resolve($request = null) {} public function toArray($request) {} } class Items extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection { public $collects = Item::class; } function run() { (new Items([]))->resolve(); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $hooks = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'resource-response-hook'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['toArray'], array_values(array_unique(array_column(array_column($hooks($first), 'metadata'), 'method'))));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"laravel/framework","version":"v13.18.0"}]}');
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Resources.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'property') {
                $element['metadata']['visibility'] = true;
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['composer.lock'], $changed->toParse);
        $this->assertArrayHasKey('app/Resources.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['resolve'], array_values(array_unique(array_column(array_column($hooks($next), 'metadata'), 'method'))));
        foreach ($hooks($next) as $row) {
            $this->assertSame([13], $row['metadata']['framework_versions']);
        }
    }

    public function test_resource_fluent_factory_and_explicit_operation_refresh_after_visibility_edit(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Item extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request) { return []; } } class Factory { %s static function make() { return new Item; } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Service.php', '<?php namespace App; function returned() { return Factory::make()->additional([]); } function explicit() { Factory::make()->resolve(); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index, $kind) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $returns = array_values(array_filter($edges($first, 'returns-resource'), fn ($row) => $first->elements[$row['from']]['name'] === 'App\returned'));
        $this->assertCount(1, $returns);
        $this->assertCount(1, $edges($first, 'invokes-resource-operation'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returns-value' && isset($relation['metadata']['factory_call']['receiver_call'])) {
                $relation['metadata']['factory_call']['receiver_call']['binding'] = true;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Service.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], array_values(array_filter($edges($next, 'returns-resource'), fn ($row) => $next->elements[$row['from']]['name'] === 'App\returned')));
        $this->assertSame([], $edges($next, 'invokes-resource-operation'));
    }

    public function test_nested_rule_factory_visibility_edit_recomposes_return_chain_and_rejects_corrupt_form(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Valid implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} } class Builder { %s static function make() { return new Valid; } }';
        $files->put($this->tempPath.'/app/Builder.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; class Factory { public static function make() { $value = Builder::make(); return $value; } }');
        $files->put($this->tempPath.'/app/Service.php', '<?php namespace App; function run() { \validator([], ["field" => [Factory::make()]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $rules = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $rules($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Factory.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returns-value') {
                $relation['metadata']['factory_call']['form'] = true;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Builder.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Builder.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Factory.php', $changed->reusable);
        $this->assertArrayHasKey('app/Service.php', $changed->reusable);
        $this->assertSame([], $rules(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_rule_factory_visibility_edit_reuses_caller_and_rejects_corrupt_call_scope(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class Valid implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} } class Factory { %s static function make() { return new Valid; } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'public'));
        $files->put($this->tempPath.'/app/Service.php', '<?php namespace App; function run() { \validator([], ["field" => [Factory::make()]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $rules = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $rules($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returned-rule-factory') {
                $relation['metadata']['factory_call']['creator'] = true;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'private'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Service.php', $changed->reusable);
        $this->assertSame([], $rules(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_conditional_rule_branch_and_shadow_edits_refresh_cached_candidates(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Framework.php', '<?php namespace App; class Other {}');
        $files->put($this->tempPath.'/app/Rule.php', '<?php namespace App; class First implements \Illuminate\Contracts\Validation\ValidationRule { public function validate($attribute, $value, $fail) {} } class Second extends First {}');
        $source = '<?php namespace App; function run() { \validator([], ["field" => [\Illuminate\Validation\Rule::when(true, fn () => [new %s])]]); }';
        $files->put($this->tempPath.'/app/Service.php', sprintf($source, 'First'));
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $rules = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\First'], $rules($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['relations'] as &$relation) {
            if (isset($relation['metadata']['framework_rule_contexts'])) {
                $relation['metadata']['framework_rule_contexts'][0]['method'] = true;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Service.php', sprintf($source, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Service.php'], $changed->toParse);
        $this->assertSame(['App\Second'], $rules(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $files->put($this->tempPath.'/app/Framework.php', '<?php namespace Illuminate\Validation; class Rule { public static function when($condition, $rules) {} }');
        clearstatcache();
        $shadow = $loader->plan();
        $this->assertSame(['app/Framework.php'], $shadow->toParse);
        $this->assertArrayHasKey('app/Service.php', $shadow->reusable);
        $this->assertSame([], $rules(new CatalogIndex($loader->build($shadow)->catalogFacts)));
    }

    public function test_validation_property_type_edit_recomposes_cached_caller_and_rejects_corrupt_union(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base { public \Illuminate\Http\Request $request; }');
        $files->put($this->tempPath.'/app/Service.php', '<?php namespace App; class Service extends Base { public function run(\Illuminate\Http\Request|\stdClass $other) { $this->request->validate(["field" => []]); $other->validate(["field" => []]); } }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $invocations = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'invokes-validation'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $invocations($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'validation-site' && str_starts_with($element['metadata']['receiver_type'] ?? '', '@types:')) {
                $element['metadata']['receiver_type'] = '@types:'.json_encode(['Illuminate\\Http\\Request', true], JSON_THROW_ON_ERROR);
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base { public \stdClass $request; }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Service.php', $changed->reusable);
        $this->assertCount(1, $invocations(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_validator_instance_invocation_refreshes_and_rejects_corrupt_method(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php function run() { $v = \Illuminate\Support\Facades\Validator::make([], ["field" => []]); $v->%s(); }';
        $files->put($this->tempPath.'/app/Service.php', sprintf($source, 'validate'));
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $invocations = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'invokes-validation'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $invocations($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'validator-instance-candidate') {
                $relation['metadata']['validator_method'] = true;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Service.php', sprintf($source, 'setRules')."\n// changed validator behavior");
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Service.php'], $changed->toParse);
        $this->assertSame([], $invocations(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_request_validation_contract_edit_reuses_caller_and_rejects_corrupt_receiver(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Request.php', '<?php namespace App; class Request extends \\Illuminate\\Http\\Request {}');
        $files->put($this->tempPath.'/app/Service.php', '<?php namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } function run(Request $request) { $request->validate(["field" => [new Valid]]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $rules = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $rules($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'validation-site') {
                $element['metadata']['receiver_type'] = true;
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Request.php', '<?php namespace App; class Request {}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Request.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Service.php', $changed->reusable);
        $this->assertSame([], $rules(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_standalone_validation_mode_edit_reuses_rules_and_rejects_corrupt_site(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Rule.php', '<?php namespace App; class Valid implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} }');
        $source = '<?php namespace App; function run() { \\Illuminate\\Support\\Facades\\Validator::%s([], ["field" => [new Valid]]); }';
        $files->put($this->tempPath.'/app/Service.php', sprintf($source, 'make'));
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $modes = fn ($index) => array_values(array_map(fn ($row) => $row['metadata']['validation_mode'], array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['constructs-validator'], $modes($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Service.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'validation-site') {
                $element['metadata']['operation'] = true;
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Service.php', sprintf($source, 'validate'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Service.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Rule.php', $changed->reusable);
        $this->assertSame(['invokes-validation'], $modes(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_validation_factory_edit_recomposes_cached_request_and_rejects_corrupt_return_descriptor(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $source = '<?php namespace App; class First implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} } class Second extends First {} class Factory { public static function make() { return new %s; } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'First'));
        $files->put($this->tempPath.'/app/Request.php', '<?php namespace App; class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() { $rules = [Factory::make()]; return ["field" => $rules]; } }');
        $files->put($this->tempPath.'/routes/web.php', '<?php \\Illuminate\\Support\\Facades\\Route::post("validate", fn (App\\Request $request) => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\First'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Request.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returned-rule-factory') {
                $relation['metadata']['return_receiver'] = '@return:malformed';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Request.php', $changed->reusable);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertSame(['App\\Second'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_resource_fluent_override_edit_removes_cached_default_contract(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Item.php', '<?php namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource {}');
        $files->put($this->tempPath.'/app/Controller.php', '<?php namespace App; function response() { return Item::make([])->additional([]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $returns = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'returns-resource'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $returns($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Item.php', '<?php namespace App; class Item extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function additional(array $data) { return new \\stdClass; } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Item.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Controller.php', $changed->reusable);
        $this->assertSame([], $returns(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_resource_collection_selector_edit_reuses_return_facts_and_rejects_corrupt_metadata(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Items.php', '<?php namespace App; class First extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($r) { return []; } } class Second extends First {}');
        $source = '<?php namespace App; #[\\Illuminate\\Http\\Resources\\Attributes\\Collects(%s::class)] class Items extends \\Illuminate\\Http\\Resources\\Json\\ResourceCollection {}';
        $files->put($this->tempPath.'/app/Collection.php', sprintf($source, 'First'));
        $files->put($this->tempPath.'/app/Controller.php', '<?php namespace App; function response() { return new Items([]); }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'resource-collection-item')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\First'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Collection.php']['g']['elements'] as &$element) {
            if (isset($element['metadata']['resource_collects'])) {
                $element['metadata']['resource_collects']['resolved'] = 'corrupt';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Collection.php', sprintf($source, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Collection.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Controller.php', $changed->reusable);
        $this->assertArrayHasKey('app/Items.php', $changed->reusable);
        $this->assertSame(['App\\Second'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_auth_factory_chain_edit_recomposes_cached_registration(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class First implements \\Illuminate\\Contracts\\Auth\\Guard {} class Second implements \\Illuminate\\Contracts\\Auth\\Guard {} function makeGuard() { return new %s; } class Factory { public static function make() { return makeGuard(); } }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'First'));
        $files->put($this->tempPath.'/app/Register.php', '<?php \\Illuminate\\Support\\Facades\\Auth::extend("custom", fn () => App\\Factory::make());');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'auth-factory-result')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\First'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Register.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['App\\Second'], $targets($next));
        $results = array_values(array_filter($next->relations, fn ($row) => $row['kind'] === 'auth-factory-result'));
        $this->assertCount(2, $results[0]['metadata']['return_chain_sources']);
        $files->put($this->tempPath.'/app/Factory.php', str_replace('public static', 'private static', sprintf($source, 'Second')));
        clearstatcache();
        $private = $loader->plan();
        $this->assertSame(['app/Factory.php'], $private->toParse);
        $this->assertArrayHasKey('app/Register.php', $private->reusable);
        $this->assertSame([], $targets(new CatalogIndex($loader->build($private)->catalogFacts)));
    }

    public function test_console_trait_selection_edit_recomposes_cached_callsite_and_rejects_corrupt_creator_scope(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Traits.php', '<?php namespace App; trait First { public static function run() {} } trait Second { public static function run() {} }');
        $source = '<?php namespace App; class Callbacks { use First, Second { %s } }';
        $files->put($this->tempPath.'/app/Callbacks.php', sprintf($source, 'First::run insteadof Second;'));
        $files->put($this->tempPath.'/routes/console.php', '<?php \\Illuminate\\Support\\Facades\\Schedule::call(App\\Callbacks::run(...));');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'schedule-call')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\First::run'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['routes/console.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'execution-template') {
                foreach ($relation['metadata']['operations'] as &$operation) {
                    if ($operation['kind'] === 'schedule' && $operation['method'] === 'call') {
                        $operation['args']['callback']['creator_class'] = true;
                        $mutated = true;
                    }
                }
                unset($operation);
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Callbacks.php', sprintf($source, 'Second::run insteadof First; /* changed */'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Callbacks.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Traits.php', $changed->reusable);
        $this->assertArrayHasKey('routes/console.php', $changed->reusable);
        $this->assertSame(['App\\Second::run'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_schedule_callable_edit_reuses_function_facts_and_rejects_corrupt_descriptor(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Callbacks.php', '<?php namespace App; function first() {} function secondCallback() {}');
        $source = '<?php \\Illuminate\\Support\\Facades\\Schedule::call("App\\\\%s");';
        $files->put($this->tempPath.'/routes/console.php', sprintf($source, 'first'));
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'schedule-call')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\first'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['routes/console.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'execution-template') {
                foreach ($relation['metadata']['operations'] as &$operation) {
                    if ($operation['kind'] === 'schedule' && $operation['method'] === 'call') {
                        $operation['args']['callback']['symbol'] = true;
                        $mutated = true;
                    }
                }
                unset($operation);
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/routes/console.php', sprintf($source, 'secondCallback'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['routes/console.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Callbacks.php', $changed->reusable);
        $this->assertSame(['App\\secondCallback'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_console_route_file_change_recomposes_cached_commands_and_rejects_corrupt_null_evidence(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $source = '<?php \Illuminate\Foundation\Application::configure()->withRouting(commands: base_path("routes/%s.php"));';
        $files->put($this->tempPath.'/bootstrap/app.php', sprintf($source, 'first'));
        $files->put($this->tempPath.'/routes/first.php', '<?php \Illuminate\Support\Facades\Artisan::command("first:run", fn () => 1);');
        $files->put($this->tempPath.'/routes/second.php', '<?php \Illuminate\Support\Facades\Artisan::command("second:run", fn () => 2);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'registers-console-file')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['routes/first.php'], $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['bootstrap/app.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'execution-template') {
                foreach ($relation['metadata']['operations'] as &$operation) {
                    if ($operation['method'] === 'withrouting') {
                        $operation['args']['commands_null'] = 'corrupt';
                        $mutated = true;
                    }
                }
                unset($operation);
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/bootstrap/app.php', sprintf($source, 'second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['bootstrap/app.php'], $changed->toParse);
        $this->assertArrayHasKey('routes/first.php', $changed->reusable);
        $this->assertArrayHasKey('routes/second.php', $changed->reusable);
        $next = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['routes/second.php'], $registered($next));
        foreach ($next->elements as $element) {
            if ($element['name'] === 'routes/first.php' && $element['kind'] === 'file') {
                $this->assertArrayNotHasKey('console_file_registrations', $element['metadata']);
            }
        }
    }

    public function test_aliased_middleware_return_edit_recomposes_cached_controller_and_routes(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $source = '<?php namespace App; class First { public function handle($r, $n) {} } class Second { public function handle($r, $n) {} } trait Filters { public static function selected() { $filters = [%s::class]; return $filters; } public static function payload() { return ["credential-secret"]; } }';
        $files->put($this->tempPath.'/app/Filters.php', sprintf($source, 'First'));
        $files->put($this->tempPath.'/app/Controller.php', '<?php namespace App; class Controller implements \Illuminate\Routing\Controllers\HasMiddleware { use Filters { selected as middleware; } public function run() {} }');
        $files->put($this->tempPath.'/routes/web.php', '<?php \Illuminate\Support\Facades\Route::get("run", [App\Controller::class, "run"]);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'http-middleware')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\First::handle'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $this->assertStringNotContainsString('credential-secret', json_encode($stored));
        $mutated = false;
        foreach ($stored['entries']['app/Filters.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'execution-template') {
                foreach ($relation['metadata']['classes'] as &$class) {
                    if ($class['name'] === 'App\Filters') {
                        $class['methods']['selected']['middleware_candidates'][0] = true;
                        $mutated = true;
                    }
                }
                unset($class);
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Filters.php', sprintf($source, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Filters.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Controller.php', $changed->reusable);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertSame(['App\Second::handle'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_controller_middleware_attribute_edit_recomposes_cached_child_and_routes(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $source = '<?php namespace App; class Audit { public function handle($request, $next) {} } #[\Illuminate\Routing\Attributes\Controllers\Middleware(Audit::class, only: ["%s"])] class Base {}';
        $files->put($this->tempPath.'/app/Base.php', sprintf($source, 'show'));
        $files->put($this->tempPath.'/app/Controller.php', '<?php namespace App; class Controller extends Base { public function show() {} public function store() {} }');
        $files->put($this->tempPath.'/routes/web.php', '<?php use Illuminate\Support\Facades\Route; Route::get("show", [App\Controller::class, "show"]); Route::post("store", [App\Controller::class, "store"]);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $uris = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['from']]['metadata']['uri'], array_filter($index->relations, fn ($row) => $row['kind'] === 'http-middleware')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['/show'], $uris($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Base.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'attribute') {
                $element['metadata']['controller_middleware']['filters_resolved'] = 'corrupt';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Base.php', sprintf($source, 'store'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Controller.php', $changed->reusable);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertSame(['/store'], $uris(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_auth_first_class_factory_edit_recomposes_cached_registration_and_rejects_corrupt_evidence(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $source = '<?php namespace App; class First implements \Illuminate\Contracts\Auth\Guard {} class Second implements \Illuminate\Contracts\Auth\Guard {} function guardFactory() { return new %s; }';
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'First'));
        $files->put($this->tempPath.'/app/Register.php', '<?php namespace App; \Illuminate\Support\Facades\Auth::extend("custom", guardFactory(...));');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'auth-factory-result')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\First'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Register.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'execution-template') {
                $relation['metadata']['operations'][0]['args']['callback']['first_class'] = 'corrupt';
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Factory.php', sprintf($source, 'Second'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Register.php', $changed->reusable);
        $this->assertSame(['App\Second'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_controller_middleware_trait_precedence_edit_reuses_route_and_trait_facts(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Traits.php', '<?php namespace App; class Audit { public function handle($request, $next) {} } class Extra { public function handle($request, $next) {} } trait First { public static function middleware() { return [Audit::class]; } } trait Second { public static function middleware() { return [Extra::class]; } }');
        $source = '<?php namespace App; class Controller implements \Illuminate\Routing\Controllers\HasMiddleware { use First, Second { %s } public function run() {} }';
        $files->put($this->tempPath.'/app/Controller.php', sprintf($source, 'First::middleware insteadof Second;'));
        $files->put($this->tempPath.'/routes/web.php', '<?php \Illuminate\Support\Facades\Route::get("selected", [App\Controller::class, "run"]);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'http-middleware')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\Audit::handle'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Controller.php', sprintf($source, 'Second::middleware insteadof First; middleware as public;'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Controller.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Traits.php', $changed->reusable);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertSame(['App\Extra::handle'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_exception_reporting_control_edit_recomposes_cached_callback_declarations(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Failure extends \\Exception {} class OtherFailure extends \\Exception {} class Reporter { public static function report(Failure $e) {} }');
        $source = '<?php \\Illuminate\\Foundation\\Application::configure()->withExceptions(function ($exceptions) { $exceptions->dontReport(App\\%s::class); $exceptions->report(App\\Reporter::report(...))->stop(); });';
        $files->put($this->tempPath.'/bootstrap/app.php', sprintf($source, 'Failure'));
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $reports = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'exception-report-registration'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\Failure'], $reports($first)[0]['metadata']['reporting_controls'][0]['classes']);
        $this->assertCount(1, $reports($first)[0]['metadata']['stop_after_callback']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['bootstrap/app.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'execution-template') {
                foreach ($relation['metadata']['operations'] as &$op) {
                    if ($op['kind'] === 'exception_registration') {
                        $op['callback_returns_false_candidate'] = 'corrupt';
                        $mutated = true;
                    }
                }
                unset($op);
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/bootstrap/app.php', sprintf($source, 'OtherFailure'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['bootstrap/app.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Types.php', $changed->reusable);
        $index = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['App\\OtherFailure'], $reports($index)[0]['metadata']['reporting_controls'][0]['classes']);
        $this->assertCount(1, $reports($index)[0]['metadata']['stop_after_callback']);
    }

    public function test_trait_alias_final_edit_recomposes_cached_provider_helpers(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Chosen::class];');
        $files->put($this->tempPath.'/app/Loads.php', '<?php namespace App; trait Loads { protected function original() {} }');
        $source = '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { use Loads { original as %sselected; } }';
        $files->put($this->tempPath.'/app/Base.php', sprintf($source, ''));
        $files->put($this->tempPath.'/app/Chosen.php', '<?php namespace App; class Chosen extends Base { public function boot() { $this->selected(); } protected function selected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }');
        $files->put($this->tempPath.'/routes/selected.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Base.php']['g']['elements'] as &$element) {
            if ($element['kind'] === 'class') {
                $element['metadata']['trait_rules'][0]['final'] = 'corrupt';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Base.php', sprintf($source, 'final '));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        foreach (['app/Chosen.php', 'app/Loads.php', 'bootstrap/providers.php', 'routes/selected.php'] as $path) {
            $this->assertArrayHasKey($path, $changed->reusable);
        }
        $index = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $registered($index));
        $this->assertContains('http_provider_final_override', array_column($index->diagnostics, 'code'));
    }

    public function test_returned_provider_callable_cache_keeps_creator_and_recomposes_after_factory_edit(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Chosen::class];');
        $source = '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function callback() { $callback = $this->%s(...); return $callback; } private function first() { $this->loadRoutesFrom(__DIR__."/../routes/first.php"); } private function secondLong() { $this->loadRoutesFrom(__DIR__."/../routes/second.php"); } }';
        $files->put($this->tempPath.'/app/Base.php', sprintf($source, 'first'));
        $files->put($this->tempPath.'/app/Chosen.php', '<?php namespace App; class Chosen extends Base { public function boot() { $callback = $this->callback(); $callback(); } }');
        foreach (['first', 'second'] as $name) {
            $files->put($this->tempPath.'/routes/'.$name.'.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("'.$name.'", fn () => 1);');
        }
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_unique(array_map(fn ($row) => $row['metadata']['uri'], array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)))));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['/first'], $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['app/Base.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'returns-value') {
                $relation['metadata']['bound_callable']['creator'] = null;
                $mutated = true;
            }
        }
        unset($relation);
        $this->assertTrue($mutated);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Base.php', sprintf($source, 'secondLong'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        foreach (['app/Chosen.php', 'bootstrap/providers.php', 'routes/first.php', 'routes/second.php'] as $path) {
            $this->assertArrayHasKey($path, $changed->reusable);
        }
        $this->assertSame(['/second'], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_provider_receiver_alias_edit_refreshes_cached_route_registration(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $source = '<?php namespace App; class Other {} class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $provider = $this; %s $provider->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, ''));
        $files->put($this->tempPath.'/routes/selected.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        foreach ($stored['entries']['app/Provider.php']['g']['relations'] as &$relation) {
            if ($relation['kind'] === 'calls') {
                $relation['metadata']['offset'] = 'corrupt';
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, '$provider = new Other;'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('bootstrap/providers.php', $changed->reusable);
        $this->assertArrayHasKey('routes/selected.php', $changed->reusable);
        $this->assertSame([], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_malformed_composer_metadata_with_valid_cache_checksum_is_rebuilt(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Example.php', '<?php namespace App; class Example {}');
        $files->put($this->tempPath.'/composer.json', '{"require":{"vendor/package":"^1.2"}}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $mutated = false;
        foreach ($stored['entries']['composer.json']['g']['elements'] as &$element) {
            if ($element['kind'] === 'composer-dependency') {
                $element['metadata']['development'] = 'false';
                $mutated = true;
            }
        }
        unset($element);
        $this->assertTrue($mutated);
        $payload = serialize($stored);
        $cachePath = $this->tempPath.'/'.ProjectGraphCache::DIRECTORY.'/graph-'.substr($warm->signature->fingerprint, 0, 16).'.cache';
        $files->put($cachePath, hash('xxh128', $payload)."\n".$payload);
        $rebuild = $loader->plan();
        $this->assertSame(CacheStatus::Corrupt, $rebuild->cacheStatus);
        $this->assertEqualsCanonicalizing(['app/Example.php', 'composer.json'], $rebuild->toParse);
        $this->assertSame([], $rebuild->reusable);
        $this->assertEquals($first, new CatalogIndex($loader->build($rebuild)->catalogFacts));
        $restored = $loader->plan();
        $this->assertSame(CacheStatus::Fresh, $restored->cacheStatus);
        $this->assertSame([], $restored->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($restored)->catalogFacts));
    }

    public function test_root_composer_declaration_edit_reuses_php_and_lock_facts(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Example.php', '<?php namespace App; class Example {}');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"vendor/package","version":"1.2.3"}]}');
        $files->put($this->tempPath.'/composer.json', '{"require":{"vendor/package":"^1.2"},"autoload":{"psr-4":{"Extra":"private/"}}}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $loader->load();
        $this->assertSame([], $loader->plan()->toParse);
        $files->put($this->tempPath.'/composer.json', '{"require-dev":{"vendor/package":"~1.2.3"},"autoload-dev":{"psr-4":{"Extra":"private/new/"}}}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['composer.json'], $changed->toParse);
        $this->assertArrayHasKey('app/Example.php', $changed->reusable);
        $this->assertArrayHasKey('composer.lock', $changed->reusable);
        $this->assertArrayNotHasKey('private/new/', $changed->files);
        $index = new CatalogIndex($loader->build($changed)->catalogFacts);
        $declarations = array_values(array_filter($index->elements, fn ($row) => in_array($row['kind'], ['composer-dependency', 'autoload-mapping'], true)));
        $this->assertCount(2, $declarations);
        foreach ($declarations as $declaration) {
            $this->assertTrue($declaration['metadata']['development']);
        }
        $dependency = array_values(array_filter($declarations, fn ($row) => $row['kind'] === 'composer-dependency'))[0];
        $this->assertSame('~1.2.3', $dependency['metadata']['constraint']);
        $autoload = array_values(array_filter($declarations, fn ($row) => $row['kind'] === 'autoload-mapping'))[0];
        $this->assertSame('private/new/', $autoload['metadata']['source_path']);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($index, new CatalogIndex($loader->build($warm)->catalogFacts));
    }

    public function test_installed_version_edit_recomposes_cached_lock_metadata(): void
    {
        $files = new Filesystem;
        foreach (['app', 'vendor/composer'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Example.php', '<?php namespace App; class Example {}');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"vendor/package","version":"v1.2.3"}]}');
        $files->put($this->tempPath.'/vendor/composer/installed.json', '{"packages":[{"name":"vendor/package","version":"1.2.3"}],"dev-package-names":[]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertNotContains('composer_version_mismatch', array_column($first->diagnostics, 'code'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/vendor/composer/installed.json', '{"packages":[{"name":"vendor/package","version":"1.2.40"}],"dev-package-names":[]}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['vendor/composer/installed.json'], $changed->toParse);
        $this->assertArrayHasKey('composer.lock', $changed->reusable);
        $this->assertArrayHasKey('app/Example.php', $changed->reusable);
        $this->assertContains('composer_version_mismatch', array_column((new CatalogIndex($loader->build($changed)->catalogFacts))->diagnostics, 'code'));
    }

    public function test_bound_callable_keeps_selected_provider_when_cached_boot_is_reused(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $callback = $this->loadSelected(...); $callback(); } protected function loadSelected() {} }');
        $source = '<?php namespace App; class Provider extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/%s.php"); } }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, 'first'));
        foreach (['first', 'second-long'] as $route) {
            $files->put($this->tempPath.'/routes/'.$route.'.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("'.$route.'", fn () => 1);');
        }
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
        $this->assertSame(['/first'], $registered(new CatalogIndex($loader->load()->catalogFacts)));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertSame(['/first'], $registered(new CatalogIndex($loader->build($warm)->catalogFacts)));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, 'second-long'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Base.php', $changed->reusable);
        $this->assertArrayHasKey('bootstrap/providers.php', $changed->reusable);
        $this->assertSame(['/second-long'], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_provider_callback_invocation_edit_recomposes_cached_route_sources(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $source = '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $callback = function () { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); }; %s } }';
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, ''));
        $files->put($this->tempPath.'/routes/selected.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)));
        $this->assertSame([], $registered(new CatalogIndex($loader->load()->catalogFacts)));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertSame([], $registered(new CatalogIndex($loader->build($warm)->catalogFacts)));
        $files->put($this->tempPath.'/app/Provider.php', sprintf($source, '$callback();'));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('bootstrap/providers.php', $changed->reusable);
        $this->assertArrayHasKey('routes/selected.php', $changed->reusable);
        $this->assertCount(1, $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_trait_alias_visibility_edit_recomposes_cached_child_boot(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends Base { public function boot() { $this->loadAlias(); } }');
        $files->put($this->tempPath.'/app/Loads.php', '<?php namespace App; trait Loads { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/selected.php"); } }');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { use Loads { loadSelected as private loadAlias; } }');
        $files->put($this->tempPath.'/routes/selected.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("selected", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)));
        $this->assertSame([], $registered(new CatalogIndex($loader->load()->catalogFacts)));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertSame([], $registered(new CatalogIndex($loader->build($warm)->catalogFacts)));
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { use Loads { loadSelected as protected loadAlias; } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Provider.php', $changed->reusable);
        $this->assertArrayHasKey('app/Loads.php', $changed->reusable);
        $this->assertCount(1, $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_provider_trait_precedence_edit_recomposes_cached_trait_methods(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use First, Second { First::loadSelected insteadof Second; } public function boot() { $this->loadSelected(); } }');
        foreach (['First', 'Second'] as $trait) {
            $name = strtolower($trait);
            $files->put($this->tempPath.'/app/'.$trait.'.php', '<?php namespace App; trait '.$trait.' { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/'.$name.'.php"); } }');
            $files->put($this->tempPath.'/routes/'.$name.'.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("'.$name.'", fn () => 1);');
        }
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
        $this->assertSame(['/first'], $registered(new CatalogIndex($loader->load()->catalogFacts)));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertSame(['/first'], $registered(new CatalogIndex($loader->build($warm)->catalogFacts)));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { use First, Second { Second::loadSelected insteadof First; } public function boot() { $this->loadSelected(); } } // changed');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/First.php', $changed->reusable);
        $this->assertArrayHasKey('app/Second.php', $changed->reusable);
        $this->assertSame(['/second'], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_provider_helper_override_edit_recomposes_cached_inherited_boot(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadSelected(); } protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/base.php"); } }');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends Base {}');
        $files->put($this->tempPath.'/routes/base.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("base", fn () => 1);');
        $files->put($this->tempPath.'/routes/chosen.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("chosen", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
        $this->assertSame(['/base'], $registered(new CatalogIndex($loader->load()->catalogFacts)));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertSame(['/base'], $registered(new CatalogIndex($loader->build($warm)->catalogFacts)));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends Base { protected function loadSelected() { $this->loadRoutesFrom(__DIR__."/../routes/chosen.php"); } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Base.php', $changed->reusable);
        $this->assertArrayHasKey('bootstrap/providers.php', $changed->reusable);
        $this->assertSame(['/chosen'], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_bootstrap_provider_setting_edit_recomposes_cached_provider_routes(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php \\Illuminate\\Foundation\\Application::configure()->withProviders([], false);');
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provided.php"); } }');
        $files->put($this->tempPath.'/routes/provided.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true)));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame([], $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php \\Illuminate\\Foundation\\Application::configure()->withProviders();');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['bootstrap/app.php'], $changed->toParse);
        foreach (['bootstrap/providers.php', 'app/Provider.php', 'routes/provided.php'] as $path) {
            $this->assertArrayHasKey($path, $changed->reusable);
        }
        $this->assertCount(1, $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_composer_metadata_uses_cache_without_reading_vendor_php_and_refreshes_discovery_exclusions(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes', 'vendor/composer'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/composer.json', '{"extra":{"laravel":{"dont-discover":[]}},"scripts":{"post-autoload-dump":"script-secret"}}');
        $files->put($this->tempPath.'/vendor/composer/installed.php', '<?php throw new \\RuntimeException("must never execute");');
        $files->put($this->tempPath.'/vendor/composer/installed.json', '{"packages":[{"name":"vendor/package","version":"v1.2.3","extra":{"laravel":{"providers":["App\\\\Provider"]}}}]}');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provided.php"); } }');
        $files->put($this->tempPath.'/routes/provided.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $plan = $loader->plan();
        $this->assertArrayHasKey('vendor/composer/installed.json', $plan->files);
        $this->assertArrayNotHasKey('vendor/composer/installed.php', $plan->files);
        $registered = fn ($index) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('vendor/composer/installed.json', array_column($row['metadata']['registration'], 'path'), true)));
        $graph = $loader->build($plan);
        $this->assertStringNotContainsString('-secret', json_encode(array_map(fn ($fact) => $fact->toArray(), $graph->catalogFacts)));
        $first = new CatalogIndex($graph->catalogFacts);
        $this->assertCount(1, $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/composer.json', '{"extra":{"laravel":{"dont-discover":["*"]}}}');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['composer.json'], $changed->toParse);
        $this->assertArrayHasKey('vendor/composer/installed.json', $changed->reusable);
        $this->assertSame([], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
        $legacy = new ProjectGraphLoader($files, $this->tempPath);
        $this->assertArrayNotHasKey('composer.json', $legacy->plan()->files);
    }

    public function test_inherited_provider_route_path_edit_recomposes_cached_registration(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/providers.php', '<?php return [App\\Provider::class];');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends Base {}');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/a.php"); } }');
        $files->put($this->tempPath.'/routes/a.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("a", fn () => 1);');
        $files->put($this->tempPath.'/routes/b-long.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("b-long", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $registered = fn ($index) => array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($index->elements, fn ($row) => $row['kind'] === 'route' && in_array('bootstrap/providers.php', array_column($row['metadata']['registration'], 'path'), true))));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['/a'], $registered($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/b-long.php"); } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Base.php'], $changed->toParse);
        $this->assertArrayHasKey('bootstrap/providers.php', $changed->reusable);
        $this->assertArrayHasKey('app/Provider.php', $changed->reusable);
        $this->assertSame(['/b-long'], $registered(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_job_middleware_edit_recomposes_cached_dispatch(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function run() { Job::dispatch(); }');
        $files->put($this->tempPath.'/app/Middleware.php', '<?php namespace App; class A { public function handle($job, $next) {} } class BLong { public function handle($job, $next) {} }');
        $files->put($this->tempPath.'/app/Job.php', '<?php namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() {} public function middleware() { return [new A]; } }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'job-middleware')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\A::handle'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Job.php', '<?php namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() {} public function middleware() { return [new BLong]; } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Job.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame(['App\\BLong::handle'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_resource_contract_change_recomposes_cached_returning_callable(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function responseResource() { return Resource::collection([]); }');
        $files->put($this->tempPath.'/app/Resource.php', '<?php namespace App; class Resource extends \\Illuminate\\Http\\Resources\\Json\\JsonResource { public function toArray($request) {} }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'returns-resource'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Resource.php', '<?php namespace App; class Resource { public function toArray($request) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Resource.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame([], $edges(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_form_request_rule_contract_edit_recomposes_cached_routes_and_returned_rules(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/routes/web.php', '<?php \\Illuminate\\Support\\Facades\\Route::post("one", fn (App\\Request $request) => 1);');
        $files->put($this->tempPath.'/app/Request.php', '<?php namespace App; class Request extends \\Illuminate\\Foundation\\Http\\FormRequest { public function rules() { return ["field" => [new Rule]]; } }');
        $files->put($this->tempPath.'/app/Rule.php', '<?php namespace App; class Rule implements \\Illuminate\\Contracts\\Validation\\ValidationRule { public function validate($attribute, $value, $fail) {} }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'validation-rule'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Rule.php', '<?php namespace App; class Rule { public function validate($attribute, $value, $fail) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Rule.php'], $changed->toParse);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertArrayHasKey('app/Request.php', $changed->reusable);
        $this->assertSame([], $edges(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_exception_parameter_change_recomposes_cached_registration(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php \\Illuminate\\Foundation\\Application::configure()->withExceptions(fn ($exceptions) => $exceptions->report(App\\Reporter::report(...)));');
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Failure extends \\Exception {} class Ordinary {} class Reporter { public static function report(Failure $e) {} }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $edges = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'exception-report-registration'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $edges($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Failure extends \\Exception {} class Ordinary {} class Reporter { public static function report(Ordinary $e) {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Types.php'], $changed->toParse);
        $this->assertArrayHasKey('bootstrap/app.php', $changed->reusable);
        $this->assertSame([], $edges(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_auth_driver_change_recomposes_cached_factories_without_parsing_provider(): void
    {
        $files = new Filesystem;
        foreach (['app', 'config'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class A implements \\Illuminate\\Contracts\\Auth\\Guard {} class BLong implements \\Illuminate\\Contracts\\Auth\\Guard {} class Provider { public function boot() { \\Illuminate\\Support\\Facades\\Auth::extend("a", fn () => new A); \\Illuminate\\Support\\Facades\\Auth::extend("b-long", fn () => new BLong); } }');
        $files->put($this->tempPath.'/config/auth.php', '<?php return ["guards" => ["web" => ["driver" => "a"]], "providers" => []];');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'auth-driver-factory')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $targets($first));
        $original = $targets($first);
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/config/auth.php', '<?php return ["guards" => ["web" => ["driver" => "b-long"]], "providers" => []];');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['config/auth.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Provider.php', $changed->reusable);
        $updated = $targets(new CatalogIndex($loader->build($changed)->catalogFacts));
        $this->assertCount(1, $updated);
        $this->assertNotSame($original, $updated);
    }

    public function test_controller_middleware_filter_change_updates_cached_routes(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Audit { public function handle($request, $next) {} } class Controller extends \\Illuminate\\Routing\\Controller { public function __construct() { $this->middleware(Audit::class)->only("show"); } public function show() {} public function store() {} }');
        $files->put($this->tempPath.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("show", [App\\Controller::class, "show"]); Route::post("store", [App\\Controller::class, "store"]);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $uris = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['from']]['metadata']['uri'], array_filter($index->relations, fn ($row) => $row['kind'] === 'http-middleware')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['/show'], $uris($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Audit { public function handle($request, $next) {} } class Controller extends \\Illuminate\\Routing\\Controller { public function __construct() { $this->middleware(Audit::class)->only("store"); } public function show() {} public function store() {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Types.php'], $changed->toParse);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertSame(['/store'], $uris(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_global_middleware_change_recomposes_unchanged_cached_routes(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class A { public function handle($request, $next) {} } class BLong { public function handle($request, $next) {} }');
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php \\Illuminate\\Foundation\\Application::configure()->withMiddleware(fn ($middleware) => $middleware->append(App\\A::class));');
        $files->put($this->tempPath.'/routes/web.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("one", fn () => 1);');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'http-global-middleware')));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\A::handle'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php \\Illuminate\\Foundation\\Application::configure()->withMiddleware(fn ($middleware) => $middleware->append(App\\BLong::class));');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['bootstrap/app.php'], $changed->toParse);
        $this->assertArrayHasKey('routes/web.php', $changed->reusable);
        $this->assertSame(['App\\BLong::handle'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_route_authorization_refreshes_from_cache_and_validates_callback_parameters(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Invoice {} class Policy { public function view($user, Invoice $invoice) {} } class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider { protected $policies = [Invoice::class => Policy::class]; }');
        $files->put($this->tempPath.'/routes/web.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("invoice/{invoice}", fn (App\\Invoice $invoice) => 1)->middleware("can:view,invoice");');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $checks = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'authorization-check'));
        $this->assertCount(1, $checks($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        foreach ($stored['entries']['routes/web.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['to'], 'http:') && isset($relation['metadata']['handler']['parameters'])) {
                $relation['metadata']['handler']['parameters'][0]['route_resolvable'] = 'corrupt';
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/routes/web.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("invoice/{invoice}", fn (App\\Invoice $invoice) => 1)->middleware("can:view,invoice")->withoutMiddleware("can:view,invoice");');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['routes/web.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Types.php', $changed->reusable);
        $this->assertSame([], $checks(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_policy_registration_change_recomposes_cached_authorization_check(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function entry(Invoice $invoice) { \\Illuminate\\Support\\Facades\\Gate::authorize("update", $invoice); }');
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Invoice {} class A { public function update($user, Invoice $invoice) {} } class BLong { public function update($user, Invoice $invoice) {} }');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider { protected $policies = [Invoice::class => A::class]; }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'authorization-policy')));
        $this->assertSame(['App\\A::update'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider { protected $policies = [Invoice::class => BLong::class]; }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame(['App\\BLong::update'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_command_signature_change_recomposes_cached_schedule_without_reparsing_it(): void
    {
        $files = new Filesystem;
        foreach (['app', 'bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php \\Illuminate\\Foundation\\Application::configure()->withCommands([App\\Report::class]);');
        $files->put($this->tempPath.'/app/Report.php', '<?php namespace App; class Report extends \\Illuminate\\Console\\Command { protected $signature = "report:a {--token=signature-secret}"; public function handle() {} }');
        $files->put($this->tempPath.'/routes/console.php', '<?php \\Illuminate\\Support\\Facades\\Schedule::command("report:a --token=argument-secret")->daily();');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $calls = fn ($index) => array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'artisan-command'));
        $this->assertCount(1, $calls($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        $this->assertStringNotContainsString('-secret', json_encode($stored));
        foreach ($stored['entries']['routes/console.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['to'], 'execution:')) {
                foreach ($relation['metadata']['operations'] as &$operation) {
                    if ($operation['kind'] === 'schedule_options') {
                        $operation['schedule_site'] = ['line' => 0, 'offset' => 0];
                    }
                }
                unset($operation);
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Report.php', '<?php namespace App; class Report extends \\Illuminate\\Console\\Command { protected $signature = "report:changed {--token=signature-secret}"; public function handle() {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Report.php'], $changed->toParse);
        $this->assertArrayHasKey('routes/console.php', $changed->reusable);
        $updated = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $calls($updated));
        $this->assertContains('execution_analysis', array_column($updated->diagnostics, 'code'));
    }

    public function test_listener_registration_change_recomposes_cached_event_dispatch(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; function entry() { event(new Paid("payload-secret")); }');
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class Paid {} class A { public function handle(Paid $event) {} } class BLong { public function handle(Paid $event) {} }');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider { protected $listen = [Paid::class => [A::class]]; }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'event-listener')));
        $this->assertSame(['App\\A::handle'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        foreach ($stored['entries']['app/Provider.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['to'], 'execution:')) {
                $relation['metadata']['classes'][0]['methods'] = 'corrupt';
                break;
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($stored));
        $this->assertStringNotContainsString('payload-secret', json_encode($stored));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider { protected $listen = [Paid::class => [BLong::class]]; }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $this->assertSame(['App\\BLong::handle'], $targets(new CatalogIndex($loader->build($changed)->catalogFacts)));
    }

    public function test_routing_prefix_change_recomposes_cached_route_templates(): void
    {
        $files = new Filesystem;
        foreach (['bootstrap', 'routes'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php use Illuminate\\Foundation\\Application; Application::configure()->withRouting(api: __DIR__."/../routes/api.php", apiPrefix: "v1");');
        $files->put($this->tempPath.'/routes/api.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("health", fn () => "payload-secret");');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $uris = fn ($index) => array_values(array_map(fn ($row) => $row['metadata']['uri'], array_filter($index->elements, fn ($row) => $row['kind'] === 'route')));
        $this->assertSame(['/v1/health'], $uris($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        foreach ($stored['entries']['routes/api.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['to'], 'http:')) {
                $relation['metadata']['context']['middleware'] = ['invalid' => 'associative list'];
                break;
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php use Illuminate\\Foundation\\Application; Application::configure()->withRouting(api: __DIR__."/../routes/api.php", apiPrefix: "version2");');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['bootstrap/app.php'], $changed->toParse);
        $this->assertArrayHasKey('routes/api.php', $changed->reusable);
        $updated = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['/version2/health'], $uris($updated));
        $this->assertStringNotContainsString('payload-secret', json_encode([$updated->elements, $updated->relations, $updated->diagnostics]));
    }

    public function test_binding_change_recomposes_injection_without_parsing_the_consumer(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Consumer.php', '<?php namespace App; class Consumer { public function __construct(Port $port) {} }');
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; interface Port {} class A implements Port {} class BLong implements Port {}');
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function register() { $this->app->bind(Port::class, A::class); } }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $targets = fn ($index) => array_values(array_map(fn ($row) => $index->elements[$row['to']]['name'], array_filter($index->relations, fn ($row) => $row['kind'] === 'provides')));
        $this->assertSame(['App\\A'], $targets($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $stored = (new CachedGraph($warm->signature, $warm->reusable))->toArray();
        foreach ($stored['entries']['app/Provider.php']['g']['relations'] as &$relation) {
            if (str_starts_with($relation['to'], 'container:')) {
                $relation['metadata']['contexts'] = 'invalid shape';
                break;
            }
        }
        unset($relation);
        $this->assertNull(CachedGraph::fromArray($stored));
        $files->put($this->tempPath.'/app/Provider.php', '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function register() { $this->app->bind(Port::class, BLong::class); } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Provider.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Consumer.php', $changed->reusable);
        $updated = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['App\\BLong'], $targets($updated));
        $this->assertCount(1, array_filter($updated->relations, fn ($row) => $row['kind'] === 'injected-binding'));
    }

    public function test_cross_file_return_type_change_recomposes_cached_caller_dispatch(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Caller.php', '<?php namespace App; class Caller { public function run() { Factory::make()->work(); } }');
        $files->put($this->tempPath.'/app/Types.php', '<?php namespace App; class A { public function work() {} } class BLong { public function work() {} }');
        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; class Factory { public static function make(): A { throw new \\RuntimeException("source only"); } }');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $caller = $first->names[strtolower('App\\Caller::run')][0];
        $targets = fn ($index) => array_values(array_filter(array_map(fn ($row) => $index->elements[$row['to']]['name'] ?? null,
            array_filter($index->relations, fn ($row) => $row['kind'] === 'calls' && $row['from'] === $caller)), 'is_string'));
        $this->assertSame(['App\\Factory::make', 'App\\A::work'], $targets($first));
        $files->put($this->tempPath.'/app/Factory.php', '<?php namespace App; class Factory { public static function make(): BLong { throw new \\RuntimeException("source only"); } }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Factory.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Caller.php', $changed->reusable);
        $updated = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['App\\Factory::make', 'App\\BLong::work'], $targets($updated));
    }

    public function test_catalog_cache_reuses_unchanged_files_and_round_trips_facts(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/A.php', '<?php namespace App; class A { public function run() {} }');
        $files->put($this->tempPath.'/app/B.php', '<?php namespace App; class B {}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, cache: new ProjectGraphCache($files, $this->tempPath), impact: true, sourceOnly: true, catalog: true);
        $plan = $loader->plan();
        $cold = $loader->build($plan);
        $warmPlan = $loader->plan();
        $this->assertSame([], $warmPlan->toParse);
        $this->assertEquals($cold, $loader->build($warmPlan));
        $this->assertEquals($cold, (new ProjectGraphLoader($files, $this->tempPath, impact: true, sourceOnly: true, catalog: true))->load());
        $stored = new CachedGraph($warmPlan->signature, $warmPlan->reusable);
        $array = $stored->toArray();
        $this->assertArrayNotHasKey('path', $array['entries']['app/A.php']['g']);
        $this->assertEquals($stored, CachedGraph::fromArray($array));
        $array['entries']['app/A.php']['g']['elements'][0]['line'] = 'not an integer';
        $this->assertNull(CachedGraph::fromArray($array));
        $files->put($this->tempPath.'/app/A.php', '<?php namespace App; class A { public function runUpdated() {} }');
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/A.php'], $changed->toParse);
        $this->assertArrayHasKey('app/B.php', $changed->reusable);
    }

    public function test_inherited_livewire_attribute_edit_recomposes_cached_child_methods_and_trait_alias_visibility(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $trait = '<?php namespace App; trait Reloads { #[\\Livewire\\Attributes\\On("saved")] protected function receive() {} }';
        $component = '<?php namespace App; class Orders extends \\Livewire\\Component { use Reloads { receive as public reload; } public function send() { $this->dispatch("saved")->self(); } }';
        $files->put($this->tempPath.'/app/Reloads.php', $trait);
        $files->put($this->tempPath.'/app/Orders.php', $component);
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $listeners = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'livewire-event-listener'));
        $cold = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(2, $listeners($cold));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($cold, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/Reloads.php', str_replace('On("saved")', 'On("changed-event")', $trait));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Reloads.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Orders.php', $changed->reusable);
        $updated = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertCount(1, $listeners($updated));
        $this->assertSame('changed-event', $updated->elements[$listeners($updated)[0]['from']]['name']);
        $files->put($this->tempPath.'/app/Orders.php', str_replace('as public reload', 'as protected reload', $component));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/Orders.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Reloads.php', $changed->reusable);
        $hidden = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $listeners($hidden));
    }

    public function test_package_source_reference_edit_recomposes_cached_relations_without_reparsing_application_files(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Orders.php', '<?php namespace App; class Orders extends \\Livewire\\Component { #[\\Livewire\\Attributes\\On("saved")] public function reload() {} }');
        $record = ['name' => 'livewire/livewire', 'version' => '4.4.5', 'source' => ['reference' => '10aa0b5ee44c99b5bce0f78ad265bbcf0e74abdc']];
        $files->put($this->tempPath.'/composer.lock', json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR));
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $listeners = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'livewire-event-listener'));
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertCount(1, $listeners($first));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $record['source']['reference'] = str_repeat('f', 40);
        $files->put($this->tempPath.'/composer.lock', json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR)."\n\n");
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['composer.lock'], $changed->toParse);
        $this->assertArrayHasKey('app/Orders.php', $changed->reusable);
        $mismatched = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame([], $listeners($mismatched));
        $this->assertCount(1, $mismatched->namedTypes('App\\Orders'));
        $this->assertContains('composer_profile_reference_mismatch', array_column($mismatched->diagnostics, 'code'));
        $record['source']['reference'] = '10aa0b5ee44c99b5bce0f78ad265bbcf0e74abdc';
        $files->put($this->tempPath.'/composer.lock', json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR)."\n\n\n");
        clearstatcache();
        $restored = $loader->plan();
        $this->assertSame(['composer.lock'], $restored->toParse);
        $this->assertArrayHasKey('app/Orders.php', $restored->reusable);
        $this->assertCount(1, $listeners(new CatalogIndex($loader->build($restored)->catalogFacts)));
    }

    public static function livewireListenerDeclarations(): array
    {
        return [
            'literal getter' => ["protected function getListeners() { return ['saved' => 'reload']; }"],
            'property with framework getter' => ["protected \$listeners = ['saved' => 'reload'];"],
            'property with source getter' => ["protected \$listeners = ['saved' => 'reload']; protected function getListeners() { return \$this->listeners; }"],
        ];
    }

    #[DataProvider('livewireListenerDeclarations')]
    public function test_livewire_listener_map_edit_recomposes_inherited_and_self_targeted_delivery_without_reparsing_the_receiver(string $declaration): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $base = <<<'SOURCE'
<?php namespace App;
abstract class BaseOrders extends \Livewire\Component {
    /* listener declaration */
    public function reload() {}
    public function reloadChanged() {}
}
SOURCE;
        $base = str_replace('/* listener declaration */', $declaration, $base);
        $files->put($this->tempPath.'/app/BaseOrders.php', $base);
        $files->put($this->tempPath.'/app/Orders.php', '<?php namespace App; class Orders extends BaseOrders { public function save() { $this->dispatch("saved")->self(); } }');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $targets = function (CatalogIndex $index) {
            $edges = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'livewire-event-listener'));
            $this->assertCount(2, $edges);
            foreach ($edges as $edge) {
                $this->assertSame($index->namedTypes('App\\Orders')[0], $edge['metadata']['listener_component']);
            }

            return array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $edges);
        };
        $cold = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame(['App\\BaseOrders::reload', 'App\\BaseOrders::reload'], $targets($cold));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($cold, new CatalogIndex($loader->build($warm)->catalogFacts));
        $files->put($this->tempPath.'/app/BaseOrders.php', str_replace("'saved' => 'reload'", "'saved' => 'reloadChanged'", $base));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame(['app/BaseOrders.php'], $changed->toParse);
        $this->assertArrayHasKey('app/Orders.php', $changed->reusable);
        $updated = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertSame(['App\\BaseOrders::reloadChanged', 'App\\BaseOrders::reloadChanged'], $targets($updated));
        $files->delete($this->tempPath.'/composer.lock');
        clearstatcache();
        $withoutPackage = new CatalogIndex($loader->load()->catalogFacts);
        $this->assertSame([], array_values(array_filter($withoutPackage->relations, fn ($edge) => $edge['kind'] === 'livewire-event-listener')));
    }

    public function test_mapped_livewire_blade_facts_survive_warm_cache_and_recompose_after_edit(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/resources/views/livewire');
        $source = <<<'SOURCE'
{{-- Component source follows. --}}
<?php
new class extends \Livewire\Component {
    #[\Livewire\Attributes\On('saved')]
    public function reload() {}
};
?>
{{-- <?php class ??? ?> --}}
<div>{{
    view('shared.panel')
}}</div>
SOURCE;
        $path = 'resources/views/livewire/orders.blade.php';
        $files->put($this->tempPath.'/'.$path, $source);
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, CatalogSettings::load($files, $this->tempPath)->scope, cache: new ProjectGraphCache($files, $this->tempPath), sourceOnly: true, catalog: true);
        $first = new CatalogIndex($loader->load()->catalogFacts);
        $views = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'renders-livewire-view'));
        $this->assertCount(1, $views($first));
        $this->assertSame(3, $views($first)[0]['line']);
        $this->assertNotContains('parse_error', array_column($first->diagnostics, 'code'));
        $warm = $loader->plan();
        $this->assertSame([], $warm->toParse);
        $this->assertEquals($first, new CatalogIndex($loader->build($warm)->catalogFacts));
        $this->assertArrayHasKey('saved', $first->names);
        $files->put($this->tempPath.'/'.$path, str_replace("On('saved')", "On('order-updated')", $source));
        clearstatcache();
        $changed = $loader->plan();
        $this->assertSame([$path], $changed->toParse);
        $new = new CatalogIndex($loader->build($changed)->catalogFacts);
        $this->assertArrayNotHasKey('saved', $new->names);
        $this->assertArrayHasKey('order-updated', $new->names);
        $this->assertCount(1, $views($new));
    }
}
