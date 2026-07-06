<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use Taqie\ArchitectureKit\Audit\Ast\ClassInspector;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class LifecycleFolderPurityCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isLifecyclePath($file->path)) {
            return [];
        }

        $nodes = $file->ast();
        $class = $nodes === null ? null : ClassInspector::firstClass($nodes);
        $className = $class === null ? basename($file->path, '.php') : (ClassInspector::className($class) ?? basename($file->path, '.php'));

        if ($class !== null && $this->lifecycle->looksLikeLifecycleHandler($className, $class)) {
            return [];
        }

        return [
            new AuditFinding(
                'error',
                'eloquent-lifecycle',
                $file->path,
                1,
                'Lifecycle folders must contain final lifecycle handlers only: one public handle(Model $model): void method and no Data/Result/Enum/Request/Resource classes.',
            ),
        ];
    }
}
