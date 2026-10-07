<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

final readonly class CatalogDiagnostic
{
    public function __construct(public string $code, public string $message, public int $line, public ?string $subject = null, public ?string $limitReason = null)
    {
        if ($code === '' || $message === '' || $line < 1) {
            throw new InvalidArgumentException('Invalid catalog diagnostic.');
        }
        if ($limitReason !== null && (! in_array($limitReason, ['structure', 'memory'], true) || ! str_ends_with($code, '_limit'))) {
            throw new InvalidArgumentException('Invalid catalog limit reason.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'line' => $this->line, 'subject' => $this->subject,
            ...($this->limitReason === null ? [] : ['limit_reason' => $this->limitReason])];
    }

    public static function fromArray(mixed $value): self
    {
        if (! is_array($value) || ! in_array(array_keys($value), [['code', 'message', 'line', 'subject'], ['code', 'message', 'line', 'subject', 'limit_reason']], true)) {
            throw new InvalidArgumentException('Invalid catalog diagnostic record.');
        }

        return new self($value['code'], $value['message'], $value['line'], $value['subject'], $value['limit_reason'] ?? null);
    }
}
