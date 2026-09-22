<?php

declare(strict_types=1);
use Composer\InstalledVersions;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;

// Executed only by RouteMap::fresh(), never by the audited endpoint.
$projectPath = $_SERVER['argv'][1] ?? throw new InvalidArgumentException('Missing project path.');
require $projectPath.'/vendor/autoload.php';
require_once __DIR__.'/RouteMap.php';
require_once __DIR__.'/RouteEntry.php';
$application = require $projectPath.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$router = $application->make('router');
$httpKernel = $application->make(HttpKernel::class);
if (! $httpKernel instanceof FoundationHttpKernel) {
    throw new UnexpectedValueException('Unsupported Laravel HTTP kernel.');
}
$providers = array_keys(array_filter($application->getLoadedProviders()));
$versions = [];
foreach (['laravel/framework', 'inertiajs/inertia-laravel', 'laravel/fortify'] as $package) {
    try {
        $versions[$package] = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
    } catch (Throwable) {
        $versions[$package] = null;
    }
}
$authConfig = $application->make('config')->get('auth', []);
$authProviders = [];
foreach (($authConfig['providers'] ?? []) as $provider => $providerConfig) {
    if (! is_string($provider) || ! is_array($providerConfig) || ! is_string($providerConfig['driver'] ?? null)) {
        continue;
    }
    $authProviders[$provider] = [
        'driver' => $providerConfig['driver'],
        'model' => is_string($providerConfig['model'] ?? null) ? $providerConfig['model'] : null,
    ];
}
$authGuards = [];
foreach (($authConfig['guards'] ?? []) as $guard => $guardConfig) {
    if (! is_string($guard) || ! is_array($guardConfig) || ! is_string($guardConfig['driver'] ?? null)) {
        continue;
    }
    $provider = is_string($guardConfig['provider'] ?? null) ? $guardConfig['provider'] : null;
    $providerConfig = $provider !== null ? ($authProviders[$provider] ?? null) : null;
    $model = $providerConfig !== null && $providerConfig['driver'] === 'eloquent' ? $providerConfig['model'] : null;
    $authGuards[$guard] = [
        'driver' => $guardConfig['driver'],
        'provider' => $provider,
        'model' => $model,
        'custom' => $model === null,
    ];
}
$context = [
    'status' => 'known',
    'providers' => $providers,
    'middleware' => $httpKernel->getGlobalMiddleware(),
    'middlewareGroups' => $httpKernel->getMiddlewareGroups(),
    'middlewareAliases' => $httpKernel->getMiddlewareAliases(),
    'packageVersions' => $versions,
    'auth' => [
        'default' => is_string($authConfig['defaults']['guard'] ?? null) ? $authConfig['defaults']['guard'] : null,
        'guards' => $authGuards,
    ],
];
echo 'ARCHITECTURE_KIT_ROUTES='.json_encode(RouteMap::fromRoutes($router->getRoutes(), $context)->snapshot(), JSON_THROW_ON_ERROR);
