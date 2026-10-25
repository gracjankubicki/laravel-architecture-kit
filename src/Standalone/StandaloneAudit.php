<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorCheck;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuardResult;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Target\ArchitectureTarget;
use Illuminate\Filesystem\Filesystem;

final class StandaloneAudit
{
    public static function state(RepositoryContext $context): ProjectState
    {
        return ProjectState::load(new Filesystem, dirname(__DIR__, 2), $context->rootPath);
    }

    public static function run(RepositoryContext $context, bool $changed = false, ?string $base = null, AnalysisMode $mode = AnalysisMode::Basic, ?string $task = null): ApplicationAuditResult
    {
        $context->assertAnalysisMode($mode, $task);
        $state = self::state($context);

        // Never use a persistent suppression baseline as a substitute for the task's actual beginning.
        return (new ApplicationAudit(new Filesystem, $context->rootPath))->run(
            enabled: $state->enabled, changedOnly: $changed, baseRef: $base,
            exclude: $state->exclude, customRules: $state->customRules, useBaseline: false,
            scope: $state->auditScope, missingTestLevel: $state->missingTestLevel,
            cache: $state->graphCache, cacheConfiguration: [...$state->graphConfiguration(), $context->fingerprint()], mode: $mode,
        );
    }

    public static function doctor(RepositoryContext $context): ArchitectureDoctorResult
    {
        $state = self::state($context);
        $state->assertCompatibility();

        return new ArchitectureDoctorResult($state->enabled, [new ArchitectureDoctorCheck(
            'config', 'current', $context->configSource,
            'Standalone configuration. Application installation and runtime probes are not required.',
        )], false, $state->laravelAi, $state->inertia, $state->fortify);
    }

    public static function incomplete(ApplicationAuditResult $result): bool
    {
        return $result->incomplete();
    }

    public static function guard(RepositoryContext $context, bool $changed, ?string $base, bool $strict, bool $target, ?ControlPolicy $policy = null): ArchitectureGuardResult
    {
        $result = self::run($context, $changed, $base);
        $doctor = self::doctor($context);

        return new ArchitectureGuardResult($doctor, $result, $strict,
            $target ? (new ArchitectureTarget($context->rootPath))->inspect() : null,
            self::state($context)->blockIncompleteAnalysis, $policy ?? $context->controlPolicy());
    }

    /** @return array{status: string, policy: string, notice_total: int, incomplete_findings: int, reasons: array<string, int>} */
    public static function analysisSummary(ApplicationAuditResult $audit, bool $block): array
    {
        $reasons = [];
        foreach ($audit->notices as $notice) {
            $reasons[$notice->reason] = ($reasons[$notice->reason] ?? 0) + 1;
        }
        ksort($reasons);

        return [...$audit->analysis(), 'status' => $audit->incomplete() ? 'incomplete' : ($audit->analysis()['checks_complete'] ? 'complete' : $audit->analysisStatus),
            'policy' => $block ? 'block' : 'warn', 'notice_total' => count($audit->notices),
            'incomplete_findings' => count(array_filter($audit->findings, static fn ($finding): bool => $finding->isIncompleteAnalysis())),
            'reasons' => $reasons];
    }
}
