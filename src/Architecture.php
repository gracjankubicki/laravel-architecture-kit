<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit;

enum Architecture: string
{
    case ThinControllers = 'thin-controllers';
    case FormRequests = 'form-requests';
    case Actions = 'actions';
    case Services = 'services';
    case QueryObjects = 'query-objects';
    case CustomEloquentBuilders = 'custom-eloquent-builders';
    case DataObjects = 'data-objects';
    case ValueObjects = 'value-objects';
    case Enums = 'enums';
    case ApiResources = 'api-resources';
    case EloquentLifecycle = 'eloquent-lifecycle';
    case Saloon = 'saloon';
    case PortsAndAdapters = 'ports-and-adapters';
    case ModernPhp85 = 'modern-php-85';
    case LaravelAi = 'laravel-ai';
    case LaravelBestPractices = 'laravel-best-practices';

    /**
     * @return array<int, self>
     */
    public static function defaultSelection(): array
    {
        return [
            self::ThinControllers,
            self::FormRequests,
            self::Actions,
            self::DataObjects,
            self::ApiResources,
            self::LaravelBestPractices,
        ];
    }

    /**
     * @return array<int, self>
     */
    public static function guidelineOrder(): array
    {
        return [
            self::ThinControllers,
            self::FormRequests,
            self::Actions,
            self::Services,
            self::QueryObjects,
            self::CustomEloquentBuilders,
            self::DataObjects,
            self::ValueObjects,
            self::Enums,
            self::ApiResources,
            self::EloquentLifecycle,
            self::Saloon,
            self::PortsAndAdapters,
            self::ModernPhp85,
            self::LaravelAi,
            self::LaravelBestPractices,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function promptOptions(): array
    {
        $options = [];

        foreach (self::guidelineOrder() as $architecture) {
            $options[$architecture->value] = $architecture->label();
        }

        return $options;
    }

    public function label(): string
    {
        return match ($this) {
            self::ThinControllers => 'Thin Controllers',
            self::FormRequests => 'Form Requests',
            self::Actions => 'Actions',
            self::Services => 'Services',
            self::QueryObjects => 'Query Objects',
            self::CustomEloquentBuilders => 'Custom Eloquent Builders',
            self::DataObjects => 'Data Objects',
            self::ValueObjects => 'Value Objects',
            self::Enums => 'Enums',
            self::ApiResources => 'API Resources',
            self::EloquentLifecycle => 'Eloquent Lifecycle',
            self::Saloon => 'Saloon',
            self::PortsAndAdapters => 'Ports And Adapters',
            self::ModernPhp85 => 'Modern PHP 8.5',
            self::LaravelAi => 'Laravel AI',
            self::LaravelBestPractices => 'Laravel Best Practices',
        };
    }

    public function skillName(): string
    {
        return 'architecture-kit-'.$this->value;
    }

    public function defaultPlacement(): ?string
    {
        return match ($this) {
            self::ThinControllers => 'app/Http/Controllers',
            self::FormRequests => 'app/Http/Requests',
            self::Actions => 'app/Actions',
            self::Services => 'app/Services',
            self::QueryObjects => 'app/Queries',
            self::CustomEloquentBuilders => 'app/Models/Builders',
            self::DataObjects => 'app/Data',
            self::ValueObjects => 'app/ValueObjects',
            self::Enums => null,
            self::ApiResources => 'app/Http/Resources',
            self::EloquentLifecycle => 'app/Observers, app/Lifecycle',
            self::Saloon => 'app/Http/Integrations',
            self::PortsAndAdapters => null,
            self::ModernPhp85 => null,
            self::LaravelAi => 'app/Ai',
            self::LaravelBestPractices => null,
        };
    }

    public function sourcePath(): string
    {
        return 'resources/architectures/'.$this->value;
    }
}
