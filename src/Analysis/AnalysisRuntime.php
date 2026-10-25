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

    public static function stage(string $stage): void
    {
        // Stage observation is optional even for pure in-memory catalog composition.
        if (self::$current !== null) {
            self::$current->observeStage($stage);
        } elseif (($context = RepositoryContext::current()) !== null) {
            $context->analysis()->observeStage($stage);
        } elseif (function_exists('app') && Container::getInstance()->bound(AnalysisSession::class)) {
            app(AnalysisSession::class)->observeStage($stage);
        }
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
