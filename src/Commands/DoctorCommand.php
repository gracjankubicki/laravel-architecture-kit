<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureResources;
use Taqie\ArchitectureKit\Support\GeneratedFile;
use Taqie\ArchitectureKit\Support\PhpRequirement;

class DoctorCommand extends Command
{
    protected $signature = 'architecture-kit:doctor';

    protected $description = 'Inspect Architecture Kit configuration and generated AI resources.';

    public function handle(Filesystem $files): int
    {
        $config = new ArchitectureConfig(config_path('architectures.php'), $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), base_path(), $files);
        $failed = false;

        $this->info('Laravel Architecture Kit');
        $this->newLine();

        try {
            $enabled = $config->read();
            $resources->assertSourcesExist($enabled);
            $this->line('Config:');
            $this->line('  current  config/architectures.php');
            $this->line('  enabled  '.implode(', ', array_map(fn (Architecture $architecture): string => $architecture->value, $enabled)));

            if (
                in_array(Architecture::ModernPhp85, $enabled, true)
                && ! PhpRequirement::projectRequiresPhp85($files, base_path())
            ) {
                $failed = true;
                $this->line('  blocked  composer.json');
                $this->line('  reason   Modern PHP 8.5 is enabled but composer.json does not require PHP 8.5 or newer.');
            }
        } catch (Throwable $exception) {
            $this->line('Config:');
            $this->line('  blocked  config/architectures.php');
            $this->line('  reason   '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Generated resources:');

        $expected = [
            'guideline' => $resources->guideline($enabled),
        ];

        foreach ($resources->skills($enabled) as $key => $skill) {
            $expected['skill:'.$key] = $skill;
        }

        foreach ($expected as $file) {
            $status = $this->status($files, $resources, $file);
            $failed = $failed || $status !== 'current';
            $this->line(sprintf('  %-8s %s', $status, $this->relative($file->path)));
        }

        $expectedSkillNames = array_map(
            fn (Architecture $architecture): string => $architecture->skillName(),
            $enabled,
        );

        foreach ($resources->existingGeneratedSkillPaths() as $name => $path) {
            if (in_array($name, $expectedSkillNames, true)) {
                continue;
            }

            $failed = true;
            $this->line(sprintf('  %-8s %s', 'stale', $this->relative(dirname($path))));
        }

        $this->newLine();
        $this->line('Laravel Boost:');

        if ($this->getApplication()?->has('boost:update') === true) {
            $this->line('  installed yes');
            $this->line('  sync      php artisan boost:update --discover');
        } else {
            $this->line('  installed no');
            $this->line('  warning   Install laravel/boost and run php artisan boost:install or boost:update --discover to sync agent files.');
        }

        if ($failed) {
            $this->newLine();
            $this->line('Run php artisan architecture-kit:install to regenerate Architecture Kit resources.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function status(Filesystem $files, ArchitectureResources $resources, GeneratedFile $file): string
    {
        if (! $files->exists($file->path)) {
            return 'missing';
        }

        if ($files->get($file->path) === $file->contents) {
            return 'current';
        }

        if (! $resources->isGenerated($file->path)) {
            return 'blocked';
        }

        return 'outdated';
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
