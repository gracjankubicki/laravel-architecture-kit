<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Container\Container;
use RuntimeException;

/** The process entry point pins one session; individual files may explicitly supply another. */
final class AnalysisRuntime
{
    private static ?AnalysisSession $current = null;

    public static function activate(?AnalysisSession $session): void
    {
        self::$current = $session;
    }

    public static function session(): AnalysisSession
    {
        if (self::$current !== null) {
            return self::$current;
        }
        if (($context = RepositoryContext::current()) !== null) {
            return $context->analysis();
        }
        if (function_exists('app') && Container::getInstance()->bound(AnalysisSession::class)) {
            return app(AnalysisSession::class);
        }

        throw new RuntimeException('No analysis session is selected. Supply the backend and project PHP version explicitly.');
    }
}
