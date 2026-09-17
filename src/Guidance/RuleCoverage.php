<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Guidance;

use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
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
use Illuminate\Support\Str;

/**
 * Maps architectures and audit rule classes to the finding slugs they can produce.
 *
 * The audit rule interface intentionally does not expose a slug, because a rule may
 * emit several and custom project rules must stay free to define their own. The map
 * below therefore lives next to the rules rather than inside them, and a guard test
 * fails when a built-in rule is added without an entry here.
 */
final class RuleCoverage
{
    /** @var array<class-string, array<int, string>> */
    private const BUILT_IN_RULE_SLUGS = [
        ActionsRule::class => ['actions'],
        ApiResourcesRule::class => ['api-resource'],
        CustomEloquentBuildersRule::class => ['custom-eloquent-builders'],
        DataObjectsRule::class => ['data-objects'],
        EloquentLifecycleRule::class => ['eloquent-lifecycle', 'transaction-side-effects'],
        EnumsRule::class => ['enums'],
        FolderPurityRule::class => ['folder-purity'],
        FormRequestsRule::class => ['form-request'],
        InertiaRule::class => ['inertia'],
        LaravelAiRule::class => ['laravel-ai'],
        ModernPhp85Rule::class => ['modern-php-85'],
        PortsAndAdaptersRule::class => ['ports-and-adapters'],
        QueryObjectsRule::class => ['query-objects'],
        RouteLogicRule::class => ['route-logic'],
        // IntegrationFolderCheck reports folder purity for integration folders, and
        // SaloonInsideTransactionCheck reports a request sent inside a transaction.
        SaloonRule::class => ['saloon', 'raw-http', 'folder-purity', 'transaction-side-effects'],
        ServiceLocatorRule::class => ['service-locator'],
        // ServicesRule reports the service locator inside app/Services itself, which
        // ServiceLocatorRule does not cover.
        ServicesRule::class => ['services', 'service-locator'],
        TestabilityRule::class => ['testability'],
        ThinControllerRule::class => ['thin-controller'],
        UnenabledPatternRule::class => ['unenabled-pattern'],
        ValueObjectsRule::class => ['value-objects'],
    ];

    /**
     * Finding slugs an architecture can produce once enabled. An empty list means the
     * architecture ships guidance only and no violation of it can be detected.
     *
     * @var array<string, array<int, string>>
     */
    private const ARCHITECTURE_RULES = [
        'thin-controllers' => ['thin-controller'],
        'form-requests' => ['form-request'],
        'actions' => ['actions', 'folder-purity'],
        'services' => ['services', 'folder-purity', 'service-locator'],
        'query-objects' => ['query-objects', 'folder-purity'],
        'custom-eloquent-builders' => ['custom-eloquent-builders', 'folder-purity'],
        'data-objects' => ['data-objects', 'folder-purity', 'form-request'],
        'value-objects' => ['value-objects', 'folder-purity'],
        'enums' => ['enums', 'folder-purity'],
        'api-resources' => ['api-resource', 'folder-purity'],
        'inertia' => ['inertia'],
        'eloquent-lifecycle' => ['eloquent-lifecycle', 'transaction-side-effects'],
        'saloon' => ['saloon', 'raw-http', 'folder-purity', 'transaction-side-effects'],
        'ports-and-adapters' => ['ports-and-adapters'],
        'modern-php-85' => ['modern-php-85'],
        'laravel-ai' => ['laravel-ai'],
        'laravel-best-practices' => [],
    ];

    /**
     * Rules no per-file pass can report, because they run over the whole project or over
     * the audit itself. Every other architecture-independent rule reaches the agent
     * through its own supports(), which answers per path instead of claiming to be
     * always active.
     *
     * @var array<int, string>
     */
    private const GLOBAL_RULES = [
        'layer-dependency',
        'namespace-cycle',
        'unparseable-file',
        'invalid-suppression',
    ];

    /**
     * Slugs emitted by one shared rule on behalf of several architectures, where which
     * architecture is answerable follows from the folder. Folder purity of app/Actions
     * belongs to Actions, not to every architecture that also has a pure folder, so the
     * slug is attributed only to the architecture whose placement covers the path.
     *
     * @var array<int, string>
     */
    private const PLACEMENT_SCOPED_RULES = ['folder-purity'];

    /**
     * Finding slugs the given rule instance can produce.
     *
     * A custom project rule has no entry in the built-in map, so its slug is derived
     * the same way the audit derives it when validating suppressions.
     *
     * @return array<int, string>
     */
    public static function slugsFor(AuditRule $rule): array
    {
        return self::BUILT_IN_RULE_SLUGS[$rule::class] ?? [Str::of($rule::class)->classBasename()->kebab()->toString()];
    }

    /**
     * @return array<int, string>
     */
    public static function placementScopedRules(): array
    {
        return self::PLACEMENT_SCOPED_RULES;
    }

    /**
     * @return array<int, string>
     */
    public static function rulesForArchitecture(string $architecture): array
    {
        return self::ARCHITECTURE_RULES[$architecture] ?? [];
    }

    /**
     * True when the architecture is known and ships guidance that no rule can verify.
     */
    public static function isAdvisoryOnly(string $architecture): bool
    {
        return (self::ARCHITECTURE_RULES[$architecture] ?? null) === [];
    }

    public static function isKnownArchitecture(string $architecture): bool
    {
        return array_key_exists($architecture, self::ARCHITECTURE_RULES);
    }

    /**
     * @return array<int, string>
     */
    public static function globalRules(): array
    {
        return self::GLOBAL_RULES;
    }

    /**
     * @return array<class-string, array<int, string>>
     */
    public static function builtInRuleSlugs(): array
    {
        return self::BUILT_IN_RULE_SLUGS;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function architectureRules(): array
    {
        return self::ARCHITECTURE_RULES;
    }
}
