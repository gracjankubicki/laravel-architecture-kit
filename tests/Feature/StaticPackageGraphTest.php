<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\BladeCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

class StaticPackageGraphTest extends TestCase
{
    /** @return list<CatalogFacts> */
    private function facts(string $source, string $path = 'app/Search.php'): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build([new FileContext($path, '<?php '.$source)])->catalogFacts;
    }

    private function package(string $version = '11.7.0'): CatalogFacts
    {
        return (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', json_encode(['packages' => [['name' => 'laravel/scout', 'version' => $version]]], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
    }

    public function test_scout_preparation_terminals_and_index_writes_are_distinct_source_candidates(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Scout\Searchable as Indexed;
class Product extends \Illuminate\Database\Eloquent\Model { use Indexed; }
function consumer(Product $product) {
    Product::search('search-secret-sentinel');
    Product::search('another-secret')->where('category', 'private-value')->get();
    $builder = Product::search('aliased-secret');
    $builder->paginate();
    Product::search()->raw();
    Product::search()->cursor();
    $product->searchable();
    $product->searchableSync();
    $product->unsearchable();
    $product->unsearchableSync();
    Product::removeAllFromSearch();
}
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        foreach (['search-secret-sentinel', 'private-value', 'aliased-secret', 'source-only-sentinel'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertCount(5, $this->edges($index, 'prepares-scout-search'));
        $this->assertCount(4, $this->edges($index, 'executes-scout-search'));
        $this->assertCount(2, $this->edges($index, 'updates-scout-index'));
        $this->assertCount(3, $this->edges($index, 'removes-scout-index'));
        $this->assertCount(1, $this->edges($index, 'declares-scout-index'));
        $this->assertCount(1, $this->edges($index, 'declares-scout-engine'));
        $this->assertContains('searchable-model', $index->elements[$index->namedTypes('App\\Product')[0]]['roles']);
        foreach ($this->edges($index, 'updates-scout-index') as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['index_selection_required']);
            $this->assertSame($edge['metadata']['method'] === 'searchable', $edge['metadata']['queue_delivery_required_if_configured']);
        }
        foreach ([[], [$this->package('99.0.0')]] as $packages) {
            $index = new CatalogIndex([...$cached, ...$packages]);
            $this->assertSame([], $this->edges($index, 'executes-scout-search'));
            $this->assertContains('package_scout_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_scout_source_override_trait_shadow_and_lookalike_do_not_fake_searches(): void
    {
        $consumer = $this->facts(<<<'SOURCE'
namespace App;
class Fake { public static function search() {} public function searchable() {} }
function consumer(Product $product, Fake $fake) {
    Product::search()->get();
    $product->searchable();
    Fake::search()->get();
    $fake->searchable();
    $dynamic->search()->get();
    Product::search()->unknownFluent()->get();
    Product::get();
}
SOURCE);
        $model = $this->facts('namespace App; class Product extends \\Illuminate\\Database\\Eloquent\\Model { use \\Laravel\\Scout\\Searchable; }', 'app/Product.php');
        $index = new CatalogIndex([...$consumer, ...$model, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'executes-scout-search'));
        $this->assertCount(1, $this->edges($index, 'updates-scout-index'));
        $override = $this->facts('namespace App; class Product extends \\Illuminate\\Database\\Eloquent\\Model { use \\Laravel\\Scout\\Searchable; public static function search() {} public function searchable() {} }', 'app/Product.php');
        $index = new CatalogIndex([...$consumer, ...$override, $this->package()]);
        $this->assertSame([], $this->edges($index, 'executes-scout-search'));
        $this->assertSame([], $this->edges($index, 'updates-scout-index'));
        $shadow = $this->facts('namespace Laravel\\Scout; trait Searchable {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$consumer, ...$model, ...$shadow, $this->package()]);
        $this->assertSame([], $this->edges($index, 'executes-scout-search'));
    }

    public function test_scout_callbacks_belong_to_the_exact_terminal_chain_and_do_not_leak_between_searches(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Product extends \Illuminate\Database\Eloquent\Model { use \Laravel\Scout\Searchable; }
function consumer() {
    Product::search('first', function () { Service::first(); })->get();
    Product::search('second')->query(function () { Service::second(); })->get();
    Product::search('third')->withRawResults(function () { Service::third(); })->raw();
    Product::search('fourth', function () { Service::unused(); });
    Product::search('fifth')->get();
    Product::search(...$args)->get();
    Product::search()->get('invalid');
}
class Service { public static function first() {} public static function second() {} public static function third() {} public static function unused() {} }
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $callbacks = $this->edges($index, 'scout-search-callback');
        $this->assertCount(3, $callbacks);
        $this->assertCount(4, $this->edges($index, 'executes-scout-search'));
        $this->assertSame(['search', 'query', 'withrawresults'], array_column(array_column($callbacks, 'metadata'), 'hook'));
        $this->assertCount(3, array_unique(array_column(array_column($callbacks, 'metadata'), 'root_offset')));
        foreach ($callbacks as $edge) {
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_engine_uses_callback_required']);
        }
        $this->assertContains('package_scout_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_scout_callers_recompose_inherited_index_names_and_source_hooks(): void
    {
        $consumer = $this->facts('namespace App; function consumer(Product $product) { Product::search()->get(); $product->searchable(); }');
        $consumer = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $consumer);
        $source = 'namespace App; class Base extends \\Illuminate\\Database\\Eloquent\\Model { use \\Laravel\\Scout\\Searchable; public function searchableAs() { return "products_search"; } public function indexableAs() { return "products_write"; } public function searchableUsing() { return app(Engine::class); } } class Product extends Base {}';
        $model = $this->facts($source, 'app/Product.php');
        $index = new CatalogIndex([...$consumer, ...$model, $this->package()]);
        $resource = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'scout-index' && $element['metadata']['model'] === 'App\\Product'))[0];
        $this->assertSame('products_search', $resource['metadata']['search_index_name']);
        $this->assertSame('products_write', $resource['metadata']['write_index_name']);
        $this->assertFalse($resource['metadata']['runtime_selection_known']);
        $this->assertCount(4, $this->edges($index, 'scout-source-hook'));
        $changed = $this->facts(str_replace('return "products_search";', 'return config("scout.prefix").$this->getTable();', $source), 'app/Product.php');
        $index = new CatalogIndex([...$consumer, ...$changed, $this->package()]);
        $resource = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'scout-index' && $element['metadata']['model'] === 'App\\Product'))[0];
        $this->assertNull($resource['metadata']['search_index_name']);
        $this->assertSame('products_write', $resource['metadata']['write_index_name']);
        $withoutTrait = $this->facts(str_replace('use \\Laravel\\Scout\\Searchable;', '', $source), 'app/Product.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$consumer, ...$withoutTrait, $this->package()]), 'executes-scout-search'));
    }

    public function test_scout_aliases_share_callback_state_rebinding_and_clearing_preserve_builder_identity(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Product extends \Illuminate\Database\Eloquent\Model { use \Laravel\Scout\Searchable; }
function consumer() {
    $first = Product::search('one');
    $second = Product::search('two');
    $alias = $first;
    $alias->query(function () { Service::one(); });
    $first = Product::search('replacement');
    $second->query(function () { Service::unused(); });
    $second->query(null);
    $second->get();
    $alias->get();
    $first->get();
}
class Service { public static function one() {} public static function unused() {} }
SOURCE);
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $this->package()]);
        $callbacks = $this->edges($index, 'scout-search-callback');
        $this->assertCount(1, $callbacks);
        $this->assertSame('query', $callbacks[0]['metadata']['hook']);
        $this->assertSame(12, $callbacks[0]['line']);
        $this->assertCount(3, $this->edges($index, 'executes-scout-search'));
        $this->assertContains('package_scout_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_scout_alias_escape_branch_reference_and_dynamic_callback_do_not_reuse_stale_callbacks(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Product extends \Illuminate\Database\Eloquent\Model { use \Laravel\Scout\Searchable; }
function escaped() {
    $builder = Product::search('x', function () {});
    unknown($builder);
    $builder->get();
}
function branched() {
    $builder = Product::search('x', function () {});
    if (condition()) { $builder->query($dynamic); }
    $builder->get();
}
function referenced() {
    $builder = Product::search('x', function () {});
    $alias =& $builder;
    $builder->get();
}
function dynamic() {
    $builder = Product::search('x');
    $builder->query($dynamic);
    $builder->get();
}
function invalid() {
    $builder = Product::search(...$arguments);
    $builder->get();
}
function methodEscaped($service) {
    $builder = Product::search('x', function () {});
    $service->mutate($builder);
    $builder->get();
}
function propertyMutated() {
    $builder = Product::search('x', function () {});
    $builder->callback = $other;
    $builder->get();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($index, 'scout-search-callback'));
        $this->assertContains('package_scout_analysis', array_column($index->diagnostics, 'code'));
        foreach ($this->edges($index, 'executes-scout-search') as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertStringNotContainsString('invalid', $index->elements[$edge['from']]['name']);
        }
    }

    public function test_scout_within_uses_the_last_literal_override_and_keeps_dynamic_selection_explicit(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Product extends \Illuminate\Database\Eloquent\Model { use \Laravel\Scout\Searchable; }
function consumer() {
    Product::search()->within('old')->within(index: 'products')->get();
    $builder = Product::search()->within('initial');
    $alias = $builder;
    $alias->within('replacement');
    $builder->get();
    Product::search()->within($dynamic)->get();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $reads = $this->edges($index, 'executes-scout-search');
        $this->assertCount(3, $reads);
        $this->assertSame(['products', 'replacement', null], array_column(array_column($reads, 'metadata'), 'index_override'));
        foreach ($reads as $edge) {
            $this->assertTrue($edge['metadata']['index_override_supplied']);
            $this->assertSame($edge['metadata']['index_override'] === null, $edge['metadata']['index_selection_required']);
        }
        $this->assertContains('package_scout_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_scout_alias_budget_is_explicit_and_cache_rejects_unbounded_selector_payloads(): void
    {
        $statements = '';
        for ($i = 0; $i < 130; $i++) {
            $statements .= '$builder'.$i.' = Product::search();';
        }
        $facts = $this->facts('namespace App; class Product extends \\Illuminate\\Database\\Eloquent\\Model { use \\Laravel\\Scout\\Searchable; } function consumer() { '.$statements.' }');
        $this->assertContains('catalog_limit', array_map(fn ($diagnostic) => $diagnostic->code, $facts[0]->diagnostics));
        $this->assertFalse($facts[0]->cacheable());
        $facts = $this->facts('namespace App; function consumer() { Product::search()->within("products")->get(); }');
        $raw = $facts[0]->toArray();
        foreach ($raw['elements'] as &$element) {
            if ($element['kind'] === 'scout-call-site') {
                $element['metadata']['index_override'] = str_repeat('x', 257);
                $element['metadata']['index_override_supplied'] = true;
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($facts[0]->path, $raw);
    }

    public function test_socialite_driver_aliases_redirect_and_user_exchange_are_distinct_without_oauth_payloads(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Socialite\Facades\Socialite as OAuth;
use Laravel\Socialite\Socialite as LegacyOAuth;
function login() { return OAuth::driver('github')->redirect(); }
function callback() { return OAuth::driver('github')->stateless()->user(); }
function token() { return LegacyOAuth::with('google')->userFromToken('oauth-token-secret-sentinel'); }
function refresh() { return OAuth::driver('google')->refreshToken('oauth-refresh-secret-sentinel'); }
function dynamic($driver) { return OAuth::driver($driver)->user(); }
function aliased() { $provider = OAuth::driver('github'); return $provider->user(); }
function typed(\Laravel\Socialite\Contracts\Provider $provider) { return $provider->user(); }
class Custom implements \Laravel\Socialite\Contracts\Provider { public function user() {} public function redirect() {} }
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/socialite","version":"5.31.0"}]}'));
        $raw = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        foreach (['oauth-token-secret-sentinel', 'oauth-refresh-secret-sentinel', 'source-only-sentinel'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $this->assertCount(1, $this->edges($index, 'socialite-redirect'));
        $this->assertCount(5, $this->edges($index, 'socialite-fetch-user'));
        $this->assertContains('oauth-provider', $index->elements[$index->namedTypes('App\\Custom')[0]]['roles']);
        $this->assertCount(1, $this->edges($index, 'socialite-refresh-token'));
        foreach ($this->edges($index, 'socialite-fetch-user') as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['external_exchange_required_if_standard_provider']);
            $this->assertSame('oauth-provider', $index->elements[$edge['to']]['kind']);
        }
        $this->assertContains('package_socialite_analysis', array_column($index->diagnostics, 'code'));
        $this->assertSame([], $this->edges(new CatalogIndex($cached), 'socialite-fetch-user'));
        $shadow = $this->facts('namespace Laravel\\Socialite\\Facades; class Socialite {}', 'app/Shadow.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$cached, ...$shadow, $package]), 'socialite-fetch-user'));
    }

    public function test_socialite_lookalikes_argument_errors_and_version_mismatch_keep_source_analysis_explicit(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Decoy { public static function driver($name) {} public function user() {} }
function consumer(Decoy $decoy) {
    Decoy::driver('fake')->user();
    $decoy->user();
    \Laravel\Socialite\Facades\Socialite::driver(...$args)->user();
    \Laravel\Socialite\Facades\Socialite::driver('google')->user('invalid');
    \Laravel\Socialite\Facades\Socialite::driver('google')->userFromToken();
    \Laravel\Socialite\Facades\Socialite::driver('google')->user();
}
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/socialite","version":"5.31.0"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $this->assertCount(1, $this->edges($index, 'socialite-fetch-user'));
        $this->assertContains('package_socialite_analysis', array_column($index->diagnostics, 'code'));
        $mismatch = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/socialite","version":"99.0.0"}]}'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$facts, $mismatch]), 'socialite-fetch-user'));
    }

    public function test_socialite_callback_route_uses_the_current_source_handler_and_cached_package_selection(): void
    {
        $routes = $this->facts(<<<'SOURCE'
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
Route::get('/oauth/github/callback', function () { return Socialite::driver('github')->user(); });
SOURCE, 'routes/web.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $routes);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/socialite","version":"5.31.0"}]}'));
        $index = new CatalogIndex([...$cached, $package]);
        $users = $this->edges($index, 'socialite-fetch-user');
        $handlers = $this->edges($index, 'route-handler');
        $this->assertCount(1, $users);
        $this->assertCount(1, $handlers);
        $this->assertSame($handlers[0]['to'], $users[0]['from']);
        $this->assertSame('github', $users[0]['metadata']['driver']);
        $changed = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/socialite","version":"99.0.0"}]}'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$cached, $changed]), 'socialite-fetch-user'));
        $this->assertCount(1, $this->edges(new CatalogIndex([...$cached, $changed]), 'route-handler'));
    }

    public function test_cashier_billable_subscription_checkout_and_charges_exclude_payment_payloads(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Cashier\Billable as Billing;
class User extends \Illuminate\Foundation\Auth\User { use Billing; }
function consumer(User $user, \Laravel\Cashier\Subscription $subscription) {
    $user->newSubscription(type: 'default', prices: 'price-secret-sentinel')->trialDays(10)->create(paymentMethod: 'payment-secret-sentinel');
    $user->newSubscription('default', ['price-secret-sentinel'])->checkout();
    $builder = $user->newSubscription('default', 'price-secret-sentinel');
    $builder->checkout();
    $user->checkout(['private-items-secret-sentinel' => 1]);
    $user->charge(100, 'payment-secret-sentinel');
    $user->refund('intent-secret-sentinel');
    $user->subscription()->cancel();
    $subscription->resume();
    $user->subscribed();
    $user->onTrial();
}
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/cashier","version":"16.8.0"}]}'));
        $raw = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        foreach (['price-secret-sentinel', 'payment-secret-sentinel', 'private-items-secret-sentinel', 'intent-secret-sentinel', 'source-only-sentinel'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $package]);
        $this->assertContains('billable-model', $index->elements[$index->namedTypes('App\\User')[0]]['roles']);
        $this->assertCount(3, $this->edges($index, 'prepares-cashier-subscription'));
        $this->assertCount(3, $this->edges($index, 'reads-cashier-subscription'));
        $operations = $this->edges($index, 'cashier-stripe-operation');
        $this->assertCount(8, $operations);
        foreach ($operations as $edge) {
            $this->assertSame('stripe', $index->elements[$edge['to']]['name']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['checkout_completion_proven']);
            $this->assertTrue($edge['metadata']['external_service_required']);
        }
        $withoutPackage = new CatalogIndex($facts);
        $this->assertSame([], $this->edges($withoutPackage, 'cashier-stripe-operation'));
        $this->assertContains('package_cashier_analysis', array_column($withoutPackage->diagnostics, 'code'));
    }

    public function test_cashier_source_overrides_decoys_static_calls_and_invalid_args_do_not_fake_stripe_calls(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { use \Laravel\Cashier\Billable; }
class Override extends User { public function checkout($items) {} public function newSubscription($type, $prices = []) { return new Decoy; } }
class Decoy { public function checkout($items = []) {} public function charge($amount, $paymentMethod) {} }
function consumer(User $user, Override $override, Decoy $decoy) {
    $user->checkout();
    $user->charge(100);
    $user->newSubscription()->create();
    User::charge(100, 'pm');
    $override->checkout(['a']);
    $override->newSubscription('default')->create();
    $decoy->checkout();
    $decoy->charge(100, 'pm');
    $user->checkout(['valid']);
}
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/cashier","version":"16.8.0"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $this->assertCount(1, $this->edges($index, 'cashier-stripe-operation'));
        $this->assertContains('package_cashier_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Laravel\\Cashier; trait Billable {}', 'app/Shadow.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$facts, ...$shadow, $package]), 'cashier-stripe-operation'));
        $mismatch = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/cashier","version":"99.0.0"}]}'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$facts, $mismatch]), 'cashier-stripe-operation'));
    }

    public function test_cashier_webhooks_require_actual_routes_and_do_not_infer_completion_from_received_event(): void
    {
        $routes = $this->facts(<<<'SOURCE'
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
Event::listen(\Laravel\Cashier\Events\WebhookReceived::class, [\App\Listener::class, 'handle']);
Route::post('/stripe/webhook', [\Laravel\Cashier\Http\Controllers\WebhookController::class, 'handleWebhook']);
Route::post('/custom/webhook', [\App\Webhook::class, 'handleWebhook']);
Route::post('/decoy/webhook', [\App\Decoy::class, 'handleWebhook']);
SOURCE, 'routes/web.php');
        $controllers = $this->facts('namespace App; class Webhook extends \\Laravel\\Cashier\\Http\\Controllers\\WebhookController { protected function handleCustomerSubscriptionUpdated($payload) {} private function handlePrivate($payload) {} } class Decoy { public function handleWebhook($request) {} } class Listener { public function handle($event) {} }', 'app/Webhook.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/cashier","version":"16.8.0"}]}'));
        $index = new CatalogIndex([...$routes, ...$controllers, $package]);
        $this->assertContains('cashier-webhook-controller', $index->elements[$index->namedTypes('App\\Webhook')[0]]['roles']);
        $this->assertCount(2, $this->edges($index, 'cashier-webhook-entry'));
        $events = $this->edges($index, 'cashier-webhook-emits-event');
        $this->assertCount(4, $events);
        $handlers = $this->edges($index, 'cashier-webhook-dispatches-handler');
        $this->assertCount(1, $handlers);
        $this->assertSame('App\\Webhook::handleCustomerSubscriptionUpdated', $index->elements[$handlers[0]['to']]['name']);
        $this->assertTrue($handlers[0]['metadata']['runtime_payload_selects_handler_required']);
        $listeners = $this->edges($index, 'event-registration');
        $this->assertCount(1, $listeners);
        $this->assertSame($listeners[0]['from'], $events[0]['to']);
        foreach ($events as $edge) {
            $this->assertSame(str_ends_with($index->elements[$edge['to']]['name'], 'WebhookHandled'), $edge['metadata']['handler_exists_and_completed_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $changed = $this->facts('namespace App; class Webhook extends \\Laravel\\Cashier\\Http\\Controllers\\WebhookController { public function handleWebhook($request) {} } class Decoy { public function handleWebhook($request) {} }', 'app/Webhook.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $routes);
        $this->assertCount(1, $this->edges(new CatalogIndex([...$cached, ...$changed, $package]), 'cashier-webhook-entry'));
    }

    public function test_cashier_cached_callers_follow_billable_trait_changes_and_reject_source_builder_shadows(): void
    {
        $callers = $this->facts('namespace App; function consumer(User $user) { $user->newSubscription("default", "price")->checkout(); }');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $callers);
        $models = $this->facts('namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { use \\Laravel\\Cashier\\Billable; }', 'app/User.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/cashier","version":"16.8.0"}]}'));
        $this->assertCount(1, $this->edges(new CatalogIndex([...$cached, ...$models, $package]), 'cashier-stripe-operation'));
        $changed = $this->facts('namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model {}', 'app/User.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$cached, ...$changed, $package]), 'cashier-stripe-operation'));
        $shadow = $this->facts('namespace Laravel\\Cashier; class SubscriptionBuilder { public function checkout() {} }', 'app/Shadow.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$cached, ...$models, ...$shadow, $package]), 'cashier-stripe-operation'));
    }

    public function test_horizon_inherits_defaults_using_recursive_array_replacement_without_job_links(): void
    {
        $facts = $this->facts(<<<'SOURCE'
throw new \RuntimeException('source-only-sentinel');
return [
    'prefix' => 'redis-secret-sentinel',
    'defaults' => ['worker' => ['connection' => 'redis', 'queue' => ['default', 'emails'], 'maxProcesses' => 1, 'tries' => 2, 'timeout' => 60]],
    'environments' => ['production' => ['worker' => ['queue' => ['critical'], 'maxProcesses' => 5]], 'local' => []],
];
SOURCE, 'config/horizon.php');
        $raw = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('redis-secret-sentinel', $raw);
        $this->assertStringNotContainsString('source-only-sentinel', $raw);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/horizon","version":"5.49.0"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $supervisors = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'horizon-supervisor'));
        $this->assertCount(2, $supervisors);
        $this->assertSame(['critical', 'emails'], $supervisors[0]['metadata']['options']['queue']);
        $this->assertSame(5, $supervisors[0]['metadata']['options']['maxProcesses']);
        $this->assertSame(2, $supervisors[0]['metadata']['options']['tries']);
        $this->assertSame(['default', 'emails'], $supervisors[1]['metadata']['options']['queue']);
        $queues = $this->edges($index, 'horizon-supervises-queue');
        $this->assertCount(4, $queues);
        foreach ($queues as $edge) {
            $this->assertSame('horizon-supervisor', $index->elements[$edge['from']]['kind']);
            $this->assertSame('queue', $index->elements[$edge['to']]['kind']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_horizon_provisioning_required']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex($cached), 'horizon-supervises-queue'));
    }

    public function test_horizon_dynamic_config_lookalike_paths_version_and_source_profile_shadows_remain_explicit(): void
    {
        $source = 'return ["defaults" => ["worker" => ["connection" => "redis", "queue" => ["default"]]], "environments" => ["*" => ["worker" => ["queue" => env("PRIVATE_QUEUE", "secret-default")]]]];';
        $facts = $this->facts($source, 'config/horizon.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/horizon","version":"5.49.0"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $this->assertSame([], $this->edges($index, 'horizon-supervises-queue'));
        $this->assertContains('horizon_config_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('secret-default', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $decoy = $this->facts(str_replace('env("PRIVATE_QUEUE", "secret-default")', '["critical"]', $source), 'app/Horizon.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$decoy, $package]), 'horizon-supervises-queue'));
        $valid = $this->facts(str_replace('env("PRIVATE_QUEUE", "secret-default")', '["critical"]', $source), 'config/horizon.php');
        $mismatch = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/horizon","version":"99.0.0"}]}'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$valid, $mismatch]), 'horizon-supervises-queue'));
        $shadow = $this->facts('namespace Laravel\\Horizon; class ProvisioningPlan {}', 'app/Shadow.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$valid, ...$shadow, $package]), 'horizon-supervises-queue'));
    }

    public function test_horizon_config_edit_queue_string_and_unpacking_recompose_without_stale_queue_edges(): void
    {
        $source = 'return ["defaults" => ["worker" => ["connection" => "redis", "queue" => ["default", "emails"], "balance" => false]], "environments" => ["*" => ["worker" => ["queue" => "critical,notifications"]]]];';
        $facts = $this->facts($source, 'config/horizon.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/horizon","version":"5.49.0"}]}'));
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $package]);
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $this->edges($index, 'horizon-supervises-queue'));
        $this->assertSame(['critical', 'notifications'], $names);
        $supervisor = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'horizon-supervisor'))[0];
        $this->assertFalse($supervisor['metadata']['options']['balance']);
        $changed = $this->facts(str_replace('"critical,notifications"', '"other"', $source), 'config/horizon.php');
        $index = new CatalogIndex([...$changed, $package]);
        $this->assertSame(['other'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $this->edges($index, 'horizon-supervises-queue')));
        $unpacked = $this->facts(str_replace('"critical,notifications"', '[...$dynamic]', $source), 'config/horizon.php');
        $index = new CatalogIndex([...$unpacked, $package]);
        $this->assertSame([], $this->edges($index, 'horizon-supervises-queue'));
        $this->assertContains('horizon_config_analysis', array_column($index->diagnostics, 'code'));
        $this->assertSame([], $this->edges(new CatalogIndex([$package]), 'horizon-supervises-queue'));
    }

    public function test_reverb_source_configuration_keeps_addresses_but_omits_credentials(): void
    {
        $facts = [...$this->facts(<<<'SOURCE'
return ['servers' => ['reverb' => ['host' => '0.0.0.0', 'port' => 8080]],
    'apps' => ['provider' => 'config', 'apps' => [['app_id' => 'private-app-id', 'key' => 'private-key', 'secret' => 'private-secret',
        'options' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https']]]]];
SOURCE, 'config/reverb.php'), ...$this->facts(<<<'SOURCE'
return ['default' => 'socket', 'connections' => ['socket' => ['driver' => 'reverb', 'secret' => 'private-secret',
    'options' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https']], 'log' => ['driver' => 'log']]];
SOURCE, 'config/broadcasting.php')];
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        foreach (['private-app-id', 'private-key', 'private-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex($cached);
        $definitions = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'reverb-definition'));
        $this->assertCount(3, $definitions);
        $this->assertEqualsCanonicalizing(['server', 'application', 'connection'], array_column(array_column($definitions, 'metadata'), 'form'));
        $default = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'broadcast-connection-default'));
        $this->assertSame(['selector' => 'socket', 'resolved' => true], $default[0]['metadata']);
    }

    public function test_reverb_dynamic_configuration_and_lookalike_paths_remain_source_only(): void
    {
        $source = <<<'SOURCE'
return ['default' => env('PRIVATE_CONNECTION', 'private-fallback'), 'connections' => [
    'socket' => ['driver' => 'reverb', 'options' => ['host' => env('PRIVATE_HOST'), 'port' => 99999]],
    'dynamic' => ['driver' => $driver]]];
SOURCE;
        $facts = $this->facts($source, 'config/broadcasting.php');
        $index = new CatalogIndex($facts);
        $this->assertContains('reverb_config_analysis', array_column($index->diagnostics, 'code'));
        $definitions = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'reverb-definition'));
        $this->assertCount(1, $definitions);
        $this->assertNull($definitions[0]['metadata']['host']);
        $this->assertNull($definitions[0]['metadata']['port']);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-fallback', $serialized);
        $decoy = new CatalogIndex($this->facts($source, 'app/broadcasting.php'));
        $this->assertSame([], array_values(array_filter($decoy->elements, fn ($element) => $element['kind'] === 'reverb-definition')));
        $log = new CatalogIndex($this->facts("return ['default' => 'log', 'connections' => ['log' => ['driver' => 'log']]];", 'config/broadcasting.php'));
        $this->assertNotContains('reverb_config_analysis', array_column($log->diagnostics, 'code'));
        $this->assertSame([], array_values(array_filter($log->elements, fn ($element) => $element['kind'] === 'reverb-definition')));
    }

    public function test_broadcast_connection_methods_keep_literal_selections_and_reject_required_arguments(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Event { public function broadcastConnections() { return ['socket', null]; } }
class Invalid { public function broadcastConnections($required) { return ['socket']; } }
class Dynamic { public function broadcastConnections() { return $this->connections; } }
class EmptyEvent { public function broadcastConnections() { return []; } }
throw new \RuntimeException('must-never-run');
SOURCE);
        $index = new CatalogIndex(array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts));
        $selectors = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'broadcast-connection-selector'));
        $this->assertCount(4, $selectors);
        $byName = array_column($selectors, 'metadata', 'name');
        $this->assertSame(['connections' => ['socket', null], 'resolved' => true], $byName['Connections for App\\Event::broadcastConnections']);
        $this->assertFalse($byName['Connections for App\\Invalid::broadcastConnections']['resolved']);
        $this->assertFalse($byName['Connections for App\\Dynamic::broadcastConnections']['resolved']);
        $this->assertSame(['connections' => [], 'resolved' => true], $byName['Connections for App\\EmptyEvent::broadcastConnections']);
    }

    public function test_reverb_configuration_resources_require_profile_and_recompose_after_source_edits(): void
    {
        $source = "return ['default' => 'socket', 'connections' => ['socket' => ['driver' => 'reverb', 'options' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https']]]];";
        $facts = $this->facts($source, 'config/broadcasting.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/reverb","version":"1.11.1"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $this->assertCount(1, $this->edges($index, 'declares-broadcast-connection'));
        $this->assertCount(1, $this->edges($index, 'selects-default-broadcast-connection'));
        $defaultEdge = $this->edges($index, 'selects-default-broadcast-connection')[0];
        $query = new GraphQuery($index);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($defaultEdge['from'], 'path', $defaultEdge['to'])['status']);
        $edge = $this->edges($index, 'declares-broadcast-connection')[0];
        $this->assertSame('structural', $edge['resolution']);
        $this->assertFalse($edge['metadata']['execution_proven']);
        $this->assertFalse($index->elements[$edge['to']]['metadata']['runtime_activation_known']);
        $changed = $this->facts(str_replace("'socket'", "'other'", $source), 'config/broadcasting.php');
        $new = new CatalogIndex([...$changed, $package]);
        $this->assertArrayNotHasKey($edge['to'], $new->elements);
        $this->assertCount(1, $this->edges($new, 'selects-default-broadcast-connection'));
        $mismatch = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/reverb","version":"99.0.0"}]}'));
        foreach ([[], [$mismatch], [$package, ...$this->facts('namespace Laravel\\Reverb; class ServerProviderManager {}', 'app/Shadow.php')]] as $context) {
            $invalid = new CatalogIndex([...$cached, ...$context]);
            $this->assertSame([], $this->edges($invalid, 'declares-broadcast-connection'));
            $this->assertContains('package_reverb_analysis', array_column($invalid->diagnostics, 'code'));
        }
    }

    public function test_reverb_delivery_uses_actual_event_dispatch_and_preserves_queue_conditions(): void
    {
        $config = $this->facts("return ['default' => 'socket', 'connections' => ['socket' => ['driver' => 'reverb']]];", 'config/broadcasting.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/reverb","version":"1.11.1"}]}'));
        $events = $this->facts(<<<'SOURCE'
namespace App;
class Queued implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
    public function broadcastOn() { return ['orders']; }
}
class Immediate implements \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow {
    public function broadcastOn() { return ['orders']; }
    public function broadcastConnections() { return ['socket']; }
}
class Unused implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
    public function broadcastOn() { return ['orders']; }
}
function emit() { event(new Queued); event(new Immediate); }
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), [...$config, ...$events]);
        $index = new CatalogIndex([...$cached, $package]);
        $edges = $this->edges($index, 'broadcasts-via-reverb');
        $this->assertCount(2, $edges);
        foreach ($edges as $edge) {
            $event = $index->elements[$edge['metadata']['event_class']]['name'];
            $this->assertContains($event, ['App\\Queued', 'App\\Immediate']);
            $this->assertSame($event === 'App\\Queued', $edge['metadata']['queue_delivery_required']);
            $this->assertSame('socket', $edge['metadata']['connection_selector']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_server_available_required']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertSame('broadcast-connection', $index->elements[$edge['to']]['kind']);
            $query = new GraphQuery($index);
            $this->assertSame('found', $query->query($edge['from'], 'path', $edge['to'])['status']);
        }
        $changed = $this->facts("return ['default' => env('PRIVATE_CONNECTION'), 'connections' => ['socket' => ['driver' => 'reverb']]];", 'config/broadcasting.php');
        $new = new CatalogIndex([...$changed, ...$events, $package]);
        $this->assertCount(1, $this->edges($new, 'broadcasts-via-reverb'));
        $this->assertContains('package_reverb_analysis', array_column($new->diagnostics, 'code'));
        $this->assertSame([], $this->edges(new CatalogIndex($cached), 'broadcasts-via-reverb'));
    }

    public function test_reverb_empty_channels_empty_connections_dynamic_trait_and_decoys_do_not_fake_delivery(): void
    {
        $config = $this->facts("return ['default' => 'socket', 'connections' => ['socket' => ['driver' => 'reverb']]];", 'config/broadcasting.php');
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"laravel/reverb","version":"1.11.1"}]}'));
        $events = $this->facts(<<<'SOURCE'
namespace App;
class EmptyChannels implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
    public function broadcastOn() { return []; }
}
class EmptyConnections implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
    public function broadcastOn() { return ['orders']; }
    public function broadcastConnections() { return []; }
}
class RuntimeConnections implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
    use \Illuminate\Broadcasting\InteractsWithBroadcasting;
    public function broadcastOn() { return ['orders']; }
}
class UnknownBase extends \Vendor\BaseEvent implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
    public function broadcastOn() { return ['orders']; }
}
class Lookalike { public function broadcastOn() { return ['orders']; } public function broadcastConnections() { return ['socket']; } }
function emit() { event(new EmptyChannels); event(new EmptyConnections); event(new RuntimeConnections); event(new UnknownBase); event(new Lookalike); }
SOURCE);
        $index = new CatalogIndex([...$config, ...$events, $package]);
        $this->assertSame([], $this->edges($index, 'broadcasts-via-reverb'));
        $this->assertContains('package_reverb_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_livewire_get_listeners_maps_inherited_source_methods_and_refresh_without_running_the_component(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Livewire\Component as Widget;
abstract class BaseOrders extends Widget {
    protected function getListeners() { return ['saved' => 'missing', 'saved' => 'reload', 'refresh-all' => '$refresh', 'ping']; }
    public function reload() {}
    public function ping() {}
}
class Orders extends BaseOrders {
    public function save() { $this->dispatch('saved')->self(); }
}
class EmptyOrders extends BaseOrders { protected function getListeners() { return []; } }
class Lookalike { protected function getListeners() { return ['fake-event' => 'fake']; } public function fake() {} }
throw new \RuntimeException('listener-source-only-sentinel');
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $listeners = $this->edges($index, 'livewire-event-listener');
        $this->assertCount(3, $listeners);
        $this->assertCount(1, $this->edges($index, 'livewire-refreshes-component'));
        $this->assertCount(3, $this->edges($index, 'registers-livewire-listener'));
        $component = $index->namedTypes('App\\Orders')[0];
        foreach ($listeners as $edge) {
            $this->assertSame($component, $edge['metadata']['listener_component']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_listener_map_evaluation_required']);
        }
        $targeted = array_values(array_filter($listeners, fn ($edge) => ($edge['metadata']['target_mode'] ?? null) === 'self'));
        $this->assertCount(1, $targeted);
        $this->assertSame($component, $targeted[0]['metadata']['target_component']);
        $this->assertStringEndsWith('BaseOrders::reload', $index->elements[$targeted[0]['to']]['name']);
        $this->assertArrayNotHasKey('fake-event', $index->names);
        $this->assertStringNotContainsString('listener-source-only-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->edges(new CatalogIndex($cached), 'livewire-event-listener'));
    }

    public function test_livewire_listener_attributes_override_literal_maps_and_dynamic_or_inaccessible_selectors_are_explicit(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Orders extends \Livewire\Component {
    protected function getListeners() { return ['saved' => 'first', 'saved' => 'second', 'hidden' => 'hidden', 'static-event' => 'staticMethod', 'computed' => 'total', 'paint' => 'render']; }
    public function first() {}
    public function second() {}
    #[\Livewire\Attributes\On('saved')]
    public function attributeListener() {}
    protected function hidden() {}
    public static function staticMethod() {}
    #[\Livewire\Attributes\Computed]
    public function total() {}
    public function render() {}
}
class Dynamic extends \Livewire\Component { protected function getListeners() { return ['saved' => 'reload', 'orders.{id}' => 'reload']; } public function reload() {} }
class Conditional extends \Livewire\Component { protected function getListeners() { if (true) { return ['conditional' => 'reload']; } return []; } public function reload() {} }
class RequiredArgument extends \Livewire\Component { protected function getListeners($required) { return ['required-argument' => 'reload']; } public function reload() {} }
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $listeners = $this->edges($index, 'livewire-event-listener');
        $this->assertCount(1, $listeners);
        $this->assertSame('App\\Orders::attributeListener', $index->elements[$listeners[0]['to']]['name']);
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $this->assertContains('livewire_listener_analysis', array_column($index->diagnostics, 'code'));
        $this->assertArrayNotHasKey('orders.{id}', $index->names);
        $this->assertArrayNotHasKey('conditional', $index->names);
        $this->assertArrayNotHasKey('required-argument', $index->names);
    }

    public function test_livewire_listener_properties_follow_source_inheritance_traits_and_getter_overrides(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Livewire\Component as Widget;
abstract class BaseOrders extends Widget {
    protected $listeners = ['saved' => 'reload', 'refresh-all' => '$refresh'];
    public function reload() {}
}
class InheritedOrders extends BaseOrders {}
class ChangedOrders extends BaseOrders { protected $listeners = ['changed' => 'changed']; public function changed() {} }
class EmptyOrders extends BaseOrders { protected $listeners = []; }
class DelegatedOrders extends Widget {
    protected $listeners = ['delegated' => 'reload'];
    protected function getListeners() { return $this->listeners; }
    public function reload() {}
}
trait ListenerMap { protected $listeners = ['trait-event' => 'reload']; }
class TraitOrders extends Widget { use ListenerMap; public function reload() {} }
class LiteralOverridesProperty extends Widget {
    protected $listeners = ['unused' => 'reload'];
    protected function getListeners() { return ['literal' => 'reload']; }
    public function reload() {}
}
class Fake { protected $listeners = ['fake' => 'reload']; public function reload() {} }
throw new \RuntimeException('property-source-only-sentinel');
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $listeners = $this->edges($index, 'livewire-event-listener');
        $this->assertCount(5, $listeners);
        $this->assertCount(1, $this->edges($index, 'livewire-refreshes-component'));
        $this->assertCount(6, $this->edges($index, 'registers-livewire-listener'));
        $this->assertEqualsCanonicalizing(['App\\BaseOrders::reload', 'App\\ChangedOrders::changed', 'App\\DelegatedOrders::reload', 'App\\TraitOrders::reload', 'App\\LiteralOverridesProperty::reload'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $listeners));
        foreach ($listeners as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_listener_map_evaluation_required']);
        }
        $this->assertArrayNotHasKey('unused', $index->names);
        $this->assertArrayNotHasKey('fake', $index->names);
        $this->assertStringNotContainsString('property-source-only-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->edges(new CatalogIndex($cached), 'livewire-event-listener'));
    }

    public function test_livewire_listener_property_uncertainty_and_entry_budget_do_not_create_delivery(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Dynamic extends \Livewire\Component { protected $listeners = ['orders.{id}' => 'reload']; public function reload() {} }
class PrivateMap extends \Livewire\Component { private $listeners = ['private' => 'reload']; public function reload() {} }
class StaticMap extends \Livewire\Component { protected static $listeners = ['static' => 'reload']; public function reload() {} }
class UnknownTrait extends \Livewire\Component { use \Vendor\UnknownListeners; protected $listeners = ['unknown' => 'reload']; public function reload() {} }
class GetterOverride extends \Livewire\Component { protected $listeners = ['ignored' => 'reload']; protected function getListeners() { return config('private-selector-sentinel'); } public function reload() {} }
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $this->assertSame([], $this->edges($index, 'livewire-event-listener'));
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $entries = implode(',', array_map(fn ($number) => "'event-".$number."' => 'reload'", range(1, 129)));
        foreach (['protected $listeners = ['.$entries.'];', 'protected function getListeners() { return ['.$entries.']; }'] as $declaration) {
            $limited = $this->facts('class Limited extends \\Livewire\\Component { '.$declaration.' public function reload() {} }');
            $index = new CatalogIndex([...$limited, $package]);
            $this->assertSame([], $this->edges($index, 'livewire-event-listener'));
            $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
            $this->assertFalse($limited[0]->cacheable());
        }
    }

    public function test_livewire_inherited_attributes_respect_method_property_overrides_and_public_trait_aliases(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
#[On('refresh-all')]
abstract class BaseOrders extends \Livewire\Component {
    #[On('saved')]
    public function reload() {}
    #[Computed]
    public function total() {}
    #[Validate('private-rule-sentinel')]
    public string $name;
}
class InheritedOrders extends BaseOrders {
    protected $listeners = ['saved' => 'other'];
    public function other() {}
    public function save() { $this->dispatch('saved')->self(); }
}
class ChangedOrders extends BaseOrders {
    public function reload() {}
    public function total() {}
    public string $name;
}
#[On('trait-root-decoy')]
trait Reloads {
    #[On('trait-event')]
    protected function receive() {}
    #[Validate('private-rule-sentinel')]
    public string $first, $second;
}
class TraitOrders extends \Livewire\Component {
    use Reloads { receive as public reload; }
    public function send() { $this->dispatch('trait-event')->self(); }
}
#[On('same-event')]
class RootAndMethod extends \Livewire\Component {
    #[On('same-event')]
    public function reload() {}
}
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $listeners = $this->edges($index, 'livewire-event-listener');
        $this->assertCount(5, $listeners);
        $this->assertCount(2, $this->edges($index, 'livewire-refreshes-component'));
        $this->assertArrayNotHasKey('trait-root-decoy', $index->names);
        $inherited = $index->namedTypes('App\\InheritedOrders')[0];
        $changed = $index->namedTypes('App\\ChangedOrders')[0];
        $trait = $index->namedTypes('App\\TraitOrders')[0];
        foreach ($listeners as $edge) {
            $this->assertNotSame($changed, $edge['metadata']['listener_component']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $alias = array_values(array_filter($listeners, fn ($edge) => ($edge['metadata']['listener_component'] ?? null) === $trait));
        $this->assertCount(2, $alias);
        foreach ($alias as $edge) {
            $this->assertSame('reload', $edge['metadata']['listener_member']);
            $this->assertSame('App\\Reloads::receive', $index->elements[$edge['to']]['name']);
        }
        $inheritedListeners = array_values(array_filter($listeners, fn ($edge) => $edge['metadata']['listener_component'] === $inherited));
        $this->assertCount(2, $inheritedListeners);
        $this->assertSame('App\\BaseOrders::reload', $index->elements[$inheritedListeners[0]['to']]['name']);
        $validations = $this->edges($index, 'declares-livewire-validate');
        $this->assertCount(4, $validations);
        $this->assertEqualsCanonicalizing(['App\\BaseOrders::$name', 'App\\BaseOrders::$name', 'App\\Reloads::$first', 'App\\Reloads::$second'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $validations));
        $computed = $this->edges($index, 'declares-livewire-computed');
        $this->assertCount(2, $computed);
        $this->assertNotContains($changed, array_column(array_column($computed, 'metadata'), 'listener_component'));
        $this->assertStringNotContainsString('private-rule-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
    }

    public function test_livewire_duplicate_attribute_listeners_report_runtime_order_uncertainty(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Orders extends \Livewire\Component {
    #[\Livewire\Attributes\On('saved')]
    public function first() {}
    #[\Livewire\Attributes\On('saved')]
    public function second() {}
}
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $listeners = $this->edges($index, 'livewire-event-listener');
        $this->assertCount(2, $listeners);
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        foreach ($listeners as $edge) {
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertTrue($edge['metadata']['runtime_attribute_order_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_livewire_source_components_forms_attributes_and_event_listeners_are_distinct(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Livewire\Component as Widget;
use Livewire\Attributes\On as Listens;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
class OrderForm extends \Livewire\Form { #[Validate('private-rule-sentinel', message: 'private-message')] public string $name; }
#[Listens('refresh-orders')]
class Orders extends Widget {
    #[Validate('private-rule-sentinel')] public string $search;
    public function mount() {}
    public function save() {}
    public function render() { return view('livewire.orders'); }
    #[Computed] public function total() { return 1; }
    #[Listens(event: ['order-saved', 'order-deleted'])] public function reload() {}
    protected function privateAction() {}
}
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        foreach (['private-rule-sentinel', 'private-message', 'source-only-sentinel'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $package]);
        $this->assertContains('livewire-component', $index->elements[$index->namedTypes('App\\Orders')[0]]['roles']);
        $this->assertContains('livewire-form', $index->elements[$index->namedTypes('App\\OrderForm')[0]]['roles']);
        $byName = array_column(array_values($index->elements), null, 'name');
        $this->assertContains('livewire-lifecycle', $byName['App\\Orders::mount']['roles']);
        $this->assertContains('livewire-render', $byName['App\\Orders::render']['roles']);
        $this->assertContains('livewire-action', $byName['App\\Orders::save']['roles']);
        $this->assertContains('livewire-computed', $byName['App\\Orders::total']['roles']);
        $this->assertNotContains('livewire-action', $byName['App\\Orders::total']['roles']);
        $this->assertNotContains('livewire-action', $byName['App\\Orders::privateAction']['roles']);
        $this->assertCount(2, $this->edges($index, 'livewire-event-listener'));
        $this->assertCount(1, $this->edges($index, 'livewire-refreshes-component'));
        $this->assertCount(2, $this->edges($index, 'declares-livewire-validate'));
        $this->assertCount(1, $this->edges($index, 'declares-livewire-computed'));
        foreach ($this->edges($index, 'livewire-event-listener') as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['mounted_component_required']);
            $this->assertTrue($edge['metadata']['browser_event_delivery_required']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertSame('App\\Orders::reload', $index->elements[$edge['to']]['name']);
        }
    }

    public function test_livewire_profile_shadows_lookalikes_and_dynamic_listener_names_stay_explicit(): void
    {
        $source = <<<'SOURCE'
namespace App;
class Orders extends \Livewire\Component {
    #[\Livewire\Attributes\On('order.{order.id}')] public function reload() {}
    #[\Livewire\Attributes\On('saved')] protected function hidden() {}
}
class Lookalike { #[\Livewire\Attributes\On('saved')] public function reload() {} }
SOURCE;
        $facts = $this->facts($source);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...$facts, $package]);
        $this->assertSame([], $this->edges($index, 'livewire-event-listener'));
        $this->assertContains('livewire_attribute_analysis', array_column($index->diagnostics, 'code'));
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $valid = $this->facts(str_replace('order.{order.id}', 'order-saved', $source));
        $mismatch = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"99.0.0"}]}'));
        foreach ([[], [$mismatch], [$package, ...$this->facts('namespace Livewire; class Component {}', 'app/Shadow.php')],
            [$package, ...$this->facts('namespace Livewire\\Attributes; class On {}', 'app/Shadow.php')]] as $context) {
            $invalid = new CatalogIndex([...$valid, ...$context]);
            $this->assertSame([], $this->edges($invalid, 'livewire-event-listener'));
            $this->assertContains('package_livewire_analysis', array_column($invalid->diagnostics, 'code'));
        }
        $this->assertCount(1, $this->edges(new CatalogIndex([...$valid, $package]), 'livewire-event-listener'));
    }

    public function test_livewire_dispatch_to_listener_is_a_browser_delivery_candidate_and_cache_recomposes(): void
    {
        $source = <<<'SOURCE'
namespace App;
class Sender extends \Livewire\Component {
    public function save() { $this->dispatch(event: 'order-saved', privatePayload: 'private-payload-sentinel'); }
    public function unknown($event) { $this->dispatch($event); }
    public function closures() { $callback = static fn () => $this->dispatch('static-decoy'); }
}
class Receiver extends \Livewire\Component {
    #[\Livewire\Attributes\On('order-saved')] public function reload() {}
}
class Lookalike { public function save() { $this->dispatch('decoy'); } }
SOURCE;
        $facts = $this->facts($source);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-payload-sentinel', $serialized);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $package]);
        $dispatch = $this->edges($index, 'dispatches-livewire-event');
        $this->assertCount(1, $dispatch);
        $byName = array_column(array_values($index->elements), null, 'name');
        $path = (new GraphQuery($index))->query($byName['App\\Sender::save']['id'], 'path', $byName['App\\Receiver::reload']['id']);
        $this->assertSame('found', $path['status']);
        $this->assertSame(['dispatches-livewire-event', 'livewire-event-listener'], array_column($path['records'][0]['relations'], 'kind'));
        foreach ($path['records'][0]['relations'] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['browser_event_delivery_required']);
            $this->assertTrue($edge['metadata']['runtime_event_target_required']);
        }
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $changed = $this->facts(str_replace("On('order-saved')", "On('other-event')", $source));
        $new = new CatalogIndex([...$changed, $package]);
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($new))->query($byName['App\\Sender::save']['id'], 'path', $byName['App\\Receiver::reload']['id'])['status']);
        $override = $this->facts(str_replace('public function unknown', 'public function dispatch($event, ...$params) {} public function unknown', $source));
        $this->assertSame([], $this->edges(new CatalogIndex([...$override, $package]), 'dispatches-livewire-event'));
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'dispatches-livewire-event'));
    }

    public function test_livewire_component_registration_is_structural_and_requires_real_component_contract(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Livewire\Livewire as Components;
class Orders extends \Livewire\Component {}
class Fake {}
Components::component(class: Orders::class, name: 'orders-grid');
Components::component('fake', Fake::class);
Components::component($dynamic, Orders::class);
SOURCE);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $package]);
        $registrations = $this->edges($index, 'registers-livewire-component');
        $this->assertCount(1, $registrations);
        $this->assertSame('orders-grid', $index->elements[$registrations[0]['to']]['name']);
        $this->assertSame('structural', $registrations[0]['resolution']);
        $this->assertFalse($registrations[0]['metadata']['execution_proven']);
        $classes = $this->edges($index, 'references-livewire-component-class');
        $this->assertCount(1, $classes);
        $this->assertSame('App\\Orders', $index->elements[$classes[0]['to']]['name']);
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($registrations[0]['from'], 'path', $classes[0]['to'])['status']);
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Livewire; class LivewireManager {}', 'app/Shadow.php');
        $this->assertSame([], $this->edges(new CatalogIndex([...$facts, ...$shadow, $package]), 'registers-livewire-component'));
    }

    public function test_livewire_self_and_component_targeting_do_not_leak_to_unrelated_listeners(): void
    {
        $source = <<<'SOURCE'
namespace App;
class Sender extends \Livewire\Component {
    public function selfOnly() { $this->dispatch('saved')->self(); }
    public function byAlias() { $this->dispatch('saved')->to(component: 'orders-grid'); }
    public function byClass() { $this->dispatch('saved')->to(Receiver::class); }
    public function namedSelf() { $this->dispatch('saved', self: false); }
    public function dynamic($target) { $this->dispatch('saved')->to($target); }
    public function byElement() { $this->dispatch('saved')->el('private-dom-sentinel'); }
    #[\Livewire\Attributes\On('saved')] public function receive() {}
}
class Receiver extends \Livewire\Component { #[\Livewire\Attributes\On('saved')] public function receive() {} }
class Other extends \Livewire\Component { #[\Livewire\Attributes\On('saved')] public function receive() {} }
\Livewire\Livewire::component('orders-grid', Receiver::class);
SOURCE;
        $facts = $this->facts($source);
        $this->assertStringNotContainsString('private-dom-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $package]);
        $byName = array_column(array_values($index->elements), null, 'name');
        $query = new GraphQuery($index);
        foreach (['selfOnly' => 'Sender', 'namedSelf' => 'Sender', 'byAlias' => 'Receiver', 'byClass' => 'Receiver'] as $method => $receiver) {
            foreach (['Sender', 'Receiver', 'Other'] as $candidate) {
                $path = $query->query($byName['App\\Sender::'.$method]['id'], 'path', $byName['App\\'.$candidate.'::receive']['id']);
                $this->assertSame($candidate === $receiver ? 'found' : 'no_path_in_analyzed_graph', $path['status']);
            }
        }
        foreach (['dynamic', 'byElement'] as $method) {
            $this->assertSame('no_path_in_analyzed_graph', $query->query($byName['App\\Sender::'.$method]['id'], 'path', $byName['App\\Other::receive']['id'])['status']);
        }
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $conflicting = $this->facts($source." \Livewire\Livewire::component('orders-grid', Other::class);");
        $new = new CatalogIndex([...$conflicting, $package]);
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($new))->query($byName['App\\Sender::byAlias']['id'], 'path', $byName['App\\Receiver::receive']['id'])['status']);
    }

    public function test_livewire_view_actions_are_conditional_and_recompose_after_visibility_or_selector_changes(): void
    {
        $source = <<<'SOURCE'
<?php
new class extends \Livewire\Component {
    public function save() {}
    protected function hidden() {}
    #[\Livewire\Attributes\Computed]
    public function total() {}
};
?>
<button wire:click="save('private-argument-sentinel')"></button>
<button wire:click="hidden"></button>
<button wire:click="total"></button>
<button wire:click="render"></button>
<button wire:click="$parent.save()"></button>
SOURCE;
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $build = function (string $contents) {
            $snapshot = (new ProjectGraphBuilder(catalog: true))->build([new FileContext('resources/views/livewire/orders.blade.php', $contents)]);

            return array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $snapshot->catalogFacts);
        };
        $facts = $build($source);
        $index = new CatalogIndex([...$facts, $package]);
        $edges = $this->edges($index, 'livewire-view-action');
        $this->assertCount(1, $edges);
        $this->assertStringEndsWith('::save', $index->elements[$edges[0]['to']]['name']);
        $this->assertSame(9, $edges[0]['line']);
        $this->assertFalse($edges[0]['metadata']['execution_proven']);
        $this->assertTrue($edges[0]['metadata']['nearest_component_scope_required']);
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('private-argument-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'livewire-view-action'));
        foreach ([str_replace('public function save', 'protected function save', $source), str_replace('save(\'private-argument-sentinel\')', 'missing()', $source)] as $changed) {
            $this->assertSame([], $this->edges(new CatalogIndex([...$build($changed), $package]), 'livewire-view-action'));
        }
    }

    public function test_livewire_shared_render_view_keeps_separate_component_candidates(): void
    {
        $source = <<<'SOURCE'
<?php
namespace App;
class Orders extends \Livewire\Component {
    public function render() { return view('orders'); }
    public function save() {}
}
class OtherOrders extends \Livewire\Component {
    public function render() { return view('orders'); }
    public function save() {}
}
class Lookalike {
    public function render() { return view('orders'); }
    public function save() {}
}
SOURCE;
        $snapshot = (new ProjectGraphBuilder(catalog: true))->build([new FileContext('app/Orders.php', $source), new FileContext('resources/views/orders.blade.php', '<button wire:click="save"></button>')]);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $index = new CatalogIndex([...$snapshot->catalogFacts, $package]);
        $edges = $this->edges($index, 'livewire-view-action');
        $this->assertCount(2, $edges);
        $this->assertEqualsCanonicalizing(['App\\Orders::save', 'App\\OtherOrders::save'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $edges));
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_component_view_selection_required']);
        }
    }

    public function test_livewire_compiler_companions_cover_index_self_named_and_zap_paths_without_accepting_mismatched_files(): void
    {
        $class = '<?php new class extends \\Livewire\\Component { public function save() {} };';
        $files = [];
        foreach (['counter', 'orders/index', 'orders/orders', '⚡counter', 'orders/⚡index'] as $name) {
            $files[] = new FileContext('resources/views/components/'.$name.'.blade.php', $class.' ?> <button wire:click="save"></button>');
        }
        foreach (['counter', 'orders/index', 'orders/orders', '⚡counter', 'orders/⚡️index'] as $directory) {
            $basename = preg_replace('/⚡[\x{FE0E}\x{FE0F}]?/u', '', basename($directory));
            $files[] = new FileContext('resources/views/components/'.$directory.'/'.$basename.'.php', $class);
            $files[] = new FileContext('resources/views/components/'.$directory.'/'.$basename.'.blade.php', '<button wire:click="save"></button>');
        }
        $files[] = new FileContext('custom/components/invoice/invoice.php', $class);
        $files[] = new FileContext('custom/components/invoice/invoice.blade.php', '<button wire:click="save"></button>');
        $files[] = new FileContext('resources/views/components/wrong/arbitrary.php', $class);
        $files[] = new FileContext('resources/views/components/wrong/arbitrary.blade.php', '<button wire:click="save"></button>');
        $files[] = new FileContext('resources/views/components/later.blade.php', '<?php $first = 1; ?>'.$class.' ?> <button wire:click="save"></button>');
        $files[] = new FileContext('resources/views/components/second.blade.php', '<?php new class {}; new class extends \\Livewire\\Component { public function save() {} }; ?> <button wire:click="save"></button>');
        $snapshot = (new ProjectGraphBuilder(catalog: true))->build($files);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $snapshot->catalogFacts);
        $index = new CatalogIndex([...$cached, $package]);
        $edges = $this->edges($index, 'renders-livewire-view');
        $this->assertCount(11, $edges);
        $this->assertCount(11, $this->edges($index, 'livewire-view-action'));
        $this->assertNotContains('resources/views/components/wrong/arbitrary.php', array_column($edges, 'path'));
        $this->assertNotContains('resources/views/components/later.blade.php', array_column($edges, 'path'));
        $this->assertNotContains('resources/views/components/second.blade.php', array_column($edges, 'path'));
        $this->assertContains('custom/components/invoice/invoice.php', array_column($edges, 'path'));
        $this->assertContains('package_livewire_analysis', array_column($index->diagnostics, 'code'));
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_compiler_component_selection_required']);
        }
    }

    public function test_livewire_single_and_multi_file_components_keep_original_source_locations_and_companion_views(): void
    {
        $single = <<<'SOURCE'
<?php
use Livewire\Component;
new class extends Component {
    #[\Livewire\Attributes\On('saved')]
    public function reload() {}
};
?>
<div>counter</div>
<script>$wire.dynamicAction('private-js-sentinel')</script>
SOURCE;
        $singleFile = new FileContext('resources/views/livewire/counter.blade.php', $single);
        $this->assertSame($singleFile, BladeCatalogExtractor::phpSource($singleFile));
        $multi = <<<'SOURCE'
<?php
use Livewire\Component;
new class extends Component {
    public function save() {}
};
SOURCE;
        $snapshot = (new ProjectGraphBuilder(catalog: true))->build([$singleFile,
            new FileContext('resources/views/livewire/orders/orders.php', $multi),
            new FileContext('resources/views/livewire/orders/orders.blade.php', '<div>orders</div>')]);
        $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"4.4.5"}]}'));
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $snapshot->catalogFacts);
        $index = new CatalogIndex([...$cached, $package]);
        $views = $this->edges($index, 'renders-livewire-view');
        $this->assertCount(2, $views);
        $this->assertEqualsCanonicalizing(['single-file', 'multi-file'], array_column(array_column($views, 'metadata'), 'source_form'));
        foreach ($views as $edge) {
            $this->assertSame(3, $edge['line']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_compiler_component_selection_required']);
            $this->assertSame('view', $index->elements[$edge['to']]['kind']);
        }
        $listener = $this->edges($index, 'livewire-event-listener')[0];
        $method = $index->elements[$listener['to']];
        $this->assertSame(4, $method['line']);
        $this->assertSame(strpos($single, '#['), $method['offset']);
        $this->assertSame('resources/views/livewire/counter.blade.php', $method['path']);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $snapshot->catalogFacts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-js-sentinel', $serialized);
        $this->assertSame([], $this->edges(new CatalogIndex($cached), 'renders-livewire-view'));
        $withoutView = new CatalogIndex([...array_filter($cached, fn ($fact) => $fact->path !== 'resources/views/livewire/orders/orders.blade.php'), $package]);
        $this->assertCount(1, $this->edges($withoutView, 'renders-livewire-view'));
        $override = (new ProjectGraphBuilder(catalog: true))->build([new FileContext('resources/views/livewire/counter.blade.php',
            str_replace('public function reload() {}', 'public function reload() {} public function render() { return view("other"); }', $single))]);
        $this->assertSame([], $this->edges(new CatalogIndex([...$override->catalogFacts, $package]), 'renders-livewire-view'));
    }
}
