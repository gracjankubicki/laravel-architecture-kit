<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

final readonly class ArchitectureGuardResult
{
    public function __construct(
        public ArchitectureDoctorResult $doctor,
        public ?ApplicationAuditResult $audit,
        public bool $strict,
    ) {
    }

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
                    'findings' => [],
                    'skipped' => true,
                ]
                : [
                    'ok' => $this->auditOk(),
                    'scope' => $this->audit->scope,
                    'errors' => $this->audit->errors(),
                    'warnings' => $this->audit->warnings(),
                    'findings' => array_map(
                        fn (AuditFinding $finding): array => [
                            'severity' => $finding->severity,
                            'rule' => $finding->rule,
                            'path' => $finding->path,
                            'line' => $finding->line,
                            'message' => $finding->message,
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
