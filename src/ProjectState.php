<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityResult;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use Illuminate\Filesystem\Filesystem;

final readonly class ProjectState
{
    /** @param array<int, Architecture|string> $enabled */
    /** @param array<int, string> $exclude */
    /** @param array{driver: string, service: string|null, php: string, command: array<int, string>|null} $runtime */
    private function __construct(
        public ArchitectureConfig $config,
        public ArchitectureResources $resources,
        public ArchitectureCatalog $catalog,
        public array $enabled,
        public array $exclude,
        public CustomRuleSet $customRules,
        public array $runtime,
        public ?LaravelAiCompatibilityResult $laravelAi,
    ) {}

    public static function load(Filesystem $files, string $packagePath, string $basePath): self
    {
        $catalog = new ArchitectureCatalog($files, $basePath);
        $config = new ArchitectureConfig(ArchitectureConfigPath::resolve($files, $basePath), $files, $catalog);
        $enabled = $config->read();
        $laravelAi = in_array(Architecture::LaravelAi, $enabled, true)
            ? LaravelAiRequirement::resolve($files, $basePath)
            : null;

        $resources = new ArchitectureResources($packagePath, $basePath, $files, $catalog, $laravelAi);

        return new self($config, $resources, $catalog, $enabled, $config->auditExcludes(), $config->customRuleSet(), $config->runtime(), $laravelAi);
    }

    public function assertCompatibility(): void
    {
        if ($this->laravelAi !== null && ! $this->laravelAi->supported()) {
            throw new \RuntimeException($this->laravelAi->message.' '.$this->laravelAi->remediation);
        }
    }
}
