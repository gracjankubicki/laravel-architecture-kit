<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use Composer\Autoload\ClassLoader;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;

/** Select only the running tool's loader, never the inspected application. */
final class WorkerAutoloader
{
    public static function path(RepositoryContext $context): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $directory => $loader) {
            if (realpath((string) $loader->findFile(ApplicationAudit::class)) === realpath(__DIR__.'/ApplicationAudit.php')
                && ! str_starts_with($directory, $context->rootPath.'/') && is_file($directory.'/autoload.php')) {
                return $directory.'/autoload.php';
            }
        }

        return null;
    }
}
