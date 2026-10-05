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
use GracjanKubicki\ArchitectureKit\Audit\Rules\Fortify\FortifyRule;
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
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use Illuminate\Filesystem\Filesystem;

/**
 * Single source of the rules the audit runs for a given set of enabled architectures.
 *
 * File-scoped guidance asks the same rules which of them govern a path, so both must
 * see the same list; keeping the construction here stops the two from drifting.
 */
final readonly class BuiltInRules
{
    /** @param array<int, Architecture|string> $enabled
     * @return list<AuditFinding>
     */
    public static function check(AuditRule $rule, FileContext $file, array $enabled, ProjectClassification $classification): array
    {
        if ($classification->roles->mappings->roles === []) {
            return $rule->check($file);
        }
        if (! in_array($rule::class, [ActionsRule::class, QueryObjectsRule::class, FolderPurityRule::class, ThinControllerRule::class, ServicesRule::class, CustomEloquentBuildersRule::class, DataObjectsRule::class, ValueObjectsRule::class, FormRequestsRule::class, ApiResourcesRule::class, UnenabledPatternRule::class, TestabilityRule::class, InertiaRule::class, EnumsRule::class, PortsAndAdaptersRule::class], true)) {
            return $rule->check($file);
        }
        $findings = [];
        foreach ($classification->views($file) as $view) {
            $classification->prime($view);
            if ($rule->supports($view->path, $enabled)) {
                array_push($findings, ...$rule->check($view));
            }
        }
        $classification->prime($file);

        return $findings;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, AuditRule>
     */
    public static function all(Filesystem $files, string $basePath, array $enabled, ?ProjectClassification $classification = null): array
    {
        return [
            new FolderPurityRule($enabled, $files, $basePath, $classification),
            new ThinControllerRule($classification),
            new ServicesRule($classification),
            new ActionsRule($classification),
            new QueryObjectsRule($classification),
            new CustomEloquentBuildersRule($classification),
            new DataObjectsRule($classification),
            new ValueObjectsRule($classification),
            new FormRequestsRule($enabled, $classification),
            new FortifyRule($files, $basePath),
            new EnumsRule($files, $basePath, $enabled, $classification),
            new ApiResourcesRule($classification),
            new PortsAndAdaptersRule($files, $basePath, $enabled, $classification?->roles),
            new ModernPhp85Rule,
            new LaravelAiRule($files, $basePath),
            new InertiaRule($classification),
            new EloquentLifecycleRule($files, $basePath),
            new SaloonRule($classification),
            new RouteLogicRule,
            new ServiceLocatorRule,
            new TestabilityRule($classification),
            new UnenabledPatternRule($enabled, $files, $basePath, $classification),
        ];
    }
}
