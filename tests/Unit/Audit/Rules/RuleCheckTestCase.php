<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\EloquentLifecycleRule;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\SaloonRule;
use Taqie\ArchitectureKit\Tests\TestCase;

abstract class RuleCheckTestCase extends TestCase
{
    /**
     * @return array<int, AuditFinding>
     */
    protected function eloquentLifecycleFindings(string $path, string $contents): array
    {
        return (new EloquentLifecycleRule(new Filesystem, $this->tempPath))->check(new FileContext($path, $contents));
    }

    /**
     * @return array<int, AuditFinding>
     */
    protected function saloonFindings(string $path, string $contents): array
    {
        return (new SaloonRule(new Filesystem, $this->tempPath))->check(new FileContext($path, $contents));
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     */
    protected function assertHasFinding(array $findings, string $rule, string $severity, int $line, string $messageFragment): void
    {
        $this->assertTrue(
            collect($findings)->contains(
                fn (AuditFinding $finding): bool => $finding->rule === $rule
                    && $finding->severity === $severity
                    && $finding->line === $line
                    && str_contains($finding->message, $messageFragment),
            ),
            "Missing {$severity} {$rule} finding on line {$line} containing [{$messageFragment}].",
        );
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     */
    protected function assertDoesNotHaveFinding(array $findings, string $rule, string $messageFragment): void
    {
        $this->assertFalse(
            collect($findings)->contains(
                fn (AuditFinding $finding): bool => $finding->rule === $rule
                    && str_contains($finding->message, $messageFragment),
            ),
            "Unexpected {$rule} finding containing [{$messageFragment}].",
        );
    }

    protected function lineOf(string $contents, string $needle): int
    {
        foreach (explode("\n", $contents) as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        $this->fail("Needle [{$needle}] not found.");
    }
}
