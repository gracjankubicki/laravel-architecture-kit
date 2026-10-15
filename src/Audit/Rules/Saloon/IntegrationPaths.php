<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class IntegrationPaths
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    public function isIntegrationName(string $name): bool
    {
        return str_contains('\\'.ltrim($name, '\\'), '\\Http\\Integrations\\')
            || $this->classification?->roleOfName($name) === 'infrastructure';
    }

    public function isIntegrationPath(string $path): bool
    {
        return str_starts_with($path, 'app/Http/Integrations/')
            || str_contains($path, '/Http/Integrations/');
    }

    public function isIntegrationDtoPath(string $path): bool
    {
        return str_contains($path, '/Dto/');
    }

    public function isIntegrationSupportPath(string $path): bool
    {
        return str_contains($path, '/Auth/')
            || str_contains($path, '/Authenticators/')
            || str_contains($path, '/Paginators/')
            || str_contains($path, '/Responses/')
            || str_contains($path, '/Stores/')
            || str_contains($path, '/Plugins/');
    }

    public function isForbiddenAdapterPath(string $path): bool
    {
        foreach (['controller' => 'app/Http/Controllers/', 'request' => 'app/Http/Requests/', 'resource' => 'app/Http/Resources/', 'model' => 'app/Models/'] as $kind => $prefix) {
            if ($this->classification?->kindMatches($path, $kind, str_starts_with($path, $prefix)) ?? str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isUseCasePath(string $path): bool
    {
        return str_starts_with($path, 'app/Actions/')
            || str_contains($path, '/Actions/')
            || str_starts_with($path, 'app/Jobs/')
            || str_contains($path, '/Jobs/');
    }

    public function looksLikeIntegrationDto(Node $class): bool
    {
        $className = ClassInspector::className($class);

        return $className !== null
            && (
                str_ends_with($className, 'Data')
                || str_ends_with($className, 'Dto')
                || str_ends_with($className, 'DTO')
                || str_ends_with($className, 'Result')
            )
            && $class->isFinal()
            && $class->isReadonly();
    }
}
