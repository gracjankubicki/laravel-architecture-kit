<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use BackedEnum;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** A process has one repository; the host Laravel application keeps its own base path. */
final class RepositoryContext
{
    private static ?self $current = null;

    public readonly string $id;

    public readonly string $storage;

    public readonly string $configPath;

    public readonly string $configSource;

    private readonly string $sourceHash;

    /** @var array<string, mixed> */
    public readonly array $settings;

    public function __construct(
        public readonly string $rootPath,
        public readonly string $home,
        public readonly ?string $profile = null,
        public readonly bool $execute = false,
        public readonly bool $write = false,
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
        $store = new StateStore($home);
        $registry = $store->read('projects');
        $entry = $registry[$this->id] ?? null;
        $repoJson = $rootPath.'/.architecture-kit/settings.json';
        $repoPhp = $rootPath.'/config/architectures.php';
        if ($profile !== null) {
            if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $profile)) {
                throw new RuntimeException('Invalid local profile name.');
            }
            $path = $home.'/profiles/'.$this->id.'/'.$profile.'.json';
        } elseif (is_file($repoJson)) {
            $path = $repoJson;
        } elseif (is_file($repoPhp)) {
            $path = $repoPhp;
        } elseif (is_array($entry) && is_string($entry['config'] ?? null)) {
            $path = $entry['config'];
        } else {
            throw new RuntimeException('Project is not configured. Run architecture-kit init --project PATH.');
        }
        if (! is_file($path) || is_link($path) || filesize($path) > 100000) {
            throw new RuntimeException('Selected configuration is unavailable, unsafe or exceeds 100 KB.');
        }
        StateStore::assertBoundary(dirname($path), str_starts_with($path, $rootPath.'/') ? $rootPath : $home);
        $this->configSource = $path;
        $source = (string) file_get_contents($path);
        $this->sourceHash = hash('sha256', $source);
        $settings = str_ends_with($path, '.json')
            ? json_decode($source, true, 64, JSON_THROW_ON_ERROR)
            : DeclaredConfiguration::readSource($source);
        if (! is_array($settings)) {
            throw new RuntimeException('Configuration must contain static settings.');
        }
        $this->settings = self::data($settings);
        $this->storage = $home.'/repositories/'.$this->id;
        new StateStore($this->storage, $home);
        $this->configPath = $this->storage.'/configuration-'.hash('sha256', serialize($this->settings)).'.php';
        // Only tool-owned literal data is materialized, never application expressions.
        if (is_link($this->configPath)) {
            throw new RuntimeException('Generated configuration cannot be a symlink.');
        }
        $body = '<?php return '.var_export($this->settings, true).';';
        if (is_file($this->configPath) && file_get_contents($this->configPath) !== $body) {
            throw new RuntimeException('Generated configuration is corrupt. Restore the external state before auditing.');
        }
        if (! is_file($this->configPath)) {
            (new Filesystem)->replace($this->configPath, $body);
            chmod($this->configPath, 0600);
        }
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
        return hash('sha256', serialize([$this->settings, $this->currentConfigurationHash(), $this->profile, $this->execute,
            PackageFingerprint::current()]));
    }

    private function currentConfigurationHash(): string
    {
        $fresh = new self($this->rootPath, $this->home, $this->profile, $this->execute, $this->write);
        if ($fresh->configSource !== $this->configSource || $fresh->sourceHash !== $this->sourceHash) {
            throw new RuntimeException('Selected configuration changed during this process. Restart with the current settings; the previous task comparison remains invalid.');
        }

        return hash('sha256', $fresh->configSource.':'.$fresh->sourceHash);
    }

    /** @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function data(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $value instanceof BackedEnum ? $value->value : (is_array($value) ? self::data($value) : $value);
            if (is_object($result[$key]) || is_resource($result[$key])) {
                throw new RuntimeException('Configuration must contain literal data.');
            }
        }

        return $result;
    }
}
