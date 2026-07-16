<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Output;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorCheck;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuardResult;
use GracjanKubicki\ArchitectureKit\Planning\ArchitecturePlan;

final readonly class AgentOutput
{
    public function __construct(private FindingCodeRegistry $codes = new FindingCodeRegistry) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(ApplicationAuditResult $result, bool $ok, int $limit = 20, bool $full = false, bool $baselineUpdated = false): array
    {
        $payload = [
            'v' => 1,
            'ok' => $ok,
            'cmd' => 'audit',
            'scope' => $this->scope($result->scope),
            'err' => $result->errors(),
            'warn' => $result->warnings(),
            'sup' => [
                'inline' => $result->suppressedInline,
                'baseline' => $result->suppressedBaseline,
            ],
            ...($baselineUpdated ? ['baseline' => 'updated'] : []),
            ...$this->findings($result->findings, $limit, $full),
            'next' => $ok ? ['continue'] : ['fix_findings', 'rerun:audit --agent'],
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function guard(ArchitectureGuardResult $result, int $limit = 20, bool $full = false): array
    {
        $auditOk = $this->auditStatus($result);
        $findings = $result->audit === null ? $this->findings([], $limit, $full) : $this->findings($result->audit->findings, $limit, $full);

        return [
            'v' => 1,
            'ok' => $result->ok(),
            'cmd' => 'guard',
            'doctor' => $result->doctor->ok() ? 'ok' : 'fail',
            'agents' => $this->agentsOk($result->doctor) ? 'ok' : 'fail',
            'audit' => $auditOk,
            'err' => $result->audit?->errors() ?? 0,
            'warn' => $result->audit?->warnings() ?? 0,
            'sup' => [
                'inline' => $result->audit?->suppressedInline ?? 0,
                'baseline' => $result->audit?->suppressedBaseline ?? 0,
            ],
            ...$findings,
            'next' => $result->ok()
                ? ['continue']
                : ($result->audit === null ? ['run:architecture-kit:install', 'rerun:guard --agent'] : ['fix_findings', 'rerun:guard --agent']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function doctor(ArchitectureDoctorResult $result, int $limit = 20, bool $full = false): array
    {
        $issues = array_values(array_filter(
            $result->checks,
            fn (ArchitectureDoctorCheck $check): bool => $this->checkStatus($check) !== 'ok',
        ));

        $visibleIssues = $limit === 0 ? [] : array_slice($issues, 0, $limit);

        $payload = [
            'v' => 1,
            'ok' => $result->ok(),
            'cmd' => 'doctor',
            'enabled' => array_map(
                fn ($architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
                $result->enabled,
            ),
            'boost' => [
                'installed' => $result->boostInstalled,
                ...($result->boostInstalled ? ['sync' => 'php artisan boost:update --no-interaction'] : []),
            ],
            ...($result->laravelAi !== null ? ['laravel_ai' => $result->laravelAi->toArray()] : []),
            'checks' => $this->checks($result->checks),
            'trunc' => count($visibleIssues) < count($issues),
            ...($limit > 0 ? [
                'issues' => array_map(
                    fn (ArchitectureDoctorCheck $check): array => $this->issue($check, $full),
                    $visibleIssues,
                ),
            ] : []),
            'next' => $result->ok() ? ['continue'] : ['run:architecture-kit:install', 'rerun:doctor --agent'],
        ];

        if ($payload['trunc']) {
            $payload['total'] = count($issues);
            $payload['shown'] = count($visibleIssues);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function error(string $cmd, string $message): array
    {
        return [
            'v' => 1,
            'ok' => false,
            'cmd' => $cmd,
            'm' => 'E_COMMAND_FAILED',
            'msg' => $message,
            'next' => ['fix_command_error', 'rerun:'.$cmd.' --agent'],
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $changes
     * @param  array<string, mixed>|null  $profile
     * @return array<string, mixed>
     */
    public function sync(array $changes, bool $dryRun, ?array $profile = null): array
    {
        return [
            'v' => 1,
            'ok' => true,
            'cmd' => 'sync',
            'dry_run' => $dryRun,
            ...($profile !== null ? ['laravel_ai' => $profile] : []),
            'changes' => $changes,
            'next' => $dryRun ? ['rerun:sync --no-interaction'] : ['run:boost:update --no-interaction'],
        ];
    }

    /** @param array<string, mixed>|null $profile @return array<string, mixed> */
    public function syncError(string $message, ?array $profile = null): array
    {
        return [
            'v' => 1,
            'ok' => false,
            'cmd' => 'sync',
            'm' => 'E_SYNC_PREFLIGHT',
            'msg' => $message,
            ...($profile !== null ? ['laravel_ai' => $profile] : []),
            'next' => ['fix_preflight', 'rerun:sync --no-interaction'],
        ];
    }

    /** @param array<string, mixed>|null $profile @return array<string, mixed> */
    public function syncApplyError(string $message, ?array $profile = null): array
    {
        return [
            'v' => 1,
            'ok' => false,
            'cmd' => 'sync',
            'm' => 'E_SYNC_APPLY',
            'msg' => $message,
            ...($profile !== null ? ['laravel_ai' => $profile] : []),
            'next' => ['fix_filesystem', 'rerun:sync --no-interaction'],
        ];
    }

    /** @return array<string, mixed> */
    public function plan(ArchitecturePlan $plan): array
    {
        return [
            'v' => 1,
            'ok' => true,
            'cmd' => 'plan',
            'configured' => $plan->configured,
            'recommendations' => array_map(
                fn ($recommendation): array => $recommendation->toArray(),
                $plan->recommendations,
            ),
            'requirements' => $plan->requirements,
            'changes' => $plan->changes->toArray(),
            'next' => ['review_recommendations', 'run:architecture-kit:install'],
        ];
    }

    public function limit(mixed $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $command): array
    {
        return match ($command) {
            'audit' => $this->auditSchema(),
            'guard' => $this->guardSchema(),
            'doctor' => $this->doctorSchema(),
            'explain' => $this->explainSchema(),
            'guidelines' => $this->guidelinesSchema(),
            'plan' => $this->planSchema(),
            'sync' => $this->syncSchema(),
            default => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'additionalProperties' => true,
            ],
        };
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     * @return array<string, mixed>
     */
    private function findings(array $findings, int $limit, bool $full): array
    {
        $visible = $limit === 0 ? [] : array_slice($findings, 0, $limit);
        $payload = [
            'trunc' => count($visible) < count($findings),
        ];

        if ($payload['trunc']) {
            $payload['total'] = count($findings);
            $payload['shown'] = count($visible);
        }

        if ($limit > 0) {
            $payload['find'] = array_map(
                fn (AuditFinding $finding): array => $this->finding($finding, $full),
                $visible,
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(AuditFinding $finding, bool $full): array
    {
        return [
            'r' => $finding->rule,
            's' => $finding->severity === 'error' ? 'err' : 'warn',
            'p' => $finding->path,
            'l' => $finding->line,
            'm' => $this->codes->codeFor($finding),
            ...($finding->occurrence !== null ? ['n' => $finding->occurrence] : []),
            ...($full ? ['msg' => $finding->message] : []),
        ];
    }

    private function scope(string $scope): string
    {
        return str_contains($scope, 'changed') ? 'changed' : 'all';
    }

    private function auditStatus(ArchitectureGuardResult $result): string
    {
        if ($result->audit === null) {
            return 'skip';
        }

        if ($result->audit->errors() > 0 || ($result->strict && $result->audit->warnings() > 0)) {
            return 'fail';
        }

        return 'ok';
    }

    private function agentsOk(ArchitectureDoctorResult $result): bool
    {
        foreach ($result->checks as $check) {
            if ($check->area === 'agents' && $check->failed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, ArchitectureDoctorCheck>  $checks
     * @return array<string, string>
     */
    private function checks(array $checks): array
    {
        $statuses = [];

        foreach ($checks as $check) {
            $status = $this->checkStatus($check);
            $current = $statuses[$check->area] ?? 'ok';

            if ($current === 'fail' || ($current === 'warn' && $status === 'ok')) {
                continue;
            }

            $statuses[$check->area] = $status;
        }

        return $statuses;
    }

    private function checkStatus(ArchitectureDoctorCheck $check): string
    {
        if ($check->failed()) {
            return 'fail';
        }

        return $check->status === 'warning' ? 'warn' : 'ok';
    }

    /**
     * @return array<string, string>
     */
    private function issue(ArchitectureDoctorCheck $check, bool $full): array
    {
        return [
            'a' => $check->area,
            's' => $this->checkStatus($check) === 'fail' ? 'err' : 'warn',
            'p' => $check->path,
            'm' => $this->doctorCode($check),
            ...($full && $check->message !== null ? ['msg' => $check->message] : []),
        ];
    }

    private function doctorCode(ArchitectureDoctorCheck $check): string
    {
        return match ($check->area) {
            'agents' => 'E_AGENT_CONFIG_'.strtoupper($check->status),
            'baseline' => $check->status === 'warning' ? 'W_BASELINE_ORPHANED' : 'E_BASELINE_INVALID',
            'config' => 'E_CONFIG_'.$this->statusCode($check->status),
            'generated' => 'E_GENERATED_'.$this->statusCode($check->status),
            default => ($check->failed() ? 'E_' : 'W_').strtoupper(str_replace('-', '_', $check->area)).'_'.$this->statusCode($check->status),
        };
    }

    private function statusCode(string $status): string
    {
        return strtoupper(str_replace('-', '_', $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSchema(): array
    {
        $success = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit audit agent output',
            'type' => 'object',
            'required' => ['v', 'ok', 'cmd', 'scope', 'err', 'warn', 'sup', 'trunc', 'next'],
            'properties' => [
                'v' => ['const' => 1],
                'ok' => ['type' => 'boolean'],
                'cmd' => ['const' => 'audit'],
                'scope' => ['enum' => ['changed', 'all']],
                'err' => ['type' => 'integer', 'minimum' => 0],
                'warn' => ['type' => 'integer', 'minimum' => 0],
                'sup' => $this->suppressionSchema(),
                'baseline' => ['const' => 'updated'],
                ...$this->findingCollectionProperties(),
                'next' => $this->stringListSchema(),
            ],
            'additionalProperties' => false,
        ];

        return $this->successOrCommandErrorSchema('Architecture Kit audit agent output', 'audit', $success);
    }

    /**
     * @return array<string, mixed>
     */
    private function guardSchema(): array
    {
        $success = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit guard agent output',
            'type' => 'object',
            'required' => ['v', 'ok', 'cmd', 'doctor', 'agents', 'audit', 'err', 'warn', 'sup', 'trunc', 'next'],
            'properties' => [
                'v' => ['const' => 1],
                'ok' => ['type' => 'boolean'],
                'cmd' => ['const' => 'guard'],
                'doctor' => ['enum' => ['ok', 'fail']],
                'agents' => ['enum' => ['ok', 'fail']],
                'audit' => ['enum' => ['ok', 'fail', 'skip']],
                'err' => ['type' => 'integer', 'minimum' => 0],
                'warn' => ['type' => 'integer', 'minimum' => 0],
                'sup' => $this->suppressionSchema(),
                ...$this->findingCollectionProperties(),
                'next' => $this->stringListSchema(),
            ],
            'additionalProperties' => false,
        ];

        return $this->successOrCommandErrorSchema('Architecture Kit guard agent output', 'guard', $success);
    }

    /**
     * @return array<string, mixed>
     */
    private function doctorSchema(): array
    {
        $success = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit doctor agent output',
            'type' => 'object',
            'required' => ['v', 'ok', 'cmd', 'enabled', 'boost', 'checks', 'trunc', 'next'],
            'properties' => [
                'v' => ['const' => 1],
                'ok' => ['type' => 'boolean'],
                'cmd' => ['const' => 'doctor'],
                'enabled' => $this->stringListSchema(),
                'boost' => [
                    'type' => 'object',
                    'required' => ['installed'],
                    'properties' => [
                        'installed' => ['type' => 'boolean'],
                        'sync' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'laravel_ai' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => true,
                ],
                'checks' => [
                    'type' => 'object',
                    'additionalProperties' => ['enum' => ['ok', 'warn', 'fail']],
                ],
                'trunc' => ['type' => 'boolean'],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'shown' => ['type' => 'integer', 'minimum' => 0],
                'issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['a', 's', 'p', 'm'],
                        'properties' => [
                            'a' => ['type' => 'string'],
                            's' => ['enum' => ['err', 'warn']],
                            'p' => ['type' => 'string'],
                            'm' => ['type' => 'string'],
                            'msg' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'next' => $this->stringListSchema(),
            ],
            'additionalProperties' => false,
        ];

        return $this->successOrCommandErrorSchema('Architecture Kit doctor agent output', 'doctor', $success);
    }

    /**
     * @return array<string, mixed>
     */
    private function explainSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit explain agent output',
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'code', 'rule', 'severity', 'title', 'why', 'fix'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => true],
                        'cmd' => ['const' => 'explain'],
                        'code' => ['type' => 'string'],
                        'rule' => ['type' => 'string'],
                        'severity' => ['enum' => ['error', 'warn']],
                        'title' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                        'fix' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'code', 'm', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => 'explain'],
                        'code' => ['type' => 'string'],
                        'm' => ['type' => 'string'],
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'm', 'msg', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => 'explain'],
                        'm' => ['const' => 'E_COMMAND_FAILED'],
                        'msg' => ['type' => 'string'],
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guidelinesSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit guidelines agent output',
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'arch', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => true],
                        'cmd' => ['const' => 'guidelines'],
                        'laravel_ai' => ['type' => 'object', 'additionalProperties' => true],
                        'arch' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['slug', 'label', 'enabled', 'skill', 'sum'],
                                'properties' => [
                                    'slug' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'enabled' => ['type' => 'boolean'],
                                    'skill' => ['type' => 'string'],
                                    'sum' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'slug', 'label', 'enabled', 'skill', 'md'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => true],
                        'cmd' => ['const' => 'guidelines'],
                        'laravel_ai' => ['type' => 'object', 'additionalProperties' => true],
                        'slug' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'enabled' => ['type' => 'boolean'],
                        'skill' => ['type' => 'string'],
                        'md' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'slug', 'm', 'known', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => 'guidelines'],
                        'slug' => ['type' => 'string'],
                        'm' => ['const' => 'E_UNKNOWN_ARCHITECTURE'],
                        'known' => $this->stringListSchema(),
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'm', 'msg', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => 'guidelines'],
                        'm' => ['const' => 'E_COMMAND_FAILED'],
                        'msg' => ['type' => 'string'],
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function syncSchema(): array
    {
        $laravelAi = [
            'type' => 'object',
            'additionalProperties' => true,
        ];
        $changes = [
            'type' => 'object',
            'required' => ['create', 'update', 'remove'],
            'properties' => [
                'create' => $this->stringListSchema(),
                'update' => $this->stringListSchema(),
                'remove' => $this->stringListSchema(),
            ],
            'additionalProperties' => false,
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Architecture Kit sync agent output',
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'dry_run', 'changes', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => true],
                        'cmd' => ['const' => 'sync'],
                        'dry_run' => ['type' => 'boolean'],
                        'laravel_ai' => $laravelAi,
                        'changes' => $changes,
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'm', 'msg', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => 'sync'],
                        'm' => ['const' => 'E_SYNC_PREFLIGHT'],
                        'msg' => ['type' => 'string'],
                        'laravel_ai' => $laravelAi,
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'm', 'msg', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => 'sync'],
                        'm' => ['const' => 'E_SYNC_APPLY'],
                        'msg' => ['type' => 'string'],
                        'laravel_ai' => $laravelAi,
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function planSchema(): array
    {
        $success = [
            'type' => 'object',
            'required' => ['v', 'ok', 'cmd', 'configured', 'recommendations', 'requirements', 'changes', 'next'],
            'properties' => [
                'v' => ['const' => 1],
                'ok' => ['const' => true],
                'cmd' => ['const' => 'plan'],
                'configured' => ['type' => 'boolean'],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['slug', 'label', 'confidence', 'evidence', 'configured'],
                        'properties' => [
                            'slug' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'confidence' => ['enum' => ['high']],
                            'evidence' => $this->stringListSchema(),
                            'configured' => ['type' => 'boolean'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'requirements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'satisfied', 'message', 'remediation'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'satisfied' => ['type' => 'boolean'],
                            'message' => ['type' => 'string'],
                            'remediation' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'changes' => [
                    'type' => 'object',
                    'required' => ['create', 'update', 'remove', 'blocked'],
                    'properties' => [
                        'create' => $this->stringListSchema(),
                        'update' => $this->stringListSchema(),
                        'remove' => $this->stringListSchema(),
                        'blocked' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
                'next' => $this->stringListSchema(),
            ],
            'additionalProperties' => false,
        ];

        return $this->successOrCommandErrorSchema('Architecture Kit plan agent output', 'plan', $success);
    }

    /**
     * @return array<string, mixed>
     */
    private function findingCollectionProperties(): array
    {
        return [
            'trunc' => ['type' => 'boolean'],
            'total' => ['type' => 'integer', 'minimum' => 0],
            'shown' => ['type' => 'integer', 'minimum' => 0],
            'find' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['r', 's', 'p', 'l', 'm'],
                    'properties' => [
                        'r' => ['type' => 'string'],
                        's' => ['enum' => ['err', 'warn']],
                        'p' => ['type' => 'string'],
                        'l' => ['type' => 'integer', 'minimum' => 1],
                        'm' => ['type' => 'string'],
                        'n' => ['type' => 'integer', 'minimum' => 1],
                        'msg' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $success
     * @return array<string, mixed>
     */
    private function successOrCommandErrorSchema(string $title, string $command, array $success): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $title,
            'oneOf' => [
                $success,
                [
                    'type' => 'object',
                    'required' => ['v', 'ok', 'cmd', 'm', 'msg', 'next'],
                    'properties' => [
                        'v' => ['const' => 1],
                        'ok' => ['const' => false],
                        'cmd' => ['const' => $command],
                        'm' => ['type' => 'string'],
                        'msg' => ['type' => 'string'],
                        'next' => $this->stringListSchema(),
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suppressionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['inline', 'baseline'],
            'properties' => [
                'inline' => ['type' => 'integer', 'minimum' => 0],
                'baseline' => ['type' => 'integer', 'minimum' => 0],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];
    }
}
