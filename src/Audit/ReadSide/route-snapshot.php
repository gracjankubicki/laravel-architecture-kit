<?php

declare(strict_types=1);
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use Illuminate\Contracts\Console\Kernel;

// Executed only by RouteMap::fresh(), never by the audited endpoint.
$projectPath = $_SERVER['argv'][1] ?? throw new InvalidArgumentException('Missing project path.');
require $projectPath.'/vendor/autoload.php';
require_once __DIR__.'/RouteMap.php';
require_once __DIR__.'/RouteEntry.php';
$application = require $projectPath.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
echo 'ARCHITECTURE_KIT_ROUTES='.json_encode(RouteMap::fromRoutes($application->make('router')->getRoutes())->snapshot(), JSON_THROW_ON_ERROR);
