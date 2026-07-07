<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Shared;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

final readonly class UnenabledPatternRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function __construct(private array $enabled) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Responses/')
            || str_starts_with($path, 'app/Services/');
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $findings = [];

        if (str_starts_with($file->path, 'app/Http/Responses/')) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Http Responses are not an enabled Architecture Kit pattern.');
        }

        if (
            ! in_array(Architecture::Services, $this->enabled, true)
            && str_starts_with($file->path, 'app/Services/')
        ) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Services are not enabled; prefer an enabled architecture boundary.');
        }

        return $findings;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'unenabled-pattern', $path, $line, $message);
    }
}
