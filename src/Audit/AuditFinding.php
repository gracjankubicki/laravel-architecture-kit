<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use InvalidArgumentException;

final readonly class AuditFinding
{
    public string $severity;

    public string $rule;

    public string $path;

    public int $line;

    public string $message;

    public ?int $occurrence;

    public ?string $code;

    public function __construct(
        string $severity,
        string $rule,
        string $path,
        int $line,
        string $message,
        ?int $occurrence = null,
        ?string $code = null,
    ) {
        if (! in_array($severity, ['error', 'warn'], true)) {
            throw new InvalidArgumentException('Architecture Kit finding severity must be error or warn.');
        }

        if (! preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $rule)) {
            throw new InvalidArgumentException('Architecture Kit finding rule must be a non-empty kebab-case slug.');
        }

        if (trim($path) === '' || trim($message) === '' || $line < 1) {
            throw new InvalidArgumentException('Architecture Kit findings require a non-empty path and message, with line >= 1.');
        }

        if ($occurrence !== null && $occurrence < 1) {
            throw new InvalidArgumentException('Architecture Kit finding occurrence must be >= 1 when present.');
        }

        if ($code !== null && ! preg_match('/^[EW]_[A-Z0-9_]+$/', $code)) {
            throw new InvalidArgumentException('Architecture Kit finding code must use the E_/W_ uppercase underscore format.');
        }

        $this->severity = $severity;
        $this->rule = $rule;
        $this->path = $path;
        $this->line = $line;
        $this->message = $message;
        $this->occurrence = $occurrence;
        $this->code = $code;
    }

    public function severityRank(): int
    {
        return match ($this->severity) {
            'error' => 0,
            'warn' => 1,
            default => 2,
        };
    }
}
