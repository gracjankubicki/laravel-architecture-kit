<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit;

use Illuminate\Support\ServiceProvider;
use Taqie\ArchitectureKit\Commands\DoctorCommand;
use Taqie\ArchitectureKit\Commands\InstallCommand;

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
            DoctorCommand::class,
            InstallCommand::class,
        ]);
    }
}
