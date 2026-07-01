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

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class InstallCommand extends Command
{
    protected $signature = 'architecture-kit:install';

    protected $description = 'Configure Architecture Kit and generate Laravel Boost AI resources.';

    public function handle(Filesystem $files): int
    {
        $config = new ArchitectureConfig(config_path('architectures.php'), $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), base_path(), $files);

        try {
            $current = $config->readOrDefault();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $selected = multiselect(
            label: 'Which architecture patterns does this project use?',
            options: Architecture::promptOptions(),
            default: array_map(fn (Architecture $architecture): string => $architecture->value, $current),
            required: 'Select at least one architecture pattern.',
            hint: 'Use space to select patterns, then press enter.',
        );

        try {
            $enabled = $config->normalize($selected);
            $resources->assertSourcesExist($enabled);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (
            in_array(Architecture::ThinControllers, $enabled, true)
            && ! in_array(Architecture::Actions, $enabled, true)
        ) {
            $this->warn('Thin Controllers were selected without Actions. Thin controllers need another application boundary for business behavior.');

            if (! confirm('Continue with Thin Controllers without Actions?', default: true)) {
                $this->info('No changes were made.');

                return self::SUCCESS;
            }
        }

        if (
            in_array(Architecture::ModernPhp85, $enabled, true)
            && ! PhpRequirement::projectRequiresPhp85($files, base_path())
        ) {
            $this->error('Modern PHP 8.5 is enabled, but composer.json does not require PHP 8.5 or newer.');
            $this->line('Update composer.json require.php to a PHP 8.5+ constraint, then run php artisan architecture-kit:install again.');

            return self::FAILURE;
        }

        $expected = $this->expectedFiles($config, $resources, $enabled);
        $removals = $this->staleSkills($resources, $enabled);
        $plan = $this->plan($files, $resources, $expected, $removals);

        if ($plan['blocked'] !== []) {
            $this->error('Architecture Kit cannot continue because unmanaged files block generated targets.');
            $this->newLine();

            foreach ($plan['blocked'] as $path) {
                $this->line("  blocked  {$this->relative($path)}");
            }

            $this->newLine();
            $this->line('Move or remove the blocking files, then run php artisan architecture-kit:install again.');

            return self::FAILURE;
        }

        $changes = array_merge($plan['create'], $plan['update'], $plan['remove']);

        if ($changes === []) {
            $this->info('No file changes needed.');
        } else {
            $this->line('Planned changes:');

            foreach (['create', 'update', 'remove'] as $status) {
                foreach ($plan[$status] as $path) {
                    $this->line(sprintf('  %-7s %s', $status, $this->relative($path)));
                }
            }

            if (! confirm('Continue?', default: true)) {
                $this->info('No changes were made.');

                return self::SUCCESS;
            }

            $this->writeFiles($files, $expected);
            $this->removeStaleSkills($files, $removals);

            $this->info('Architecture Kit resources generated.');
        }

        if ($this->getApplication()?->has('boost:update') === true) {
            if (confirm('Run php artisan boost:update --discover now?', default: false)) {
                $this->call('boost:update', ['--discover' => true]);
            }
        } else {
            $this->newLine();
            $this->line('Laravel Boost is not installed or boost:update is unavailable.');
            $this->line('To sync agent files later, install Boost and run:');
            $this->line('  composer require laravel/boost --dev');
            $this->line('  php artisan boost:install');
        }

        $this->newLine();
        $this->line('Next:');
        $this->line('  php artisan architecture-kit:doctor');
        $this->line('  php artisan boost:update --discover');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<string, GeneratedFile>
     */
    private function expectedFiles(ArchitectureConfig $config, ArchitectureResources $resources, array $enabled): array
    {
        $files = [
            'config' => new GeneratedFile(
                path: config_path('architectures.php'),
                contents: $config->render($enabled),
            ),
            'guideline' => $resources->guideline($enabled),
        ];

        foreach ($resources->skills($enabled) as $key => $file) {
            $files['skill:'.$key] = $file;
        }

        return $files;
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<string, string>
     */
    private function staleSkills(ArchitectureResources $resources, array $enabled): array
    {
        $expectedNames = array_map(
            fn (Architecture $architecture): string => $architecture->skillName(),
            $enabled,
        );

        return array_filter(
            $resources->existingGeneratedSkillPaths(),
            fn (string $path, string $name): bool => ! in_array($name, $expectedNames, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, GeneratedFile>  $expected
     * @param  array<string, string>  $removals
     * @return array{create: array<int, string>, update: array<int, string>, remove: array<int, string>, blocked: array<int, string>}
     */
    private function plan(Filesystem $files, ArchitectureResources $resources, array $expected, array $removals): array
    {
        $plan = [
            'create' => [],
            'update' => [],
            'remove' => [],
            'blocked' => [],
        ];

        foreach ($expected as $key => $file) {
            if (! $files->exists($file->path)) {
                $plan['create'][] = $file->path;

                continue;
            }

            if ($files->get($file->path) === $file->contents) {
                continue;
            }

            if ($key !== 'config' && ! $resources->isGenerated($file->path)) {
                $plan['blocked'][] = $file->path;

                continue;
            }

            $plan['update'][] = $file->path;
        }

        foreach ($removals as $path) {
            $directory = dirname($path);
            $extraFiles = array_filter(
                $files->allFiles($directory),
                fn ($file): bool => $file->getPathname() !== $path,
            );

            if ($extraFiles !== []) {
                $plan['blocked'][] = $directory;

                continue;
            }

            $plan['remove'][] = $directory;
        }

        return $plan;
    }

    /**
     * @param  array<string, GeneratedFile>  $expected
     */
    private function writeFiles(Filesystem $files, array $expected): void
    {
        foreach ($expected as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
    }

    /**
     * @param  array<string, string>  $removals
     */
    private function removeStaleSkills(Filesystem $files, array $removals): void
    {
        foreach ($removals as $path) {
            $files->deleteDirectory(dirname($path));
        }
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

}
