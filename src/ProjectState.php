<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Inertia\InertiaCompatibilityResult;
use GracjanKubicki\ArchitectureKit\Install\Requirements\InertiaRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityResult;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use Illuminate\Filesystem\Filesystem;

final readonly class ProjectState
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  array<int, string>  $exclude
     * @param  array{driver: string, service: string|null, php: string, command: array<int, string>|null}  $runtime
     */
    private function __construct(
        public ArchitectureConfig $config,
        public ArchitectureResources $resources,
        public ArchitectureCatalog $catalog,
        public array $enabled,
        public array $exclude,
        public CustomRuleSet $customRules,
        public array $runtime,
        public ?LaravelAiCompatibilityResult $laravelAi,
        public ?InertiaCompatibilityResult $inertia,
        public AuditScope $auditScope,
        public MissingTestLevel $missingTestLevel,
        public ?ProjectGraphCache $graphCache,
    ) {}

    public static function load(Filesystem $files, string $packagePath, string $basePath): self
    {
        $catalog = new ArchitectureCatalog($files, $basePath);
        $config = new ArchitectureConfig(ArchitectureConfigPath::resolve($files, $basePath), $files, $catalog);
        $enabled = $config->read();
        $laravelAi = in_array(Architecture::LaravelAi, $enabled, true)
            ? LaravelAiRequirement::resolve($files, $basePath)
            : null;
        $inertia = in_array(Architecture::Inertia, $enabled, true)
            ? InertiaRequirement::resolve($files, $basePath)
            : null;

        $resources = new ArchitectureResources($packagePath, $basePath, $files, $catalog, $laravelAi);

        return new self(
            $config,
            $resources,
            $catalog,
            $enabled,
            $config->auditExcludes(),
            $config->customRuleSet(),
            $config->runtime(),
            $laravelAi,
            $inertia,
            $config->auditScope(),
            $config->missingTestLevel(),
            $config->graphCache(),
        );
    }

    /**
     * Configuration a cached graph must not be shared across.
     *
     * The builder does not read the enabled list today, so in principle these entries
     * could be shared. They are kept apart anyway: entries are stored per fingerprint
     * rather than overwritten, so the cost of being conservative is one more file, while
     * the cost of being wrong is an answer computed under settings the project no longer
     * has.
     *
     * @return array<int, string>
     */
    public function graphConfiguration(): array
    {
        return self::graphConfigurationFor($this->enabled, $this->customRules);
    }

    /**
     * The same answer for callers that hold the pieces but not the state.
     *
     * The guard can run before a state is loaded, and two spellings of this would mean
     * two fingerprints for one project: each run would miss the entry the other wrote.
     *
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    public static function graphConfigurationFor(array $enabled, CustomRuleSet $customRules): array
    {
        $names = array_map(
            static fn (Architecture|string $architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
            $enabled,
        );
        sort($names);

        $custom = $customRules->knownRuleClasses();
        sort($custom);

        return [implode(',', $names), implode(',', $custom)];
    }

    public function assertCompatibility(): void
    {
        if ($this->laravelAi !== null && ! $this->laravelAi->supported()) {
            throw new \RuntimeException($this->laravelAi->message.' '.$this->laravelAi->remediation);
        }

        if ($this->inertia !== null && ! $this->inertia->supported()) {
            throw new \RuntimeException($this->inertia->message.' '.$this->inertia->remediation);
        }
    }
}
