<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Guard;

use Taqie\ArchitectureKit\Audit\ApplicationAuditResult;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Doctor\ArchitectureDoctorCheck;
use Taqie\ArchitectureKit\Doctor\ArchitectureDoctorResult;

final readonly class ArchitectureGuardResult
{
    public function __construct(
        public ArchitectureDoctorResult $doctor,
        public ?ApplicationAuditResult $audit,
        public bool $strict,
    ) {}

    public function ok(): bool
    {
        return $this->doctor->ok()
            && $this->audit !== null
            && $this->audit->errors() === 0
            && (! $this->strict || $this->audit->warnings() === 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'doctor' => $this->doctor->toArray(),
            'agents' => [
                'ok' => $this->agentsOk(),
                'checks' => array_map(
                    fn (ArchitectureDoctorCheck $check): array => $check->toArray(),
                    array_values(array_filter(
                        $this->doctor->checks,
                        fn (ArchitectureDoctorCheck $check): bool => $check->area === 'agents',
                    )),
                ),
            ],
            'audit' => $this->audit === null
                ? [
                    'ok' => false,
                    'scope' => null,
                    'errors' => 0,
                    'warnings' => 0,
                    'suppressed' => [
                        'inline' => 0,
                        'baseline' => 0,
                    ],
                    'findings' => [],
                    'skipped' => true,
                ]
                : [
                    'ok' => $this->auditOk(),
                    'scope' => $this->audit->scope,
                    'errors' => $this->audit->errors(),
                    'warnings' => $this->audit->warnings(),
                    'suppressed' => [
                        'inline' => $this->audit->suppressedInline,
                        'baseline' => $this->audit->suppressedBaseline,
                    ],
                    'findings' => array_map(
                        fn (AuditFinding $finding): array => [
                            'severity' => $finding->severity,
                            'rule' => $finding->rule,
                            'path' => $finding->path,
                            'line' => $finding->line,
                            'message' => $finding->message,
                            ...($finding->occurrence !== null ? ['occurrence' => $finding->occurrence] : []),
                        ],
                        $this->audit->findings,
                    ),
                    'skipped' => false,
                ],
        ];
    }

    private function auditOk(): bool
    {
        return $this->audit !== null
            && $this->audit->errors() === 0
            && (! $this->strict || $this->audit->warnings() === 0);
    }

    private function agentsOk(): bool
    {
        foreach ($this->doctor->checks as $check) {
            if ($check->area === 'agents' && $check->failed()) {
                return false;
            }
        }

        return true;
    }
}
