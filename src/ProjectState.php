<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
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
    ) {}

    public static function load(Filesystem $files, string $packagePath, string $basePath): self
    {
        $catalog = new ArchitectureCatalog($files, $basePath);
        $config = new ArchitectureConfig(ArchitectureConfigPath::resolve($files, $basePath), $files, $catalog);
        $resources = new ArchitectureResources($packagePath, $basePath, $files, $catalog);

        return new self($config, $resources, $catalog, $config->read(), $config->auditExcludes(), $config->customRuleSet(), $config->runtime());
    }
}
