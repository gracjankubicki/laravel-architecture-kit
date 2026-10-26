<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Laravel\Mcp\Request;

/** Bootstrap the tool's dependencies, never the project's bootstrap or autoloader. */
final class Host
{
    public static function refresh(RepositoryContext $context): void
    {
        RepositoryContext::activate($context);
        // Never replace the container or SDK request during dispatch.
        $app = Container::getInstance();
        $app->make('config')->set('architectures', $context->effectiveSettings());
        Facade::clearResolvedInstance('config');
    }

    public static function create(string $home): Application
    {
        $app = new Application($home.'/host');
        $app->instance('config', new Repository(['app' => ['locale' => 'en', 'fallback_locale' => 'en'], 'view' => ['paths' => [], 'compiled' => $home.'/views']]));
        $app->register(EventServiceProvider::class);
        $app->register(FilesystemServiceProvider::class);
        $app->register(TranslationServiceProvider::class);
        $app->register(ValidationServiceProvider::class);
        // Bind the SDK request to the current transport request without booting provider routes.
        $app->bind(Request::class, static fn ($app) => $app->bound('mcp.request') ? $app->make('mcp.request') : new Request);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }
}
