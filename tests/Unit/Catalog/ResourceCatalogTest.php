<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

final class ResourceCatalogTest extends TestCase
{
    private function index(string $source): CatalogIndex
    {
        return new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build([new FileContext('app/Process.php', '<?php '.$source)])->catalogFacts);
    }

    public function test_resource_operations_have_owner_evidence_and_omit_payloads_and_credentials(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
use Illuminate\Support\Facades\View as V;
use Illuminate\Support\Facades\Cache as C;
use Illuminate\Support\Facades\Http as H;
use Illuminate\Support\Facades\Storage as S;
use Illuminate\Support\Facades\Config as Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
throw new \RuntimeException('do not run application source');
class Process {
    public function run() {
        V::make('billing.invoice', ['private' => 'payload-secret']);
        view('billing.summary', ['private' => 'payload-secret']);
        C::store('redis')->put('invoice-state', 'cache-secret');
        C::lock('invoice-lock', 10);
        S::disk('exports');
        Settings::get('services.billing.url');
        H::withToken('token-secret')->post('https://user-secret:password-secret@billing.test/invoices?key=query-secret#fragment-secret', ['payload' => 'body-secret']);
        Mail::to('recipient-secret')->queue(new InvoiceMail('argument-secret'));
        Notification::send($users, new InvoiceNotification('argument-secret'));
    }
}
class InvoiceMail extends \Illuminate\Mail\Mailable {}
class InvoiceNotification extends \Illuminate\Notifications\Notification {}
PHP);
        $names = array_column($index->elements, 'name');
        foreach (['billing.invoice', 'billing.summary', 'redis', 'invoice-state', 'invoice-lock', 'exports', 'services.billing.url', 'https://billing.test/invoices'] as $name) {
            $this->assertContains($name, $names);
        }
        $run = $index->names[strtolower('App\\Process::run')][0];
        $edges = array_values(array_filter($index->relations, fn ($row) => $row['from'] === $run && in_array($row['kind'], ['renders', 'reads', 'writes', 'selects-store', 'prepares-lock', 'selects-disk', 'uses-external-service', 'sends-mail', 'sends-notification'], true)));
        $this->assertCount(10, $edges);
        foreach ($edges as $edge) {
            $this->assertSame('app/Process.php', $edge['path']);
            $this->assertGreaterThan(1, $edge['line']);
        }
        $mail = array_values(array_filter($edges, fn ($row) => $row['kind'] === 'sends-mail'))[0];
        $this->assertTrue($mail['metadata']['queued']);
        $this->assertFalse($mail['metadata']['execution_proven']);
        $this->assertSame($index->namedTypes('App\\InvoiceMail')[0], $mail['to']);
        $json = json_encode([$index->elements, $index->relations, $index->diagnostics]);
        $this->assertStringNotContainsString('-secret', $json);
        $this->assertSame(['unresolved_constructor', 'unresolved_constructor'], array_column($index->diagnostics, 'code'));
        $this->assertSame([19, 20], array_column($index->diagnostics, 'line'));
    }

    public function test_similar_methods_and_shadowed_helpers_do_not_create_framework_resource_edges(): void
    {
        $index = $this->index('namespace App; use App\\View; use App\\Cache; use App\\Http; use function App\\view; class Process { public function run() { View::make("decoy"); Cache::get("decoy"); Http::get("https://decoy.test"); view("decoy"); } }');
        $this->assertSame([], array_values(array_filter($index->elements, fn ($row) => $row['metadata']['logical_resource'] ?? false)));
        $this->assertSame([], array_values(array_filter($index->relations, fn ($row) => in_array($row['kind'], ['renders', 'reads', 'uses-external-service'], true))));
    }

    public function test_dynamic_identifiers_have_diagnostics_and_no_invented_resource(): void
    {
        $index = $this->index('namespace App; use Illuminate\\Support\\Facades\\View; class Process { public function run($name) { View::make($name); } }');
        $this->assertSame(['dynamic_resource'], array_column($index->diagnostics, 'code'));
        $this->assertSame([], array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'view')));
    }

    public function test_view_composer_registration_points_to_callback_without_proving_execution(): void
    {
        $index = $this->index('use Illuminate\\Support\\Facades\\View; View::composer("billing.invoice", function () { return view("billing.footer"); });');
        $registration = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'view-composer'))[0];
        $this->assertSame('view', $index->elements[$registration['from']]['kind']);
        $this->assertSame('closure', $index->elements[$registration['to']]['kind']);
        $render = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'renders'))[0];
        $this->assertSame($registration['to'], $render['from']);
        $this->assertSame('billing.footer', $index->elements[$render['to']]['name']);
    }

    public function test_store_qualified_keys_lock_preparation_and_conditional_callbacks(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
use Illuminate\Support\Facades\Cache as C;
class Service { public static function load() {} public static function protectedWork() {} }
class Process { public function run() {
 C::store('redis')->get('invoice');
 C::store('file')->put('invoice', 'payload-secret');
 C::remember('computed', 10, fn () => Service::load());
 C::lock('mutex', 10);
 C::store('redis')->lock('mutex', 10)->block(1, fn () => Service::protectedWork());
 C::lock('mutex')->release();
 C::pull('once');
 cache(['helper-key' => 'helper-secret'], 10);
 cache()->get('helper-key');
} }
PHP);
        $keys = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'cache-key' && $row['name'] === 'invoice'));
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0]['id'], $keys[1]['id']);
        $this->assertSame(['redis', 'file'], array_column(array_column($keys, 'metadata'), 'store'));
        $kinds = array_column($index->relations, 'kind');
        $this->assertSame(3, count(array_filter($kinds, fn ($kind) => $kind === 'prepares-lock')));
        $this->assertSame(1, count(array_filter($kinds, fn ($kind) => $kind === 'acquires-lock')));
        $this->assertSame(1, count(array_filter($kinds, fn ($kind) => $kind === 'releases-lock')));
        $callbacks = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'resource-callback'));
        $this->assertCount(2, $callbacks);
        $this->assertSame([['Cache key must be absent.'], ['Lock acquisition must succeed.']], array_column(array_column($callbacks, 'metadata'), 'conditions'));
        $query = new GraphQuery($index);
        foreach (['load', 'protectedWork'] as $method) {
            $this->assertSame('found', $query->query($index->names[strtolower('App\\Process::run')][0], 'path', $index->names[strtolower('App\\Service::'.$method)][0], 8)['status']);
        }
        $pull = array_values(array_filter($index->relations, fn ($row) => ($row['metadata']['operation'] ?? null) === 'pull'));
        $this->assertSame(['reads', 'writes'], array_column($pull, 'kind'));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_storage_effects_config_array_writes_and_literal_concatenation(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
use Illuminate\Support\Facades\Storage as Files;
use Illuminate\Support\Facades\Config as Settings;
class Process { public function run() {
 Files::disk('exports')->get('path-secret');
 Files::disk('exports')->put('path-secret', 'body-secret');
 Files::copy('source-secret', 'destination-secret');
 Files::cloud()->delete('path-secret');
 Settings::string('services.'.'billing');
 config(['feature.enabled' => 'value-secret', 'feature.mode' => 'value-secret']);
 config()->set('feature.option', 'value-secret');
} }
PHP);
        $effects = array_values(array_filter($index->relations, fn ($row) => in_array($row['kind'], ['reads-storage', 'writes-storage'], true)));
        $this->assertCount(5, $effects);
        $this->assertSame(['exports', 'exports', '(default)', '(default)', '(cloud)'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $effects));
        $this->assertSame(['reads-storage', 'writes-storage', 'reads-storage', 'writes-storage', 'writes-storage'], array_column($effects, 'kind'));
        foreach (['services.billing', 'feature.enabled', 'feature.mode', 'feature.option'] as $name) {
            $this->assertArrayHasKey($name, $index->names);
        }
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_dynamic_store_and_unknown_receiver_changes_do_not_invent_effects(): void
    {
        $index = $this->index(<<<'PHP'
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
Cache::store($store)->get('unknown-store-key');
Cache::custom()->put('unknown-receiver-key', 'payload');
Storage::disk($disk)->put('file', 'payload');
Cache::get('resolved-key')->put('value-receiver-key', 'payload');
cache([...$values, 'not-first-key' => 'payload']);
PHP);
        $names = array_column($index->elements, 'name');
        foreach (['unknown-store-key', 'unknown-receiver-key', 'value-receiver-key', 'not-first-key'] as $name) {
            $this->assertNotContains($name, $names);
        }
        $this->assertContains('resolved-key', $names);
        $this->assertContains('dynamic_resource', array_column($index->diagnostics, 'code'));
        $this->assertContains('unsupported_resource_chain', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_resource_calls_recheck_source_facade_and_helper_shadows(): void
    {
        $builder = new ProjectGraphBuilder(catalog: true);
        $fixed = $builder->build([new FileContext('app/Process.php', '<?php \\Illuminate\\Support\\Facades\\Cache::remember("key", 1, fn () => App\\Service::run()); config("feature.enabled"); class AppDecoy {}')])->catalogFacts;
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $service = $builder->build([new FileContext('app/Service.php', '<?php namespace App; class Service { public static function run() {} }')])->catalogFacts;
        $shadow = $builder->build([new FileContext('app/Shadow.php', '<?php namespace Illuminate\\Support\\Facades { class Cache {} } namespace { function config($key) {} }')])->catalogFacts;
        $clean = new CatalogIndex([...$fixed, ...$service]);
        $shadowed = new CatalogIndex([...$fixed, ...$service, ...$shadow]);
        $query = new GraphQuery($shadowed);
        $file = array_values(array_filter($shadowed->elements, fn ($row) => $row['kind'] === 'file' && $row['path'] === 'app/Process.php'))[0];
        $this->assertSame('no_path_in_analyzed_graph', $query->query($file['id'], 'path', $shadowed->names[strtolower('App\\Service::run')][0], 8)['status']);
        $this->assertCount(1, array_filter($clean->relations, fn ($row) => $row['kind'] === 'resource-callback'));
        $this->assertCount(1, array_filter($shadowed->relations, fn ($row) => $row['kind'] === 'references-resource-callback'));
        $this->assertContains('resource_source_shadow', array_column($shadowed->diagnostics, 'code'));
        $this->assertCount(1, array_filter((new CatalogIndex([...$fixed, ...$service]))->relations, fn ($row) => $row['kind'] === 'resource-callback'));
    }

    public function test_cached_view_and_namespaced_helpers_recheck_ordered_source_candidates(): void
    {
        $builder = new ProjectGraphBuilder(catalog: true);
        $facts = $builder->build([new FileContext('app/Process.php', <<<'PHP'
<?php namespace App;
function entry() {
    view('billing.invoice');
    \Illuminate\Support\Facades\View::make('billing.facade');
    cache('billing.cached'); config('billing.config');
    \cache('billing.global-cache'); \config('billing.global-config');
}
PHP)])->catalogFacts;
        $facts = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $facts);
        $shadow = $builder->build([new FileContext('app/Shadow.php', <<<'PHP'
<?php namespace { function view($name) {} }
namespace App { function cache($key) {} function config($key) {} }
namespace Illuminate\Support\Facades { class View {} }
PHP)])->catalogFacts;
        $clean = new CatalogIndex($facts);
        $shadowed = new CatalogIndex([...$facts, ...$shadow]);
        $effects = fn ($index) => array_values(array_filter($index->relations, fn ($edge) => in_array($edge['kind'], ['renders', 'reads'], true)));
        $this->assertCount(6, $effects($clean));
        $this->assertCount(2, $effects($shadowed));
        $this->assertSame(['billing.global-cache', 'billing.global-config'], array_map(fn ($edge) => $shadowed->elements[$edge['to']]['name'], $effects($shadowed)));
        $this->assertCount(4, array_filter($shadowed->relations, fn ($edge) => in_array($edge['kind'], ['references-renders', 'references-reads'], true)));
        $this->assertContains('resource_source_shadow', array_column($shadowed->diagnostics, 'code'));
        $this->assertCount(6, $effects(new CatalogIndex($facts)));
    }

    public function test_route_through_typed_port_and_adapter_reaches_sanitized_external_endpoint(): void
    {
        $sources = [
            'routes/web.php' => '\Illuminate\Support\Facades\Route::post("invoices", [App\Actions\SendInvoice::class, "run"]);',
            'app/Ports/Billing.php' => 'namespace App\Ports; interface Billing { public function send(): void; }',
            'app/Adapters/Billing.php' => 'namespace App\Adapters; class Billing implements \App\Ports\Billing { public function send(): void { \Illuminate\Support\Facades\Http::post("https://billing.test/invoices?token=credential-secret", ["payload" => "payload-secret"]); } }',
            'app/Actions/SendInvoice.php' => 'namespace App\Actions; class SendInvoice { public function run(\App\Ports\Billing $billing): void { $billing->send(); } }',
        ];
        $files = [];
        foreach ($sources as $path => $source) {
            $files[] = new FileContext($path, '<?php '.$source);
        }
        $facts = (new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts;
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex($facts);
        $route = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'route'))[0];
        $endpoint = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'external-endpoint'))[0];
        $this->assertSame('https://billing.test/invoices', $endpoint['name']);
        $this->assertSame('found', (new GraphQuery($index))->query($route['id'], 'path', $endpoint['id'], 12)['status']);
        $this->assertContains('port', $index->elements[$index->namedTypes('App\\Ports\\Billing')[0]]['roles']);
        $this->assertContains('adapter', $index->elements[$index->namedTypes('App\\Adapters\\Billing')[0]]['roles']);
        $calls = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls' && $edge['to'] === $index->names[strtolower('App\\Adapters\\Billing::send')][0]));
        $this->assertCount(1, $calls);
        $this->assertSame('conditional', $calls[0]['resolution']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_http_base_url_is_source_resolved_and_response_chains_are_not_requests(): void
    {
        $index = $this->index(<<<'PHP'
namespace App;
use Illuminate\Support\Facades\Http as Client;
class Process { public function run($url) {
 Client::baseUrl('https://billing.test/api/')->withToken('token-secret')->post('invoices?credential=query-secret', ['payload' => 'payload-secret']);
 Client::get('https://first.test')->get('https://not-a-request.test');
 Client::baseUrl('https://obsolete.test')->baseUrl($url)->get('unknown');
 Client::custom()->get('https://unsupported.test');
} }
PHP);
        $endpoints = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'external-endpoint'));
        $this->assertSame(['https://billing.test/api/invoices', 'https://first.test/'], array_column($endpoints, 'name'));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        $this->assertContains('unsupported_resource_chain', array_column($index->diagnostics, 'code'));
        $this->assertContains('dynamic_endpoint', array_column($index->diagnostics, 'code'));
    }
}
