<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Accepted vocabulary. Presence here is not evidence that an extractor supports a shape. */
final class CatalogKinds
{
    public const PHP = ['file', 'class', 'interface', 'trait', 'enum', 'enum-case', 'method', 'function', 'closure', 'property', 'attribute'];

    public const ROLES = ['livewire-component', 'livewire-form', 'livewire-action', 'livewire-render', 'livewire-lifecycle', 'livewire-computed', 'cashier-webhook-controller', 'billable-model', 'oauth-provider', 'searchable-model', 'feature-definition', 'ai-agent', 'ai-tool', 'ai-gateway', 'mcp-server', 'mcp-tool', 'mcp-resource', 'mcp-prompt', 'controller', 'middleware', 'form-request', 'validation-rule', 'resource', 'resource-collection',
        'action', 'service', 'query', 'dto', 'value-object', 'port', 'adapter', 'gateway', 'connector', 'sdk-request',
        'provider', 'policy', 'gate', 'guard', 'user-provider', 'model', 'pivot', 'builder', 'scope', 'cast', 'accessor', 'mutator',
        'observer', 'model-callback', 'event', 'listener', 'event-subscriber', 'job', 'job-middleware', 'job-callback',
        'command', 'notification', 'notification-channel', 'mailable', 'broadcast-event', 'blade-component',
        'view-composer', 'view-creator', 'migration', 'seeder', 'factory', 'exception', 'exception-callback', 'test', 'test-method', 'pest-test', 'dataset'];

    public const RESOURCES = ['saloon-pool-site', 'livewire-listeners', 'livewire-view-action', 'livewire-registration', 'livewire-component-name', 'livewire-dispatch', 'livewire-attribute', 'livewire-event', 'reverb-server', 'reverb-application', 'broadcast-connection', 'reverb-definition', 'broadcast-connection-default', 'broadcast-connection-selector', 'horizon-environment', 'horizon-definition', 'horizon-supervisor', 'cashier-operation', 'cashier-webhook', 'socialite-operation', 'oauth-provider', 'scout-index-selector', 'scout-index', 'scout-engine', 'scout-call-site', 'pennant-operation', 'feature-flag', 'ai-declaration', 'ai-tools', 'source-method-object-list', 'ai-response-callback', 'source-response-callback', 'ai-call-site', 'ai-invocation', 'ai-attachment', 'ai-gateway-call-site', 'ai-gateway-operation', 'ai-provider-gateway-binding', 'ai-provider-construction', 'ai-provider-gateway-selection', 'fortify-operation', 'inertia-operation', 'inertia-page', 'package-descriptor', 'package-operation', 'mcp-endpoint', 'attribute-access', 'factory-related', 'factory-state', 'factory-configuration', 'factory-operation', 'seeder-operation', 'database-seeder', 'database-migration', 'test-invocation', 'test-hook', 'data-operation', 'framework-validation-rule', 'resource-operation', 'validation-site', 'composer-manifest', 'composer-package', 'composer-dependency', 'autoload-mapping', 'route', 'exception-type', 'auth-driver', 'user-provider-driver', 'auth-guard', 'auth-user-provider', 'authorization-check', 'console-command', 'scheduled-task', 'job-chain', 'job-batch', 'queue', 'database-connection', 'table',
        'view', 'blade-component-tag', 'broadcast-channel', 'broadcast-subscription', 'storage-disk', 'cache-store', 'cache-key', 'cache-lock',
        'config-key', 'external-service', 'external-endpoint', 'container-binding'];

    public const PACKAGES = ['inertiajs/inertia-laravel', 'livewire/livewire', 'laravel/fortify', 'laravel/ai', 'laravel/mcp',
        'laravel/scout', 'laravel/pennant', 'laravel/cashier', 'laravel/socialite', 'laravel/horizon', 'laravel/reverb'];

    public const METADATA = ['cron', 'timezone', 'retry', 'timeout', 'after_commit', 'queued'];

    /** Structural/type references must not be traversed as runtime calls. */
    public const STRUCTURAL_RELATIONS = ['registers-saloon-pool-callback', 'replaces-saloon-pool-members', 'prepares-saloon-pool', 'references-saloon-pool-member', 'uses-ai-provider-setter-gateway', 'uses-ai-provider-constructor-gateway', 'passes-ai-provider-gateway', 'references-ai-provider-gateway', 'registers-ai-gateway-callback', 'passes-ai-gateway-callback', 'registers-livewire-component', 'references-livewire-component-class', 'declares-livewire-computed', 'declares-livewire-validate', 'registers-livewire-listener', 'declares-reverb-server', 'declares-reverb-application', 'declares-broadcast-connection', 'selects-default-broadcast-connection', 'horizon-supervisor-options', 'horizon-supervises-queue', 'prepares-cashier-subscription', 'declares-scout-index', 'declares-scout-engine', 'prepares-scout-search', 'defines-feature', 'declares-ai-tool', 'registers-fortify-extension', 'declares-inertia-middleware-shared-props', 'registers-inertia-shared-props', 'declares-mcp-name', 'declares-mcp-description', 'registers-global-scope', 'uses-cast', 'maps-table', 'uses-database-connection', 'table-connection', 'contains', 'extends', 'implements', 'uses-trait', 'type-reference', 'attribute-type', 'returns-value', 'returns-null', 'returns-parameter', 'declares-dependency', 'declares-autoload', 'references-package-version', 'exception-report-control', 'registers-console-file', 'validation-site-registration', 'resource-operation-target', 'returned-framework-rule'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique([...self::PHP, ...self::ROLES, ...self::RESOURCES]));
    }
}
