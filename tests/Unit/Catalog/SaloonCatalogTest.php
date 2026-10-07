<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

final class SaloonCatalogTest extends TestCase
{
    /** @return array<string, string> */
    private function sources(string $consumer): array
    {
        return [
            'app/Integrations/Billing.php' => 'namespace App; trait Url { public function url() { return "https://user-secret:pass-secret@billing.test/api/"; } } class Billing extends \\Saloon\\Http\\Connector { use Url { url as resolveBaseUrl; } }',
            'app/Integrations/Invoice.php' => 'namespace App; class Invoice extends \\Saloon\\Http\\Request { protected \\Saloon\\Enums\\Method $method = \\Saloon\\Enums\\Method::POST; public function resolveEndpoint(): string { return "invoices?token=query-secret#fragment-secret"; } }',
            'app/Action.php' => $consumer,
        ];
    }

    /** @param array<string, string> $sources
     * @return list<CatalogFacts>
     */
    private function facts(array $sources, string $version = '4.0.0'): array
    {
        $files = [];
        foreach ($sources as $path => $source) {
            $files[] = new FileContext($path, '<?php '.$source);
        }
        $facts = (new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts;
        $facts[] = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', json_encode(['packages' => [
            ['name' => 'saloonphp/saloon', 'version' => $version],
        ]], JSON_THROW_ON_ERROR)));

        return array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
    }

    /** @param array<string, string> $sources */
    private function index(array $sources, string $version = '4.0.0'): CatalogIndex
    {
        return new CatalogIndex($this->facts($sources, $version), fn ($path) => isset($sources[$path]) ? '<?php '.$sources[$path] : null);
    }

    /** @return list<array<string, mixed>> */
    private function sends(CatalogIndex $index): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'uses-external-service'));
    }

    public function test_pool_send_composes_source_members_for_assigned_and_chained_pools(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Action {
 public function prepared(Billing $billing) { $billing->pool([new Invoice('body-secret')]); }
 public function assigned(Billing $billing) { $pool = $billing->pool([new Invoice]); $alias = $pool; $alias->send(); }
 public function chained(Billing $billing) { $billing->pool(requests: [new Invoice])->send(); }
 public function factory(Billing $billing) { $billing->pool(function () { return [new Invoice]; })->send(); }
 public function generator(Billing $billing) { $billing->pool(function () { yield new Invoice; })->send(); }
 public function unusedGenerator(Billing $billing) { $billing->pool(function () { yield new Invoice; }); }
}
PHP);
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(4, $sends);
        foreach ($sends as $send) {
            $this->assertSame('https://billing.test/api/invoices', $index->elements[$send['to']]['name']);
            $this->assertTrue($send['metadata']['asynchronous']);
            $this->assertFalse($send['metadata']['execution_proven']);
            $this->assertSame('saloon-pool-site', $index->elements[$send['metadata']['pool_source']['id']]['kind']);
        }
        $kinds = array_column($index->relations, 'kind');
        $this->assertSame(1, count(array_filter($kinds, fn ($kind) => $kind === 'invokes-saloon-pool-factory')));
        $this->assertSame(1, count(array_filter($kinds, fn ($kind) => $kind === 'consumes-saloon-pool-generator')));
        $query = new GraphQuery($index);
        foreach (['prepared', 'unusedGenerator'] as $method) {
            $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\\Action::'.$method)][0], 'path', $sends[0]['to'], 8)['status']);
        }
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_pool_decoys_overrides_reassigned_variables_and_wrong_package_do_not_send(): void
    {
        foreach ([
            ['namespace App; class Action { function run(Billing $billing) { $pool = $billing->pool([new Invoice]); $pool = new Decoy; $pool->send(); } } class Decoy { function send() {} }', '', '4.0.0'],
            ['namespace App; class Action { function run(Billing $billing) { $billing->pool([new Invoice])->send(); } }', 'public function pool($requests) {}', '4.0.0'],
            ['namespace App; class Action { function run(Billing $billing) { $billing->pool([new Invoice])->send(); } }', '', '3.0.0'],
            ['namespace App; class Action { function run(Decoy $billing) { $billing->pool([new Invoice])->send(); } } class Decoy { function pool($requests) {} }', '', '4.0.0'],
            ['namespace App; class Action { function run(Billing $billing) { $billing->pool([new Invoice])->send(mockClient: null); } }', '', '4.0.0'],
            ['namespace App; class Action { function run(Billing $billing, $args) { $billing->pool([new Invoice])->send(...$args); } }', '', '4.0.0'],
        ] as [$consumer, $override, $version]) {
            $sources = $this->sources($consumer);
            if ($override !== '') {
                $sources['app/Integrations/Billing.php'] = 'namespace App; class Billing extends \\Saloon\\Http\\Connector { '.$override.' public function resolveBaseUrl() { return "https://billing.test"; } }';
            }
            $this->assertSame([], $this->sends($this->index($sources, $version)), $consumer.$override.$version);
        }
    }

    public function test_direct_pool_constructor_and_source_subclass_keep_connector_and_request_selection(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
use Saloon\Http\Pool as Batch;
class LocalPool extends Batch {}
class Action {
 public function prepared(Billing $billing) { new Batch($billing, [new Invoice]); }
 public function positional(Billing $billing) { $pool = new Batch($billing, [new Invoice('body-secret')]); $pool->send(); }
 public function named(Billing $billing) { (new Batch(requests: [new Invoice], connector: $billing))->send(); }
 public function subclass(Billing $billing) { (new LocalPool($billing, [new Invoice]))->send(); }
 public function generator(Billing $billing) { (new Batch($billing, function () { yield new Invoice; }))->send(); }
 public function factory(Billing $billing) { (new Batch($billing, fn () => [new Invoice]))->send(); }
}
PHP);
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(5, $sends);
        foreach ($sends as $send) {
            $this->assertSame('https://billing.test/api/invoices', $index->elements[$send['to']]['name']);
            $this->assertTrue($send['metadata']['asynchronous']);
            $this->assertSame('new', $index->elements[$send['metadata']['pool_source']['id']]['metadata']['form']);
        }
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($index->names[strtolower('App\\Action::prepared')][0], 'path', $sends[0]['to'], 8)['status']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_pool_constructor_invalid_connector_decoy_and_subclass_override_suppress_transport(): void
    {
        foreach ([
            ['(new \\Saloon\\Http\\Pool(requests: [new Invoice]))->send()', ''],
            ['(new \\Saloon\\Http\\Pool(connector: $unknown, requests: [new Invoice]))->send()', ''],
            ['(new \\Saloon\\Http\\Pool(connector: new Decoy, requests: [new Invoice]))->send()', 'class Decoy {}'],
            ['(new LocalPool($billing, [new Invoice]))->send()', 'class LocalPool extends \\Saloon\\Http\\Pool { public function send() {} }'],
            ['(new LocalPool($billing, [new Invoice]))->send()', 'class LocalPool extends \\Saloon\\Http\\Pool { public function setRequests($requests) {} }'],
            ['(new Decoy($billing, [new Invoice]))->send()', 'class Decoy { public function send() {} }'],
        ] as [$body, $declaration]) {
            $sources = $this->sources('namespace App; '.$declaration.' class Action { function run(Billing $billing, $unknown) { '.$body.'; } }');
            $this->assertSame([], $this->sends($this->index($sources)), $body.$declaration);
        }
    }

    public function test_set_requests_replaces_members_through_aliases_and_chains_with_conditional_alternatives(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Action {
 public function alias(Billing $billing) { $pool = $billing->pool([new Invoice]); $alias = $pool; $alias->setRequests(requests: [new Replacement]); $pool->send(); }
 public function chained(Billing $billing) { $billing->pool([new Invoice])->setRequests([new Replacement])->send(); }
 public function constructed(Billing $billing) { $pool = new \Saloon\Http\Pool($billing); $pool->setRequests([new Replacement]); $pool->send(); }
 public function branch(Billing $billing, bool $flag) { $pool = $billing->pool([new Invoice]); if ($flag) { $pool->setRequests([new Replacement]); } $pool->send(); }
 public function isolated(Billing $billing) { $pool = $billing->pool([new Invoice]); $other = $billing->pool([new Invoice]); $pool->setRequests([new Replacement]); $other->send(); }
 public function generator(Billing $billing) { $billing->pool([])->setRequests(function () { yield new Replacement; })->send(); }
 public function prepared(Billing $billing) { $billing->pool([])->setRequests(fn () => [new Replacement]); }
 public function dynamic(Billing $billing, $requests) { $pool = $billing->pool([new Invoice]); $pool->setRequests($requests); $pool->send(); }
}
PHP);
        $sources['app/Integrations/Replacement.php'] = 'namespace App; class Replacement extends Invoice { public function resolveEndpoint() { return "replacements"; } }';
        $index = $this->index($sources);
        $actual = [];
        foreach ($this->sends($index) as $send) {
            $actual[$index->elements[$send['from']]['name']][] = $index->elements[$send['to']]['name'];
        }
        $replacement = ['https://billing.test/api/replacements'];
        $this->assertSame($replacement, $actual['App\\Action::alias']);
        $this->assertSame($replacement, $actual['App\\Action::chained']);
        $this->assertSame($replacement, $actual['App\\Action::constructed']);
        $this->assertSame(['https://billing.test/api/invoices', ...$replacement], $actual['App\\Action::branch']);
        $this->assertSame(['https://billing.test/api/invoices'], $actual['App\\Action::isolated']);
        $this->assertSame($replacement, $actual['App\\Action::generator']);
        $this->assertArrayNotHasKey('App\\Action::prepared', $actual);
        $this->assertArrayNotHasKey('App\\Action::dynamic', $actual);
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        $kinds = array_column($index->relations, 'kind');
        $this->assertSame(1, count(array_filter($kinds, fn ($kind) => $kind === 'consumes-saloon-pool-generator')));
        $this->assertSame(1, count(array_filter($kinds, fn ($kind) => $kind === 'invokes-saloon-pool-factory')));
    }

    public function test_pool_callbacks_are_registered_then_conditionally_invoked_only_on_send(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Handler { static function response() {} static function exception() {} static function concurrency() { return 2; } }
class Action {
 public function prepared(Billing $billing) { $billing->pool([new Invoice], responseHandler: fn () => Handler::response()); }
 public function consumed(Billing $billing) { $billing->pool([new Invoice], concurrency: fn () => Handler::concurrency(), responseHandler: fn () => Handler::response(), exceptionHandler: fn () => Handler::exception())->send(); }
 public function fluent(Billing $billing) { $billing->pool([new Invoice])->withResponseHandler(callable: fn () => Handler::response())->withExceptionHandler(fn () => Handler::exception())->setConcurrency(concurrency: fn () => Handler::concurrency())->send(); }
 public function constructed(Billing $billing) { (new \Saloon\Http\Pool($billing, [new Invoice], responseHandler: fn () => Handler::response()))->send(); }
 public function replaced(Billing $billing) { $pool = $billing->pool([new Invoice], responseHandler: fn () => Handler::response()); $pool->setRequests([new Invoice]); $pool->send(); }
 public function override(Billing $billing) { $pool = $billing->pool([new Invoice], responseHandler: fn () => Handler::response()); $alias = $pool; $alias->withResponseHandler(fn () => Handler::exception()); $pool->send(); }
 public function generator(Billing $billing) { $billing->pool([new Invoice], responseHandler: function () { Handler::response(); yield 1; })->send(); }
 public function empty(Billing $billing) { $billing->pool([], responseHandler: fn () => Handler::response(), exceptionHandler: fn () => Handler::exception())->send(); }
 public function fixedConcurrency(Billing $billing) { $billing->pool([new Invoice], concurrency: fn () => Handler::concurrency())->setConcurrency(5)->send(); }
}
PHP);
        $index = $this->index($sources);
        $query = new GraphQuery($index);
        $response = $index->names[strtolower('App\\Handler::response')][0];
        $id = fn ($method) => $index->names[strtolower('App\\Action::'.$method)][0];
        $this->assertSame('no_path_in_analyzed_graph', $query->query($id('prepared'), 'path', $response, 8)['status']);
        foreach (['consumed', 'fluent', 'constructed', 'replaced'] as $method) {
            $this->assertSame('found', $query->query($id($method), 'path', $response, 8)['status'], $method);
        }
        foreach (['override', 'generator', 'empty'] as $method) {
            $this->assertSame('no_path_in_analyzed_graph', $query->query($id($method), 'path', $response, 8)['status'], $method);
        }
        $this->assertSame('no_path_in_analyzed_graph', $query->query($id('fixedConcurrency'), 'path', $index->names[strtolower('App\\Handler::concurrency')][0], 8)['status']);
        $invocations = array_values(array_filter($index->relations, fn ($edge) => str_starts_with($edge['kind'], 'invokes-saloon-pool-') && str_ends_with($edge['kind'], '-handler')));
        $this->assertCount(9, $invocations);
        foreach ($invocations as $edge) {
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
            $this->assertArrayHasKey('registration_source', $edge['metadata']);
        }
        $this->assertCount(7, $this->sends($index));
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_prepared_promise_members_are_consumed_without_a_second_transport(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class ExistingPromise implements \GuzzleHttp\Promise\PromiseInterface {
 public function then(?callable $onFulfilled = null, ?callable $onRejected = null): \GuzzleHttp\Promise\PromiseInterface { return $this; }
 public function otherwise(callable $onRejected): \GuzzleHttp\Promise\PromiseInterface { return $this; }
 public function getState(): string { return 'fulfilled'; }
 public function resolve($value): void {}
 public function reject($reason): void {}
 public function cancel(): void {}
 public function wait(bool $unwrap = true) { return null; }
}
class Handler { static function response() {} }
class Decoy { function sendAsync($request) { return null; } }
class Action {
 public function prepared(Billing $billing) { $billing->pool([$billing->sendAsync(new Invoice('body-secret'))]); }
 public function consumed(Billing $billing) { $billing->pool([$billing->sendAsync(new Invoice)])->send(); }
 public function mixed(Billing $billing) { $billing->pool([$billing->sendAsync(new Invoice), new Invoice])->send(); }
 public function existing(Billing $billing) { $billing->pool([new ExistingPromise('value-secret')], responseHandler: fn () => Handler::response())->send(); }
 public function decoy(Billing $billing, Decoy $other) { $billing->pool([$other->sendAsync(new Invoice)], responseHandler: fn () => Handler::response())->send(); }
}
PHP);
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(4, $sends);
        $consumed = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'consumes-saloon-pool-promise'));
        $this->assertCount(3, $consumed);
        foreach ($consumed as $edge) {
            $this->assertFalse($edge['metadata']['prepares_another_send']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $query = new GraphQuery($index);
        $response = $index->names[strtolower('App\\Handler::response')][0];
        $this->assertSame('found', $query->query($index->names[strtolower('App\\Action::existing')][0], 'path', $response, 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\\Action::decoy')][0], 'path', $response, 8)['status']);
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_pool_member_variables_follow_source_origins_without_repeating_calls(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Action {
 public function assigned(Billing $billing) { $promise = $billing->sendAsync(new Invoice); $alias = $promise; $billing->pool([$alias])->send(); }
 public function replaced(Billing $billing) { $promise = $billing->sendAsync(new Invoice); $pool = $billing->pool([]); $pool->setRequests([$promise]); $pool->send(); }
 public function typed(Billing $billing, Invoice $request) { $billing->pool([$request])->send(); }
 public function reassigned(Billing $billing) { $promise = $billing->sendAsync(new Invoice); $promise = null; $billing->pool([$promise])->send(); }
 public function byref(Billing $billing) { $promise = $billing->sendAsync(new Invoice); $billing->pool([&$promise])->send(); }
}
PHP);
        $index = $this->index($sources);
        $this->assertCount(5, $this->sends($index));
        $consumed = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'consumes-saloon-pool-promise'));
        $this->assertCount(2, $consumed);
        foreach ($consumed as $edge) {
            $this->assertFalse($edge['metadata']['prepares_another_send']);
            $this->assertLessThan($index->elements[$edge['metadata']['pool_source']['id']]['offset'], $edge['metadata']['promise_source']['offset']);
        }
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_whole_member_list_variables_and_aliases_use_current_source_values(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class LocalPool extends \Saloon\Http\Pool {}
class Action {
 public function alias(Billing $billing) { $promise = $billing->sendAsync(new Invoice); $requests = [$promise]; $alias = $requests; $billing->pool($alias)->send(); }
 public function constructed(Billing $billing) { $requests = [new Invoice]; (new LocalPool($billing, $requests))->send(); }
 public function setter(Billing $billing) { $pool = $billing->pool([]); $requests = [new Invoice]; $pool->setRequests($requests); $pool->send(); }
 public function empty(Billing $billing) { $requests = [new Invoice]; $requests = []; $billing->pool($requests)->send(); }
 public function mutated(Billing $billing) { $requests = [new Invoice]; $requests[0] = null; $billing->pool($requests)->send(); }
 public function privacy(Billing $billing) { $requests = ['body-secret']; $billing->pool($requests)->send(); }
}
PHP);
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(3, $sends);
        $this->assertSame(['App\\Action::alias', 'App\\Action::constructed', 'App\\Action::setter'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $sends));
        $consumed = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'consumes-saloon-pool-promise'));
        $this->assertCount(1, $consumed);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_noninline_pool_handlers_keep_registration_and_conditional_execution_separate(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Handler {
 public function response() {}
 public static function failed() {}
 public function __invoke() {}
 public function generator() { yield null; }
 private function hidden() {}
}
class Action {
 private function local() {}
 public function prepared(Billing $billing) { $callback = fn () => Handler::failed(); $billing->pool([new Invoice], responseHandler: $callback); }
 public function closure(Billing $billing) { $callback = fn () => Handler::failed(); $alias = $callback; $billing->pool([new Invoice])->withResponseHandler($alias)->send(); }
 public function firstClass(Billing $billing) { $callback = (new Handler)->response(...); $billing->pool([new Invoice], responseHandler: $callback)->send(); }
 public function arrayCallback(Billing $billing) { $callback = [Handler::class, 'failed']; $billing->pool([new Invoice], exceptionHandler: $callback)->send(); }
 public function invokable(Billing $billing) { $callback = new Handler; $billing->pool([new Invoice], responseHandler: $callback)->send(); }
 public function boundPrivate(Billing $billing) { $callback = $this->local(...); $billing->pool([new Invoice], responseHandler: $callback)->send(); }
 public function boundSelf(Billing $billing) { $callback = self::local(...); $billing->pool([new Invoice], responseHandler: $callback)->send(); }
 public function generator(Billing $billing) { $billing->pool([new Invoice], responseHandler: (new Handler)->generator(...))->send(); }
 public function hidden(Billing $billing) { $billing->pool([new Invoice], responseHandler: [new Handler, 'hidden'])->send(); }
 public function replaced(Billing $billing) { $callback = (new Handler)->response(...); $callback = null; $billing->pool([new Invoice], responseHandler: $callback)->send(); }
 public function empty(Billing $billing) { $billing->pool([], responseHandler: new Handler)->send(); }
}
PHP);
        $index = $this->index($sources);
        $invocations = array_values(array_filter($index->relations, fn ($edge) => str_starts_with($edge['kind'], 'invokes-saloon-pool-') && str_ends_with($edge['kind'], '-handler')));
        $this->assertCount(6, $invocations);
        $this->assertSame(['App\\Action::closure', 'App\\Action::firstClass', 'App\\Action::arrayCallback', 'App\\Action::invokable', 'App\\Action::boundPrivate', 'App\\Action::boundSelf'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $invocations));
        foreach ($invocations as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['conditions']);
        }
        $registered = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'registers-saloon-pool-callback'));
        $this->assertCount(8, $registered);
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_pool_callback_factories_preserve_source_and_reject_generator_and_private_factories(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Action {
 public function closure(Billing $billing) { $billing->pool([new Invoice], responseHandler: (new HandlerFactory)->closure())->send(); }
 public function method(Billing $billing) { $billing->pool([new Invoice])->withResponseHandler((new HandlerFactory)->nested())->send(); }
 public function object(Billing $billing) { $billing->pool([new Invoice], exceptionHandler: (new HandlerFactory)->object())->send(); }
 public function generator(Billing $billing) { $billing->pool([new Invoice], responseHandler: (new HandlerFactory)->generator())->send(); }
 public function hidden(Billing $billing) { $billing->pool([new Invoice], responseHandler: (new HandlerFactory)->hidden())->send(); }
 public function recursive(Billing $billing) { $billing->pool([new Invoice], responseHandler: (new HandlerFactory)->recursive())->send(); }
}
PHP);
        $sources['app/HandlerFactory.php'] = <<<'PHP'
