<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use Composer\InstalledVersions;

final class ArchitectureKit
{
    public const PACKAGE_NAME = 'gracjankubicki/laravel-architecture-kit';

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'dev-main';
        }

        return 'dev-main';
    }
}
