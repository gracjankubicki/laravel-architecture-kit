<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteMapTest extends TestCase
{
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
}

final class NeverInstantiateController
{
    public function __construct()
    {
        throw new \LogicException('No endpoint or constructor may run.');
    }

    public function __invoke(): void {}
}