namespace App;
throw new RuntimeException('never execute');
class Handler { public function __invoke() {} }
class HandlerFactory {
 private function respond() {}
 public function closure() { return fn () => null; }
 public function method() { return $this->respond(...); }
 public function nested() { return $this->method(); }
 public function object() { return new Handler('body-secret'); }
 public function generator() { yield null; return fn () => null; }
 private function hidden() { return fn () => null; }
 public function recursive() { return $this->recursive(); }
}
PHP;
        $index = $this->index($sources);
        $invocations = array_values(array_filter($index->relations, fn ($edge) => str_starts_with($edge['kind'], 'invokes-saloon-pool-') && str_ends_with($edge['kind'], '-handler')));
        $this->assertCount(3, $invocations);
        foreach ($invocations as $edge) {
            $this->assertSame('app/HandlerFactory.php', $edge['metadata']['registration_source']['callback_return_sources'][0]['path']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_source_factory_lists_compose_across_files_without_executing_or_repeating_transport(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Action {
 public function direct(Billing $billing) { $billing->pool((new Factory)->requests())->send(); }
 public function alias(Billing $billing) { $list = Factory::staticRequests(); $alias = $list; $billing->pool($alias)->send(); }
 public function setter(Billing $billing) { $billing->pool([])->setRequests((new Factory)->nested())->send(); }
 public function constructor(Billing $billing) { (new \Saloon\Http\Pool($billing, (new ChildFactory)->requests()))->send(); }
 public function promises(Billing $billing) { $billing->pool((new Factory)->promises($billing))->send(); }
 public function hidden(Billing $billing) { $billing->pool((new Factory)->hidden())->send(); }
 public function generator(Billing $billing) { $billing->pool((new Factory)->generator())->send(); }
 public function recursive(Billing $billing) { $billing->pool((new Factory)->recursive())->send(); }
 public function incomplete(Billing $billing) { $billing->pool((new Factory)->incomplete())->send(); }
 public function empty(Billing $billing) { $billing->pool((new Factory)->empty())->send(); }
}
PHP);
        $sources['app/Factory.php'] = <<<'PHP'
namespace App;
throw new \RuntimeException('never execute');
class Factory {
 public function requests() { return [new Invoice('body-secret')]; }
 public static function staticRequests() { return [new Invoice]; }
 public function nested() { return $this->requests(); }
 public function promises(Billing $billing) { return [$billing->sendAsync(new Invoice)]; }
 private function hidden() { return [new Invoice]; }
 public function generator() { yield null; return [new Invoice]; }
 public function recursive() { return $this->recursive(); }
 public function incomplete() { if (unknown()) { return [new Invoice]; } return unknown(); }
 public function empty() { return []; }
}
class ChildFactory extends Factory {}
PHP;
        $index = $this->index($sources);
        $this->assertSame(['App\\Factory::promises', 'App\\Action::direct', 'App\\Action::alias', 'App\\Action::setter', 'App\\Action::constructor', 'App\\Action::incomplete'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->sends($index)));
        $consumed = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'consumes-saloon-pool-promise'));
        $this->assertCount(1, $consumed);
        $this->assertSame('app/Factory.php', $consumed[0]['metadata']['promise_source']['path']);
        $poolSends = array_values(array_filter($this->sends($index), fn ($edge) => isset($edge['metadata']['pool_source'])));
        $this->assertCount(5, $poolSends);
        foreach ($poolSends as $send) {
            $this->assertSame('app/Factory.php', $send['metadata']['pool_source']['member_return_sources'][0]['path']);
        }
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_typed_and_constructed_requests_keep_source_url_proofs_without_credentials(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
throw new \RuntimeException('never execute source');
class Action {
 public function sync(Billing $billing, Invoice $invoice) { $billing->send(request: $invoice); }
 public function async() { (new Billing)->sendAsync(new Invoice('body-secret')); }
 public function retry(Billing $billing) { $billing->sendAndRetry(interval: 1, request: new Invoice, tries: 2); }
 public function prepare(Billing $billing) { $billing->createPendingRequest(new Invoice); }
}
PHP);
        $facts = $this->facts($sources);
        $this->assertStringNotContainsString('-secret', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $index = new CatalogIndex($facts, fn ($path) => isset($sources[$path]) ? '<?php '.$sources[$path] : null);
        $sends = $this->sends($index);
        $this->assertCount(3, $sends);
        $this->assertSame(['send', 'sendasync', 'sendandretry'], array_column(array_column($sends, 'metadata'), 'mode'));
        foreach ($sends as $send) {
            $this->assertSame('https://billing.test/api/invoices', $index->elements[$send['to']]['name']);
            $this->assertFalse($send['metadata']['execution_proven']);
            $this->assertFalse($send['metadata']['traffic_proven']);
            $this->assertSame('conditional', $send['resolution']);
            $this->assertSame(['resolveBaseUrl', 'resolveEndpoint'], array_column($send['metadata']['selector_sources'], 'selector'));
        }
        $this->assertSame([false, true, false], array_column(array_column($sends, 'metadata'), 'asynchronous'));
        $this->assertContains('The asynchronous task must be consumed.', $sends[1]['metadata']['conditions']);
        $query = new GraphQuery($index);
        $this->assertSame('found', $query->query($index->names[strtolower('App\\Action::sync')][0], 'path', $sends[0]['to'], 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\\Action::prepare')][0], 'path', $sends[0]['to'], 8)['status']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations, $index->diagnostics], JSON_THROW_ON_ERROR));
    }

    public function test_dynamic_invalid_named_and_first_class_calls_do_not_create_transport_edges(): void
    {
        $index = $this->index($this->sources(<<<'PHP'
namespace App;
class Action {
 public function unknown(Billing $billing, $request) { $billing->send($request); }
 public function unpack(Billing $billing, $args) { $billing->send(...$args); }
 public function badName(Billing $billing) { $billing->send(payload: new Invoice); }
 public function reference(Billing $billing) { $billing->send(...); }
 public function decoy() { (new Decoy)->send(new Invoice); }
}
class Decoy { public function send($request) {} }
PHP));
        $this->assertSame([], $this->sends($index));
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_empty_base_accepts_an_absolute_endpoint_without_inventing_a_send_for_unused_requests(): void
    {
        $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } public function unused() { return new Invoice; } }');
        $sources['app/Integrations/Billing.php'] = 'namespace App; class Billing extends \\Saloon\\Http\\Connector { public function resolveBaseUrl() { return ""; } }';
        $sources['app/Integrations/Invoice.php'] = 'namespace App; class Invoice extends \\Saloon\\Http\\Request { public function resolveEndpoint() { return "https://user-secret:password-secret@solo.test/invoices?token=query-secret"; } }';
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(1, $sends);
        $this->assertSame('https://solo.test/invoices', $index->elements[$sends[0]['to']]['name']);
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($index->names[strtolower('App\\Action::unused')][0], 'path', $sends[0]['to'], 8)['status']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_source_send_override_including_trait_alias_suppresses_framework_pipeline(): void
    {
        foreach ([
            'public function send($request) {}',
            'public function createPendingRequest($request) {}',
            'public function sender() {}',
            'use Changes { replacement as send; }',
        ] as $override) {
            $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
            $sources['app/Integrations/Billing.php'] = 'namespace App; trait Changes { public function replacement($request) {} } class Billing extends \\Saloon\\Http\\Connector { '.$override.' public function resolveBaseUrl() { return "https://billing.test"; } }';
            $index = $this->index($sources);
            $this->assertSame([], $this->sends($index), $override);
            $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_cached_consumer_refreshes_selected_url_after_edit_and_rejects_stale_source(): void
    {
        $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
        $facts = $this->facts($sources);
        $changed = $sources;
        $changed['app/Integrations/Invoice.php'] = str_replace('invoices?', 'archives?', $sources['app/Integrations/Invoice.php']);
        $stale = new CatalogIndex($facts, fn ($path) => isset($changed[$path]) ? '<?php '.$changed[$path] : null);
        $this->assertSame([], $this->sends($stale));
        $this->assertContains('changed_inputs', array_column($stale->diagnostics, 'code'));
        $newFacts = $this->facts($changed);
        foreach ($facts as $position => $fact) {
            if ($fact->path === 'app/Integrations/Invoice.php') {
                $facts[$position] = array_values(array_filter($newFacts, fn ($row) => $row->path === $fact->path))[0];
            }
        }
        $fresh = new CatalogIndex($facts, fn ($path) => isset($changed[$path]) ? '<?php '.$changed[$path] : null);
        $this->assertSame('https://billing.test/api/archives', $fresh->elements[$this->sends($fresh)[0]['to']]['name']);
    }

    public function test_unverified_version_shadow_private_and_dynamic_selectors_stay_explicit(): void
    {
        $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
        $index = $this->index($sources, '99.0.0');
        $this->assertSame([], $this->sends($index));
        $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        foreach (['private function resolveEndpoint() { return "invoices"; }', 'public function resolveEndpoint() { return env("ENDPOINT"); }',
            'public function resolveEndpoint() { return "https://other.test/invoices"; }'] as $selector) {
            $sources['app/Integrations/Invoice.php'] = 'namespace App; class Invoice extends \\Saloon\\Http\\Request { '.$selector.' }';
            $index = $this->index($sources);
            $this->assertSame([], $this->sends($index));
            $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        }
        $sources['app/Shadow.php'] = 'namespace Saloon\\Http; class Connector {}';
        $this->assertSame([], $this->sends($this->index($sources)));
    }

    public function test_corrupted_request_descriptor_cannot_retain_extra_payload_fields(): void
    {
        $facts = $this->facts($this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }'));
        $fact = array_values(array_filter($facts, fn ($row) => $row->path === 'app/Action.php'))[0];
        $raw = $fact->toArray();
        foreach ($raw['relations'] as &$edge) {
            if (isset($edge['metadata']['saloon_request'])) {
                $edge['metadata']['saloon_request']['payload'] = 'credential-secret';
            }
        }
        unset($edge);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_solo_requests_and_source_connector_factories_resolve_request_side_signatures(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
class Solo extends \Saloon\Http\SoloRequest {
 public function resolveEndpoint() { return 'https://solo.test/invoices?token=query-secret'; }
}
trait Connects { protected function resolveConnector() { return new Billing; } }
class BoundRequest extends \Saloon\Http\Request {
 use \Saloon\Traits\Request\HasConnector, Connects;
 public function resolveEndpoint() { return 'requests'; }
}
class Action {
 public function solo(Solo $request) { $request->send(); }
 public function async() { (new Solo)->sendAsync(mockClient: null); }
 public function prepare(Solo $request) { $request->createPendingRequest(); }
 public function bound(BoundRequest $request) { $request->send(); }
}
PHP);
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(3, $sends);
        $this->assertSame(['https://solo.test/invoices', 'https://solo.test/invoices', 'https://billing.test/api/requests'],
            array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $sends));
        foreach ($sends as $send) {
            $this->assertTrue($send['metadata']['request_side']);
            $this->assertFalse($send['metadata']['traffic_proven']);
        }
        $query = new GraphQuery($index);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\\Action::prepare')][0], 'path', $sends[0]['to'], 8)['status']);
        $this->assertSame('found', $query->query($index->names[strtolower('App\\Action::bound')][0], 'path', $index->names[strtolower('App\\Connects::resolveConnector')][0], 8)['status']);
        $this->assertContains('sdk-request', $index->elements[$index->namedTypes('App\\Solo')[0]]['roles']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_request_side_source_overrides_unknown_factories_and_invalid_arguments_are_not_transport(): void
    {
        foreach (['public function send() {}', 'public function connector() {}', 'public function sender() {}',
            'protected function resolveConnector() { return unknown(); }', 'private function resolveConnector() { return new Billing; }'] as $override) {
            $sources = $this->sources('namespace App; class Solo extends \\Saloon\\Http\\SoloRequest { '.$override.' public function resolveEndpoint() { return "https://solo.test"; } } class Action { public function run(Solo $request) { $request->send(); } }');
            $index = $this->index($sources);
            $this->assertSame([], $this->sends($index), $override);
            $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        }
        $sources = $this->sources('namespace App; class Solo extends \\Saloon\\Http\\SoloRequest { public function resolveEndpoint() { return "https://solo.test"; } } class Action { public function run(Solo $request) { $request->send(request: new Invoice); } }');
        $this->assertSame([], $this->sends($this->index($sources)));
        $sources['app/Action.php'] = 'namespace App; class Solo extends \\Saloon\\Http\\SoloRequest { public function resolveEndpoint() { return "https://solo.test"; } } class Action { public function run(Solo $request) { $request->send(mockClient: new Invoice); } }';
        $this->assertSame([], $this->sends($this->index($sources)));
        foreach (['"credential-secret"', '[]', 'false'] as $argument) {
            $sources['app/Action.php'] = 'namespace App; class Solo extends \\Saloon\\Http\\SoloRequest { public function resolveEndpoint() { return "https://solo.test"; } } class Action { public function run(Solo $request) { $request->send(mockClient: '.$argument.'); } }';
            $this->assertSame([], $this->sends($this->index($sources)));
        }
    }

    public function test_inherited_connector_properties_are_source_selectors_and_dynamic_values_are_not_guessed(): void
    {
        $sources = $this->sources(<<<'PHP'
namespace App;
trait ConnectorChoice { protected string $connector = Billing::class; }
class BaseRequest extends \Saloon\Http\Request { use \Saloon\Traits\Request\HasConnector, ConnectorChoice; }
class Bound extends BaseRequest { public function resolveEndpoint() { return 'properties'; } }
class Action { public function run(Bound $request) { $request->send(); } }
PHP);
        $index = $this->index($sources);
        $sends = $this->sends($index);
        $this->assertCount(1, $sends);
        $this->assertSame('https://billing.test/api/properties', $index->elements[$sends[0]['to']]['name']);
        $property = $index->names[strtolower('App\\ConnectorChoice::$connector')][0];
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($index->names[strtolower('App\\Action::run')][0], 'path', $property, 8)['status']);
        foreach (['protected string $connector = "credential-secret";', 'protected static string $connector = Billing::class;'] as $declaration) {
            $sources['app/Action.php'] = 'namespace App; class Bound extends \\Saloon\\Http\\Request { use \\Saloon\\Traits\\Request\\HasConnector; '.$declaration.' public function resolveEndpoint() { return "properties"; } } class Action { public function run(Bound $request) { $request->send(); } }';
            $index = $this->index($sources);
            $this->assertSame([], $this->sends($index));
            $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
            $this->assertStringNotContainsString('credential-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        }
        $sources['app/Action.php'] = 'namespace App; class Bound extends \\Saloon\\Http\\Request { use \\Saloon\\Traits\\Request\\HasConnector; private string $connector = Billing::class; public function resolveEndpoint() { return "properties"; } } class Action { public function run(Bound $request) { $request->send(); } }';
        $this->assertCount(1, $this->sends($this->index($sources)));
        $sources['app/Action.php'] = 'namespace App; class ParentRequest extends \\Saloon\\Http\\Request { use \\Saloon\\Traits\\Request\\HasConnector; } class Bound extends ParentRequest { private string $connector = Billing::class; public function resolveEndpoint() { return "properties"; } } class Action { public function run(Bound $request) { $request->send(); } }';
        $this->assertSame([], $this->sends($this->index($sources)));
    }

    public function test_absolute_endpoint_override_uses_request_precedence_then_connector_defaults(): void
    {
        foreach ([['true', 'false', true], ['false', 'true', false], ['null', 'true', true], ['null', 'false', false], ['', 'true', true], ['', '', false]] as [$requestFlag, $connectorFlag, $expected]) {
            $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
            $sources['app/Integrations/Billing.php'] = 'namespace App; class Billing extends \\Saloon\\Http\\Connector { '.($connectorFlag === '' ? '' : 'public bool $allowBaseUrlOverride = '.$connectorFlag.';').' public function resolveBaseUrl() { return "https://base.test"; } }';
            $sources['app/Integrations/Invoice.php'] = 'namespace App; class Invoice extends \\Saloon\\Http\\Request { '.($requestFlag === '' ? '' : 'public ?bool $allowBaseUrlOverride = '.$requestFlag.';').' public function resolveEndpoint() { return "https://user-secret:password-secret@uploads.test/file?token=query-secret"; } }';
            $index = $this->index($sources);
            $sends = $this->sends($index);
            $this->assertCount($expected ? 1 : 0, $sends, $requestFlag.'/'.$connectorFlag);
            if ($expected) {
                $this->assertSame('https://uploads.test/file', $index->elements[$sends[0]['to']]['name']);
                $this->assertTrue($sends[0]['metadata']['absolute_endpoint_override']);
                $this->assertFalse($sends[0]['metadata']['traffic_proven']);
            } else {
                $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
            }
            $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        }
    }

    public function test_cached_trait_override_changes_recompose_without_executing_flag_properties(): void
    {
        $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
        $sources['app/Integrations/Billing.php'] = 'namespace App; class Billing extends \\Saloon\\Http\\Connector { use Permits; public function resolveBaseUrl() { return "https://base.test"; } }';
        $sources['app/Integrations/Invoice.php'] = 'namespace App; class Invoice extends \\Saloon\\Http\\Request { public function resolveEndpoint() { return "https://uploads.test/file"; } }';
        $sources['app/Permits.php'] = 'namespace App; trait Permits { public bool $allowBaseUrlOverride = true; }';
        $facts = $this->facts($sources);
        $index = new CatalogIndex($facts, fn ($path) => isset($sources[$path]) ? '<?php '.$sources[$path] : null);
        $this->assertCount(1, $this->sends($index));
        $property = $index->names[strtolower('App\\Permits::$allowBaseUrlOverride')][0];
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($index->names[strtolower('App\\Action::run')][0], 'path', $property, 8)['status']);
        $sources['app/Permits.php'] = str_replace('true', 'false', $sources['app/Permits.php']);
        $updated = $this->facts(['app/Permits.php' => $sources['app/Permits.php']])[0];
        foreach ($facts as $position => $fact) {
            if ($fact->path === $updated->path) {
                $facts[$position] = $updated;
            }
        }
        $this->assertSame([], $this->sends(new CatalogIndex($facts, fn ($path) => isset($sources[$path]) ? '<?php '.$sources[$path] : null)));
    }

    public function test_dynamic_private_static_and_conflicting_override_flags_remain_unresolved(): void
    {
        foreach (['public ?bool $allowBaseUrlOverride = SOME_FLAG;', 'private ?bool $allowBaseUrlOverride = true;', 'public static ?bool $allowBaseUrlOverride = true;',
            'use FirstFlag, SecondFlag;'] as $flag) {
            $sources = $this->sources('namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
            $sources['app/Integrations/Invoice.php'] = 'namespace App; trait FirstFlag { public ?bool $allowBaseUrlOverride = true; } trait SecondFlag { public ?bool $allowBaseUrlOverride = false; } class Invoice extends \\Saloon\\Http\\Request { '.$flag.' public function resolveEndpoint() { return "https://uploads.test/file"; } }';
            $index = $this->index($sources);
            $this->assertSame([], $this->sends($index));
            $this->assertContains('saloon_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_solo_default_allows_absolute_endpoint_with_source_selected_nonempty_connector(): void
    {
        $sources = $this->sources('namespace App; class Solo extends \\Saloon\\Http\\SoloRequest { protected function resolveConnector() { return new Billing; } public function resolveEndpoint() { return "https://uploads.test/file"; } } class Action { public function run(Solo $request) { $request->send(); } }');
        $index = $this->index($sources);
        $this->assertCount(1, $this->sends($index));
        $this->assertSame('https://uploads.test/file', $index->elements[$this->sends($index)[0]['to']]['name']);
    }
}
