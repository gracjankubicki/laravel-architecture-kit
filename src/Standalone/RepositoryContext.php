<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Analysis\EngineSettings;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** A process has one repository; the host Laravel application keeps its own base path. */
final class RepositoryContext
{
    private static ?self $current = null;

    private ?AnalysisSession $analysis = null;

    public readonly string $id;

    public readonly string $storage;

    public readonly string $configPath;

    public readonly string $configSource;

    public readonly ConfigurationSelection $selection;

    private readonly string $sourceHash;

    private ?string $packageHash = null;

    /** @var array<string, mixed> */
    public readonly array $settings;

    public function __construct(
        public readonly string $rootPath,
        public readonly string $home,
        public readonly ?string $profile = null,
        public readonly bool $execute = false,
        public readonly bool $write = false,
        public readonly bool $runtimeRoutes = false,
        public readonly bool $advisoryOnly = false,
    ) {
        if (realpath($rootPath) !== $rootPath || ! is_file($rootPath.'/composer.json')) {
            throw new RuntimeException('Select a canonical project directory containing composer.json.');
        }
        Runner::externalHome($home, $rootPath);
        $this->id = hash('sha256', $rootPath);
        $homePath = realpath($home);
        if ($homePath !== false && ($homePath === $rootPath || str_starts_with($homePath, $rootPath.'/'))) {
            throw new RuntimeException('Tool home must be outside the repository.');
        }
        new StateStore($home);
        $this->selection = (new ConfigurationResolver($home))->resolve($rootPath, $profile);
        $this->configSource = $this->selection->source;
        $this->sourceHash = $this->selection->sourceHash;
        $this->settings = $this->selection->settings;
        $this->representation();
        $this->storage = $home.'/repositories/'.$this->id;
        new StateStore($this->storage, $home);
        $effective = $this->effectiveSettings();
        $this->configPath = $this->storage.'/configuration-'.hash('sha256', serialize($effective)).'.php';
        // Only tool-owned literal data is materialized, never application expressions.
        if (is_link($this->configPath)) {
            throw new RuntimeException('Generated configuration cannot be a symlink.');
        }
        $body = '<?php return '.var_export($effective, true).';';
        if (is_file($this->configPath) && file_get_contents($this->configPath) !== $body) {
            throw new RuntimeException('Generated configuration is corrupt. Restore the external state before auditing.');
        }
        if (! is_file($this->configPath)) {
            (new Filesystem)->replace($this->configPath, $body);
            chmod($this->configPath, 0600);
        }
    }

    /** The local Artisan/MCP adapter uses the same external task consent store. */
    public static function consentContext(): self
    {
        return self::current() ?? new self(
            realpath(self::root()) ?: throw new RuntimeException('Project root is unavailable.'),
            getenv('ARCHITECTURE_KIT_HOME') ?: getenv('HOME').'/.architecture-kit',
        );
    }

    public static function requestedMode(mixed $value = 'basic', mixed $task = null): AnalysisMode
    {
        $mode = is_string($value) ? AnalysisMode::tryFrom($value) : null;
        if ($mode === null) {
            throw new RuntimeException('Analysis mode must be basic or deep.');
        }
        if ($mode === AnalysisMode::Deep) {
            if (! is_string($task) || $task === '') {
                throw new RuntimeException('Deep analysis requires a task and prior human consent.');
            }
            self::consentContext()->assertAnalysisMode($mode, $task);
        }

        return $mode;
    }

    /** Full revision and target assessment has no Basic implementation. */
    public static function requiredDeep(mixed $value = 'basic', mixed $task = null): AnalysisMode
    {
        $mode = self::requestedMode($value, $task);
        if ($mode !== AnalysisMode::Deep) {
            throw new RuntimeException('This assessment requires explicit Deep mode, a task and prior human consent. Ask the user before granting analysis-consent.');
        }

        return $mode;
    }

    public function representation(): string
    {
        $value = $this->settings['graph_runtime']['representation'] ?? 'php';
        if (! is_string($value) || ! in_array($value, ['php', 'rust'], true)) {
            throw new RuntimeException('graph_runtime.representation must be php or rust.');
        }

        return $value;
    }

    public function controlPolicy(): ControlPolicy
    {
        $control = $this->settings['control'] ?? [];
        if (! is_array($control)) {
            throw new RuntimeException('control must be a settings array.');
        }
        $value = $control['policy'] ?? 'advisory';
        if (! is_string($value) || ($policy = ControlPolicy::tryFrom($value)) === null) {
            throw new RuntimeException('control.policy must be advisory or enforce.');
        }

        return $this->selection->inherited() || $this->advisoryOnly ? ControlPolicy::Advisory : $policy;
    }

