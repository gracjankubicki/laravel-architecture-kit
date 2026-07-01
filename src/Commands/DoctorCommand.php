<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureDoctor;
use Taqie\ArchitectureKit\Support\ArchitectureDoctorCheck;
use Taqie\ArchitectureKit\Support\ArchitectureResources;

class DoctorCommand extends Command
{
    protected $signature = 'architecture-kit:doctor';

    protected $description = 'Inspect Architecture Kit configuration and generated AI resources.';

    public function handle(Filesystem $files): int
    {
        $config = new ArchitectureConfig(config_path('architectures.php'), $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), base_path(), $files);
        $result = (new ArchitectureDoctor($config, $resources, $files, base_path(), $this->getApplication()))->run();

        $this->info('Laravel Architecture Kit');
        $this->newLine();

        $this->line('Config:');

        foreach ($this->checksFor($result->checks, 'config') as $check) {
            $this->line(sprintf('  %-8s %s', $check->status, $check->path));

            if ($check->message !== null) {
                $this->line('  reason   '.$check->message);
            }
        }

        if ($result->enabled !== []) {
            $this->line('  enabled  '.implode(', ', array_map(fn (Architecture $architecture): string => $architecture->value, $result->enabled)));
        }

        $this->newLine();
        $this->line('Generated resources:');

        foreach ($this->checksFor($result->checks, 'generated') as $check) {
            $this->line(sprintf('  %-8s %s', $check->status, $check->path));
        }

        $agentChecks = $this->checksFor($result->checks, 'agents');

        if ($agentChecks !== []) {
            $this->newLine();
            $this->line('Agents:');

            foreach ($agentChecks as $check) {
                $this->line(sprintf('  %-8s %s', $check->status, $check->path));

                if ($check->message !== null) {
                    $this->line('  reason   '.$check->message);
                }
            }
        }

        $this->newLine();
        $this->line('Laravel Boost:');

        if ($result->boostInstalled) {
            $this->line('  installed yes');
            $this->line('  sync      php artisan boost:update --discover');
        } else {
            $this->line('  installed no');
            $this->line('  warning   Install laravel/boost and run php artisan boost:install or boost:update --discover to sync agent files.');
        }

        if (! $result->ok()) {
            $this->newLine();
            $this->line('Run php artisan architecture-kit:install to regenerate Architecture Kit resources.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, ArchitectureDoctorCheck>  $checks
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function checksFor(array $checks, string $area): array
    {
        return array_values(array_filter(
            $checks,
            fn (ArchitectureDoctorCheck $check): bool => $check->area === $area,
        ));
    }
}
