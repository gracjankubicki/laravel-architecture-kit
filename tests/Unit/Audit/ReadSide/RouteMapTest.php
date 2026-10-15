<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteMapTest extends TestCase
{
    public function test_handler_index_preserves_order_duplicates_and_distinct_registration_contexts(): void
    {
        $first = new RouteEntry(['GET'], 'first', class: '\\App\\Controller', method: 'index', middleware: ['auth:web']);
        $second = new RouteEntry(['POST'], 'second', class: 'APP\\CONTROLLER', method: 'INDEX', middleware: ['auth:api']);
        $other = new RouteEntry(['GET'], 'other', class: 'App\\Other', method: 'index');
        $map = new RouteMap(entries: [$first, $other, $first, $second]);

        self::assertSame([$first, $first, $second], $map->entriesFor('app\\controller', 'Index'));
        self::assertSame(['index' => 'INDEX'], $map->methodsFor('\\app\\controller'));
        self::assertSame([
            ['method' => 'index', 'verbs' => ['GET'], 'route' => $first],
            ['method' => 'INDEX', 'verbs' => ['POST'], 'route' => $second],
        ], $map->contextsFor('App\\Controller', 'index'));
        self::assertSame([], $map->methodsFor('App\\Missing'));
        self::assertSame([], $map->entriesFor('App\\Other', 'missing'));

        $legacy = new RouteMap(['app\\controller::index' => ['GET'], 'app\\controller::store' => ['POST']]);
        self::assertSame(['index' => 'index', 'store' => 'store'], $legacy->methodsFor('\\APP\\Controller'));
        self::assertSame([['method' => 'store', 'verbs' => ['POST'], 'route' => null]], $legacy->contextsFor('App\\Controller', 'store'));
        self::assertSame([], (new RouteMap($legacy->methods, entries: []))->methodsFor('App\\Controller'));
    }

    public function test_fully_qualified_controller_matches_before_and_after_snapshot(): void
    {
        $class = 'App\\Http\\Controllers\\ProjectController';
        $map = new RouteMap(entries: [new RouteEntry(['GET'], 'projects', class: '\\'.$class, method: 'index')]);
        foreach ([$map, RouteMap::fromSnapshot($map->snapshot())] as $candidate) {
            self::assertSame(['index' => 'index'], $candidate->methodsFor($class));
            self::assertCount(1, $candidate->contextsFor($class, 'index'));
        }
        self::assertSame(['GET'], RouteMap::fromSnapshot($map->snapshot())->verbs($class, 'index'));
    }

    public function test_registered_and_compiled_routes_produce_the_same_map_without_instantiating_controllers(): void
    {
        $container = new Container;
        $router = new Router(new Dispatcher($container), $container);
        $router->prefix('api')->group(function () use ($router) {
            $router->apiResource('planning', NeverInstantiateController::class)->only(['show', 'store'])->register();
        });
        $router->get('/invoke', NeverInstantiateController::class);
        $router->match(['GET', 'POST'], '/mixed', [NeverInstantiateController::class, 'mixed']);
        $plain = RouteMap::fromRoutes($router->getRoutes());
        $this->assertSame(['GET', 'HEAD'], $plain->verbs(NeverInstantiateController::class, 'show'));
        $this->assertSame(['POST'], $plain->verbs(NeverInstantiateController::class, 'store'));
        $this->assertSame(['GET', 'HEAD'], $plain->verbs(NeverInstantiateController::class, '__invoke'));
        $this->assertSame(['GET', 'HEAD', 'POST'], $plain->verbs(NeverInstantiateController::class, 'mixed'));
        $router->setCompiledRoutes($router->getRoutes()->compile());
        $this->assertSame($plain->methods, RouteMap::fromRoutes($router->getRoutes())->methods);
    }

    public function test_snapshot_v2_round_trips_context_and_route_middleware(): void
    {
        $map = new RouteMap(
            entries: [new RouteEntry(['GET', 'HEAD'], 'projects', class: 'App\\Http\\Controllers\\ProjectController', method: 'index', middleware: ['web', 'inertia'], excludedMiddleware: ['verified'])],
            context: [
                'status' => 'known',
                'providers' => ['App\\Providers\\AppServiceProvider'],
                'middleware' => [],
                'middlewareGroups' => ['web' => ['inertia']],
                'middlewareAliases' => ['inertia' => 'App\\Http\\Middleware\\HandleInertiaRequests'],
                'packageVersions' => ['laravel/framework' => '13.0.0'],
            ],
        );

        $restored = RouteMap::fromSnapshot($map->snapshot());

        $this->assertSame($map->snapshot(), $restored->snapshot());
        $this->assertSame(['web', 'inertia'], $restored->entries[0]->middleware);
        $this->assertSame(['verified'], $restored->entries[0]->excludedMiddleware);
    }

    public function test_legacy_v1_snapshot_remains_readable(): void
    {
        $snapshot = [
            'version' => 1,
            'entries' => [[
                'verbs' => ['POST'],
                'uri' => 'projects',
                'name' => null,
                'domain' => null,
                'constraints' => [],
                'class' => 'App\\Http\\Controllers\\ProjectController',
                'method' => 'store',
                'unresolved' => null,
            ]],
        ];

        $restored = RouteMap::fromSnapshot($snapshot);

        $this->assertSame(['POST'], $restored->verbs('App\\Http\\Controllers\\ProjectController', 'store'));
        $this->assertNull($restored->context);
        $this->assertSame([], $restored->entries[0]->middleware);
    }

    public function test_invalid_v2_context_is_rejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        RouteMap::fromSnapshot(['version' => 2, 'entries' => [], 'context' => ['status' => 'known']]);
    }

    public function test_snapshot_without_context_round_trips_as_unavailable_not_empty(): void
    {
        $restored = RouteMap::fromSnapshot((new RouteMap(entries: []))->snapshot());

        $this->assertSame('unavailable', $restored->context['status']);
        $this->assertStringContainsString('was not supplied', $restored->context['unavailable']);
    }
}

final class NeverInstantiateController
{
    public function __construct()
    {
        throw new \LogicException('No endpoint or constructor may run.');
    }

    public function __invoke(): void {}
}