    /** Analysis settings are inherited, operational blocking is not.
     * @return array<string, mixed>
     */
    public function effectiveSettings(): array
    {
        if (! $this->selection->inherited() && ! $this->advisoryOnly) {
            return $this->settings;
        }
        $settings = $this->settings;
        if (! is_array($settings['control'] ?? []) || ! is_array($settings['audit'] ?? [])) {
            throw new RuntimeException('control and audit must be settings arrays.');
        }
        $this->controlPolicy();
        $audit = $settings['audit'] ?? [];
        $incomplete = array_key_exists('incomplete_analysis', $audit) ? $audit['incomplete_analysis'] : 'warn';
        if (! is_string($incomplete) || ! in_array($incomplete, ['warn', 'block'], true)) {
            throw new RuntimeException('audit.incomplete_analysis must be one of: warn, block.');
        }
        $settings['control']['policy'] = 'advisory';
        $settings['audit']['incomplete_analysis'] = 'warn';

        return $settings;
    }

    public function assertAnalysisMode(AnalysisMode $mode, ?string $task): void
    {
        if ($mode === AnalysisMode::Deep) {
            if ($task === null) {
                throw new RuntimeException('Deep analysis requires a repository task and prior human consent.');
            }
            (new AnalysisConsent($this))->requireDeep($task);
        }
    }

    public function analysis(): AnalysisSession
    {
        if ($this->analysis === null) {
            $composer = json_decode((string) file_get_contents($this->rootPath.'/composer.json'), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($composer)) {
                throw new RuntimeException('Composer declarations must contain an object.');
            }
            $settings = EngineSettings::from($this->settings, $composer, $this->configSource);
            $this->analysis = $settings->session($this->home);
        }

        return $this->analysis;
    }

    public static function activate(?self $context): void
    {
        self::$current = $context;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function forRoot(string $root): ?self
    {
        return self::$current?->rootPath === $root ? self::$current : null;
    }

    public static function configurationPath(string $root): string
    {
        $context = self::forRoot($root);
        $context?->currentConfigurationHash();

        return $context === null ? $root.'/config/architectures.php' : $context->configPath;
    }

    public static function root(string $path = ''): string
    {
        if (self::$current === null && RepositoryBinding::current() !== null) {
            throw new RuntimeException('context_required: bind a task workspace before using repository tools.');
        }

        return self::$current === null ? base_path($path) : self::$current->rootPath.($path === '' ? '' : '/'.$path);
    }

    public function assertWrite(): void
    {
        if (! $this->write) {
            throw new RuntimeException('Repository writes require --allow-write.');
        }
    }

    public function fingerprint(): string
    {
        $this->packageHash ??= PackageFingerprint::current();
        // A running MCP retains loaded PHP classes. New files on disk cannot be
        // treated as the version it is executing, even if a new cache key exists.
        if ($this->packageHash !== PackageFingerprint::of(dirname(__DIR__))) {
            throw new RuntimeException('Tool sources changed during this process. Restart the tool; the previous task comparison remains invalid.');
        }

        return hash('sha256', serialize([$this->effectiveSettings(), $this->currentConfigurationHash(), $this->profile, $this->execute, $this->runtimeRoutes,
            $this->packageHash, $this->analysis()->fingerprint(), hash_file('sha256', $this->rootPath.'/composer.json')]));
    }

    /** Endpoint routing must not start an analysis engine or hash package/source trees. */
    public function routingIdentity(): string
    {
        $composer = json_decode((string) file_get_contents($this->rootPath.'/composer.json'), true, 64, JSON_THROW_ON_ERROR);
        $engine = EngineSettings::from($this->settings, $composer, $this->configSource);

        $metadata = [];
        foreach (['composer.lock', 'vendor/composer/installed.json'] as $path) {
            $metadata[$path] = SourceManifest::safe($this->rootPath, $path, true)
                ? (is_file($this->rootPath.'/'.$path) ? hash_file('sha256', $this->rootPath.'/'.$path) : null) : 'unsafe';
        }

        $metadata['Modules'] = is_dir($this->rootPath.'/Modules') && ! is_link($this->rootPath.'/Modules');

        return hash('sha256', serialize([$this->id, $this->selection->identity(), $this->representation(), $engine->fingerprint(), $metadata,
            hash('sha256', json_encode($composer, JSON_THROW_ON_ERROR)),
            $this->execute, $this->runtimeRoutes, $this->advisoryOnly, dirname(__DIR__, 2), GraphProtocol::VERSION]));
    }

    private function currentConfigurationHash(): string
    {
        $fresh = new self($this->rootPath, $this->home, $this->profile, $this->execute, $this->write, $this->runtimeRoutes, $this->advisoryOnly);
        if ($fresh->configSource !== $this->configSource || $fresh->sourceHash !== $this->sourceHash) {
            throw new RuntimeException('Selected configuration changed during this request. Use the next request to refresh settings; the previous task comparison remains invalid.');
        }

        return hash('sha256', $fresh->configSource.':'.$fresh->sourceHash);
    }
}
