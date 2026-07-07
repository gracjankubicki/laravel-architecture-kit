<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

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
