<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Taqie\ArchitectureKit\Commands\AuditCommand;
use Taqie\ArchitectureKit\Commands\DoctorCommand;
use Taqie\ArchitectureKit\Commands\GuardCommand;
use Taqie\ArchitectureKit\Commands\InstallAgentsCommand;
use Taqie\ArchitectureKit\Commands\InstallCommand;
use Taqie\ArchitectureKit\Commands\InstallHooksCommand;
use Taqie\ArchitectureKit\Commands\McpCommand;
use Taqie\ArchitectureKit\Mcp\ArchitectureKitServer;

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
            GuardCommand::class,
            InstallAgentsCommand::class,
            InstallCommand::class,
            InstallHooksCommand::class,
            McpCommand::class,
        ]);

        $this->app->booted(function (): void {
            Mcp::local('architecture-kit', ArchitectureKitServer::class);
        });
    }
}
