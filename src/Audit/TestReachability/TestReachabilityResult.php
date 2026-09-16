<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;

final class TestReachabilityResult
{
    /** @var array<string, true> */
    public array $symbols = [];

    /** @var array<string, list<string>> */
    public array $provenance = [];

    /** @var array<string, AuditFinding> */
    public array $diagnostics = [];

    /** @var array<string, array<string, true>> Test and consulted source paths that can affect a diagnostic. */
    public array $diagnosticOrigins = [];

    /** @param list<string> $trace */
    public function reach(string $class, array $trace): void
    {
        $this->symbols[strtolower($class)] = true;
        $this->provenance[strtolower($class)] ??= $trace;
    }

    /** @param list<string> $originPaths */
    public function incomplete(string $path, int $line, string $reason, array $originPaths = []): void
    {
        $key = $path.'|'.$line.'|'.$reason;
        $this->diagnosticOrigins[$key] ??= [];
        $this->diagnosticOrigins[$key] += array_fill_keys($originPaths, true);
        $this->diagnostics[$key] = new AuditFinding('warn', 'missing-test', $path, $line, 'Test relationship analysis is incomplete: '.$reason, code: 'W_MISSING_TEST_ANALYSIS_INCOMPLETE');
    }

    /** @param list<string> $originPaths */
    public function merge(self $other, array $originPaths = []): void
    {
        $this->symbols += $other->symbols;
        $this->provenance += $other->provenance;
        $this->diagnostics += $other->diagnostics;
        foreach ($other->diagnostics as $key => $_) {
            $this->diagnosticOrigins[$key] ??= [];
            $this->diagnosticOrigins[$key] += $other->diagnosticOrigins[$key] ?? [];
            $this->diagnosticOrigins[$key] += array_fill_keys($originPaths, true);
        }
    }
}
