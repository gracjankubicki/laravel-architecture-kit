<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/**
 * A non-blocking placement proposal derived from observed endpoint behaviour.
 */
final readonly class AuditSuggestion
{
    /**
     * @param  list<string>  $trace
     * @param  array<string, mixed>|null  $route
     */
    public function __construct(
        public string $code,
        public string $architecture,
        public bool $enabled,
        public string $path,
        public int $line,
        public string $message,
        public string $reason,
        public array $trace = [],
        public ?array $route = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'architecture' => $this->architecture,
            'enabled' => $this->enabled,
            'path' => $this->path,
            'line' => $this->line,
            'message' => $this->message,
            'reason' => $this->reason,
            'trace' => $this->trace,
            'route' => $this->route,
        ];
    }
}
