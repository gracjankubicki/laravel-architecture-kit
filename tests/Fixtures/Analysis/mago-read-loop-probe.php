<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Support {
    /** Observes real reads/sleeps. Only the child fixture's first partial frame waits for a read acknowledgement. */
    final class MagoReadLoopProbe
    {
        public static bool $active = false;

        public static int $checks = 0;

        public static int $progressChecks = 0;

        public static int $sleepWithProgress = 0;

        public static array $idleSleepDurations = [];

        private static int $previousBytes = 0;

        private static bool $lastReadProgress = false;

        private static string $acknowledgement;

        public static function begin(string $acknowledgement): void
        {
            self::$checks = self::$progressChecks = self::$sleepWithProgress = self::$previousBytes = 0;
            self::$lastReadProgress = false;
            self::$idleSleepDurations = [];
            self::$acknowledgement = $acknowledgement;
            self::$active = true;
        }

        public static function read(string $output): void
        {
            self::$checks++;
            self::$lastReadProgress = strlen($output) > self::$previousBytes;
            self::$previousBytes = strlen($output);
            if (self::$lastReadProgress) {
                self::$progressChecks++;
                if (! is_file(self::$acknowledgement)) {
                    file_put_contents(self::$acknowledgement, 'partial response observed');
                }
            }
        }

        public static function sleep(int $microseconds): void
        {
            if (self::$lastReadProgress) {
                self::$sleepWithProgress++;
            } else {
                self::$idleSleepDurations[] = $microseconds;
                file_put_contents(self::$acknowledgement.'.idle', 'idle wait observed');
            }
        }
    }
}

namespace GracjanKubicki\ArchitectureKit\Analysis {
    use GracjanKubicki\ArchitectureKit\Tests\Support\MagoReadLoopProbe;

    function str_contains(string $haystack, string $needle): bool
    {
        if (MagoReadLoopProbe::$active && $needle === "\n") {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
            if (($caller['class'] ?? null) === MagoAnalysisEngine::class && ($caller['function'] ?? null) === 'request') {
                MagoReadLoopProbe::read($haystack);
            }
        }

        return \str_contains($haystack, $needle);
    }

    function usleep(int $microseconds): void
    {
        if (MagoReadLoopProbe::$active) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
            if (($caller['class'] ?? null) === MagoAnalysisEngine::class && ($caller['function'] ?? null) === 'request') {
                MagoReadLoopProbe::sleep($microseconds);
            }
        }
        \usleep($microseconds);
    }
}
