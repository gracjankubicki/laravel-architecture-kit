<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Config\ArchitectureConfig;
use Taqie\ArchitectureKit\Install\AgentInstaller;
use Taqie\ArchitectureKit\Install\Agents\Agent;
use Taqie\ArchitectureKit\Install\AgentsDetector;
use Taqie\ArchitectureKit\Install\ComposerPackageInstaller;
use Taqie\ArchitectureKit\Install\ComposeServices;
use Taqie\ArchitectureKit\Install\InstallResult;
use Taqie\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use Taqie\ArchitectureKit\Install\Requirements\PhpRequirement;
use Taqie\ArchitectureKit\Install\Requirements\ServicesRequirement;
use Taqie\ArchitectureKit\Resources\ArchitectureResources;
use Taqie\ArchitectureKit\Resources\GeneratedFile;
use Taqie\ArchitectureKit\Support\SaloonRequirement;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'architecture-kit:install';

    protected $description = 'Configure Architecture Kit and generate Laravel Boost AI resources.';

    public function handle(Filesystem $files, ComposerPackageInstaller $composer): int
    {
        $config = new ArchitectureConfig(config_path('architectures.php'), $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), base_path(), $files);

        try {
            $current = $config->readOrDefault();
            $currentRuntime = $config->runtime();

            if (
                ! $files->exists(config_path('architectures.php'))
                && LaravelAiRequirement::projectRequiresLaravelAi($files, base_path())
                && ! in_array(Architecture::LaravelAi, $current, true)
            ) {
                $current[] = Architecture::LaravelAi;
            }

            if (
                ! $files->exists(config_path('architectures.php'))
                && ServicesRequirement::projectHasServices($files, base_path())
                && ! in_array(Architecture::Services, $current, true)
            ) {
                $current[] = Architecture::Services;
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $options = array_merge(Architecture::promptOptions(), $this->customPromptOptions($files));

        $selected = multiselect(
            label: 'Which architecture patterns does this project use?',
            options: $options,
            default: array_map(fn (Architecture|string $architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture, $current),
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
            in_array(Architecture::EloquentLifecycle, $enabled, true)
            && ! in_array(Architecture::Actions, $enabled, true)
        ) {
            $this->warn('Eloquent Lifecycle was selected without Actions. Flow-specific behavior should still live in an explicit application/use-case boundary.');

            if (! confirm('Continue with Eloquent Lifecycle without Actions?', default: true)) {
                $this->info('No changes were made.');

                return self::SUCCESS;
            }
        }

        if (
            in_array(Architecture::Saloon, $enabled, true)
            && ! in_array(Architecture::Actions, $enabled, true)
        ) {
            $this->warn('Saloon was selected without Actions. Integration calls should still live in an explicit application/use-case boundary such as an Action or queued Job.');

            if (! confirm('Continue with Saloon without Actions?', default: true)) {
                $this->info('No changes were made.');

                return self::SUCCESS;
            }
        }

        if (
            in_array(Architecture::LaravelBestPractices, $enabled, true)
            && $files->isDirectory(base_path('.ai/skills/laravel-best-practices'))
        ) {
            $this->warn('Generic laravel-best-practices skill is present. Architecture Kit will generate architecture-kit-laravel-best-practices, which supersedes the generic skill.');
            $this->line('Remove or disable .ai/skills/laravel-best-practices if your agent loads both skills.');
        }

        if (
            in_array(Architecture::PortsAndAdapters, $enabled, true)
            && ! in_array(Architecture::DataObjects, $enabled, true)
        ) {
            $this->warn('Ports And Adapters works best with Data Objects enabled because Port boundaries should use typed project-owned payloads instead of raw arrays.');
        }

        if (
            in_array(Architecture::ModernPhp85, $enabled, true)
            && ! PhpRequirement::projectRequiresPhp85($files, base_path())
        ) {
            $this->error('Modern PHP 8.5 is enabled, but composer.json does not require PHP 8.5 or newer.');
            $this->line('Update composer.json require.php to a PHP 8.5+ constraint, then run php artisan architecture-kit:install again.');

            return self::FAILURE;
        }

        if (in_array(Architecture::Saloon, $enabled, true)) {
            if (! $this->ensureSaloonPackages($files, $composer)) {
                return self::FAILURE;
            }
        }

        if (
            in_array(Architecture::LaravelAi, $enabled, true)
            && ! LaravelAiRequirement::projectRequiresLaravelAi($files, base_path())
        ) {
            $this->error('Laravel AI is enabled, but composer.json does not require laravel/ai.');
            $this->line('Install laravel/ai or disable Architecture::LaravelAi, then run php artisan architecture-kit:install again.');

            return self::FAILURE;
        }

        $runtime = $this->runtime($files, $currentRuntime);
        $expected = $this->expectedFiles($config, $resources, $enabled, $runtime);
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

        $agentInstall = $this->agentInstall($files, $runtime);
        $agentPlan = $agentInstall === null
            ? $this->emptyAgentPlan()
            : $agentInstall['installer']->plan($agentInstall['agents'], $agentInstall['mcp'], $agentInstall['hooks']);

        if ($this->blockedAgentPaths($agentPlan) !== []) {
            $this->error('Architecture Kit cannot continue because unmanaged files block generated targets.');
            $this->newLine();

            foreach ($this->blockedAgentPaths($agentPlan) as $path) {
                $this->line("  blocked  {$this->relative($path)}");
            }

            $this->newLine();
            $this->line('Move or remove the blocking files, then run php artisan architecture-kit:install again.');

            return self::FAILURE;
        }

        $changes = array_merge(
            $plan['create'],
            $plan['update'],
            $plan['remove'],
            $this->agentChangePaths($agentPlan),
        );

        if ($changes === []) {
            $this->info('No file changes needed.');
        } else {
            $this->line('Planned changes:');

            $this->showResourcePlan($plan);
            $this->showAgentPlan($agentPlan);

            if (! confirm('Continue?', default: true)) {
                $this->info('No changes were made.');

                return self::SUCCESS;
            }

            $this->writeFiles($files, $expected);
            $this->removeStaleSkills($files, $removals);

            if ($agentInstall !== null) {
                $agentInstall['installer']->write($agentInstall['agents'], $agentInstall['mcp'], $agentInstall['hooks']);
            }

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
        $this->line('  php artisan architecture-kit:install-agents');
        $this->line('  php artisan architecture-kit:guard --changed --strict');
        $this->line('  php artisan boost:update --discover');

        return self::SUCCESS;
    }

    private function ensureSaloonPackages(Filesystem $files, ComposerPackageInstaller $composer): bool
    {
        $violations = SaloonRequirement::violations($files, base_path());

        if ($violations === []) {
            return true;
        }

        $packages = SaloonRequirement::missingInstallPackages($files, base_path());

        if ($packages === []) {
            $this->showSaloonRequirementFailure($violations);

            return false;
        }

        $this->warn('Saloon is enabled, but required Saloon packages are missing.');
        $this->line('Architecture Kit will run:');
        $this->line('  composer require '.implode(' ', $packages).' --no-interaction --no-progress');

        $result = $composer->requirePackages($packages, base_path());

        if (! $result->successful) {
            $this->error('Composer could not install required Saloon packages.');
            $this->line('Exit code: '.$result->exitCode);

            if ($result->output !== '') {
                $this->line($result->output);
            }

            return false;
        }

        $remainingViolations = SaloonRequirement::violations($files, base_path());

        if ($remainingViolations !== []) {
            $this->showSaloonRequirementFailure($remainingViolations);

            return false;
        }

        $this->info('Required Saloon packages installed.');

        return true;
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function showSaloonRequirementFailure(array $violations): void
    {
        $this->error('Saloon is enabled, but composer.json does not satisfy Architecture::Saloon requirements.');

        foreach ($violations as $violation) {
            $this->line('  - '.$violation);
        }

        $this->line('Install saloonphp/saloon ^4.0, saloonphp/laravel-plugin, and saloonphp/rate-limit-plugin, then run php artisan architecture-kit:install again.');
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  array<string, mixed>  $runtime
     * @return array<string, GeneratedFile>
     */
    private function expectedFiles(ArchitectureConfig $config, ArchitectureResources $resources, array $enabled, array $runtime): array
    {
        $files = [
            'config' => new GeneratedFile(
                path: config_path('architectures.php'),
                contents: $config->render($enabled, $runtime),
            ),
            'guideline' => $resources->guideline($enabled),
        ];

        foreach ($resources->skills($enabled) as $key => $file) {
            $files['skill:'.$key] = $file;
        }

        return $files;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<string, string>
     */
    private function staleSkills(ArchitectureResources $resources, array $enabled): array
    {
        $expectedNames = array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture
                ? $architecture->skillName()
                : 'architecture-kit-'.$architecture,
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
        return str_starts_with($path, base_path().'/')
            ? str_replace(base_path().'/', '', $path)
            : $path;
    }

    /**
     * @return array<string, string>
     */
    private function customPromptOptions(Filesystem $files): array
    {
        $basePath = base_path('.architecture-kit/architectures');

        if (! $files->isDirectory($basePath)) {
            return [];
        }

        $options = [];

        foreach ($files->directories($basePath) as $directory) {
            $slug = basename($directory);

            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                continue;
            }

            $options[$slug] = str($slug)->replace('-', ' ')->title()->append(' (custom)')->toString();
        }

        ksort($options);

        return $options;
    }

    /**
     * @param  array{driver: string, service: string|null, php: string, command: array<int, string>|null}  $current
     * @return array{driver: string, service: string|null, php: string, command: array<int, string>|null}
     */
    private function runtime(Filesystem $files, array $current): array
    {
        $compose = new ComposeServices($files, base_path());
        $default = $current['driver'] !== 'local' ? $current['driver'] : $compose->detectedRuntimeDriver();
        $driver = select(
            label: 'How does this project run PHP?',
            options: $this->runtimeOptions(),
            default: $default,
        );
        $php = $current['php'] !== '' ? $current['php'] : 'php';

        if ($driver === 'docker') {
            return [
                'driver' => 'docker',
                'service' => $this->dockerService($compose, $current['service']),
                'php' => $php,
                'command' => null,
            ];
        }

        if ($driver === 'sail') {
            return [
                'driver' => 'sail',
                'service' => $compose->sailService(),
                'php' => $php,
                'command' => null,
            ];
        }

        if ($driver === 'custom') {
            $defaultCommand = $current['command'] !== null ? implode(' ', $current['command']) : 'bin/php-runner';
            $command = text(
                label: 'Command prefix?',
                default: $defaultCommand,
                required: 'Provide the command prefix that runs PHP for this project.',
                hint: 'Example: bin/php-runner. Architecture Kit appends artisan and the command name.',
            );

            return [
                'driver' => 'custom',
                'service' => null,
                'php' => $php,
                'command' => preg_split('/\s+/', trim($command)) ?: [$command],
            ];
        }

        return [
            'driver' => 'local',
            'service' => null,
            'php' => $php,
            'command' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function runtimeOptions(): array
    {
        return [
            'docker' => 'Docker Compose',
            'sail' => 'Laravel Sail',
            'local' => 'Local PHP',
            'custom' => 'Custom command',
        ];
    }

    private function dockerService(ComposeServices $compose, ?string $currentService): string
    {
        $services = $compose->services();
        $default = $currentService !== null && $currentService !== '' ? $currentService : 'app';

        if ($services === null) {
            return text(
                label: 'Which Docker Compose service runs PHP?',
                default: $default,
                required: 'Provide the Docker Compose service name.',
            );
        }

        return select(
            label: 'Which Docker Compose service runs PHP?',
            options: array_combine($services, $services) ?: ['app' => 'app'],
            default: in_array($default, $services, true) ? $default : ($services[0] ?? 'app'),
        );
    }

    /**
     * @param  array{driver: string, service: string|null, php: string, command: array<int, string>|null}  $runtime
     * @return array{installer: AgentInstaller, agents: array<int, Agent>, mcp: bool, hooks: bool}|null
     */
    private function agentInstall(Filesystem $files, array $runtime): ?array
    {
        if (! confirm('Install Architecture Kit MCP and hooks for AI agents now?', default: true)) {
            return null;
        }

        $detector = new AgentsDetector($files, base_path());
        $agentNames = $this->agentNames($detector);
        $features = $this->agentFeatures();

        return [
            'installer' => new AgentInstaller($files, base_path(), $runtime),
            'agents' => $detector->resolve($agentNames),
            'mcp' => $features['mcp'],
            'hooks' => $features['hooks'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function agentNames(AgentsDetector $detector): array
    {
        $detected = $detector->detectedAgentNames();
        $default = $detected === [] ? ['codex', 'claude_code'] : $detected;

        return multiselect(
            label: 'Which AI agents should Architecture Kit configure?',
            options: collect($detector->agents())
                ->mapWithKeys(fn (Agent $agent, string $name): array => [$name => $agent->displayName()])
                ->all(),
            default: $default,
            required: 'Select at least one agent.',
        );
    }

    /**
     * @return array{mcp: bool, hooks: bool}
     */
    private function agentFeatures(): array
    {
        $features = multiselect(
            label: 'Which agent integrations should Architecture Kit install?',
            options: [
                'mcp' => 'MCP server config',
                'hooks' => 'Guard hooks',
            ],
            default: ['mcp', 'hooks'],
            required: 'Select at least one integration.',
        );

        return [
            'mcp' => in_array('mcp', $features, true),
            'hooks' => in_array('hooks', $features, true),
        ];
    }

    /**
     * @return array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}
     */
    private function emptyAgentPlan(): array
    {
        $empty = new InstallResult;

        return [
            'mcp' => $empty,
            'hooks' => $empty,
            'state' => $empty,
        ];
    }

    /**
     * @param  array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}  $plan
     * @return array<int, string>
     */
    private function blockedAgentPaths(array $plan): array
    {
        return array_values(array_unique(array_merge(
            $plan['mcp']->blocked,
            $plan['hooks']->blocked,
            $plan['state']->blocked,
        )));
    }

    /**
     * @param  array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}  $plan
     * @return array<int, string>
     */
    private function agentChangePaths(array $plan): array
    {
        return array_values(array_unique(array_merge(
            $plan['mcp']->creates,
            $plan['mcp']->updates,
            $plan['hooks']->creates,
            $plan['hooks']->updates,
            $plan['state']->creates,
            $plan['state']->updates,
        )));
    }

    /**
     * @param  array{create: array<int, string>, update: array<int, string>, remove: array<int, string>, blocked: array<int, string>}  $plan
     */
    private function showResourcePlan(array $plan): void
    {
        foreach (['create', 'update', 'remove'] as $status) {
            foreach ($plan[$status] as $path) {
                $this->line(sprintf('  %-7s resources %s', $status, $this->relative($path)));
            }
        }
    }

    /**
     * @param  array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}  $plan
     */
    private function showAgentPlan(array $plan): void
    {
        foreach ($plan as $area => $result) {
            foreach (['creates' => 'create', 'updates' => 'update'] as $property => $status) {
                foreach ($result->{$property} as $path) {
                    $this->line(sprintf('  %-7s %-9s %s', $status, $area, $path));
                }
            }
        }
    }
}
