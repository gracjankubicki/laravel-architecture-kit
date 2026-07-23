<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use GracjanKubicki\ArchitectureKit\Commands\AuditCommand;
use GracjanKubicki\ArchitectureKit\Commands\DoctorCommand;
use GracjanKubicki\ArchitectureKit\Commands\ExplainCommand;
use GracjanKubicki\ArchitectureKit\Commands\GuardCommand;
use GracjanKubicki\ArchitectureKit\Commands\GuidelinesCommand;
use GracjanKubicki\ArchitectureKit\Commands\InstallAgentsCommand;
use GracjanKubicki\ArchitectureKit\Commands\InstallCommand;
use GracjanKubicki\ArchitectureKit\Commands\McpCommand;
use GracjanKubicki\ArchitectureKit\Commands\PlanCommand;
use GracjanKubicki\ArchitectureKit\Commands\SyncCommand;
use GracjanKubicki\ArchitectureKit\Commands\UpgradePlanCommand;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class ArchitectureKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/architectures.php', 'architectures');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/architectures.php' => config_path('architectures.php'),
        ], 'architectures-config');

        $this->commands([
            AuditCommand::class,
            DoctorCommand::class,
            ExplainCommand::class,
            GuardCommand::class,
            GuidelinesCommand::class,
            InstallAgentsCommand::class,
            InstallCommand::class,
            McpCommand::class,
            PlanCommand::class,
            SyncCommand::class,
            UpgradePlanCommand::class,
        ]);

        $this->app->booted(function (): void {
            Mcp::local('architecture-kit', ArchitectureKitServer::class);
        });
    }
}
