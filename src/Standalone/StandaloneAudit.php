<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

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

    public static function run(RepositoryContext $context, bool $changed = false, ?string $base = null): ApplicationAuditResult
    {
        $state = self::state($context);

        // Never use a persistent suppression baseline as a substitute for the task's actual beginning.
        return (new ApplicationAudit(new Filesystem, $context->rootPath))->run(
            enabled: $state->enabled, changedOnly: $changed, baseRef: $base,
            exclude: $state->exclude, customRules: $state->customRules, useBaseline: false,
            scope: $state->auditScope, missingTestLevel: $state->missingTestLevel,
            cache: $state->graphCache, cacheConfiguration: [...$state->graphConfiguration(), $context->fingerprint()],
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
        if ($result->analysisStatus === 'incomplete' || $result->notices !== []) {
            return true;
        }
        foreach ($result->findings as $finding) {
            if ($finding->code === 'W_MISSING_TEST_ANALYSIS_INCOMPLETE') {
                return true;
            }
        }

        return false;
    }

    public static function guard(RepositoryContext $context, bool $changed, ?string $base, bool $strict, bool $target): ArchitectureGuardResult
    {
        $result = self::run($context, $changed, $base);
        $doctor = self::doctor($context);
        if (self::incomplete($result)) {
            $doctor = new ArchitectureDoctorResult($doctor->enabled, [...$doctor->checks,
                new ArchitectureDoctorCheck('config', 'blocked', $context->rootPath, 'Required analysis is incomplete. Inspect notices; this is not PASS.'),
            ], false);
        }

        return new ArchitectureGuardResult($doctor, $result, $strict,
            $target ? (new ArchitectureTarget($context->rootPath))->inspect() : null);
    }
}
