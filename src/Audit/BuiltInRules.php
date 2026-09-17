<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Actions\ActionsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ApiResources\ApiResourcesRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\CustomEloquentBuilders\CustomEloquentBuildersRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\DataObjects\DataObjectsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\EloquentLifecycleRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Enums\EnumsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\FormRequests\FormRequestsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Inertia\InertiaRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi\LaravelAiRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ModernPhp85\ModernPhp85Rule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\PortsAndAdapters\PortsAndAdaptersRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\QueryObjects\QueryObjectsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Routes\RouteLogicRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\SaloonRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Services\ServicesRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\FolderPurityRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\ServiceLocatorRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\TestabilityRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\UnenabledPatternRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ThinControllers\ThinControllerRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ValueObjects\ValueObjectsRule;
use Illuminate\Filesystem\Filesystem;

/**
 * Single source of the rules the audit runs for a given set of enabled architectures.
 *
 * File-scoped guidance asks the same rules which of them govern a path, so both must
 * see the same list; keeping the construction here stops the two from drifting.
 */
final readonly class BuiltInRules
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, AuditRule>
     */
    public static function all(Filesystem $files, string $basePath, array $enabled): array
    {
        return [
            new FolderPurityRule($enabled),
            new ThinControllerRule,
            new ServicesRule,
            new ActionsRule,
            new QueryObjectsRule,
            new CustomEloquentBuildersRule,
            new DataObjectsRule,
            new ValueObjectsRule,
            new FormRequestsRule($enabled),
            new EnumsRule($files, $basePath, $enabled),
            new ApiResourcesRule,
            new PortsAndAdaptersRule($files, $basePath, $enabled),
            new ModernPhp85Rule,
            new LaravelAiRule,
            new InertiaRule,
            new EloquentLifecycleRule($files, $basePath),
            new SaloonRule,
            new RouteLogicRule,
            new ServiceLocatorRule,
            new TestabilityRule,
            new UnenabledPatternRule($enabled),
        ];
    }
}
