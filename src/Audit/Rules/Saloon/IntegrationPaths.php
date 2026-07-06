<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Saloon;

use PhpParser\Node\Stmt;
use Taqie\ArchitectureKit\Audit\Ast\ClassInspector;

final readonly class IntegrationPaths
{
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
        return str_starts_with($path, 'app/Http/Controllers/')
            || str_starts_with($path, 'app/Http/Requests/')
            || str_starts_with($path, 'app/Http/Resources/')
            || str_starts_with($path, 'app/Models/');
    }

    public function isUseCasePath(string $path): bool
    {
        return str_starts_with($path, 'app/Actions/')
            || str_contains($path, '/Actions/')
            || str_starts_with($path, 'app/Jobs/')
            || str_contains($path, '/Jobs/');
    }

    public function looksLikeIntegrationDto(Stmt\Class_ $class): bool
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
