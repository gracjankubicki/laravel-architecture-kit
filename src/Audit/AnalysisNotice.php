<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/**
 * A non-blocking record of code the static analysis could not classify.
 */
final readonly class AnalysisNotice
{
    /**
     * @param  list<string>  $trace
     * @param  array<string, mixed>|null  $route
     */
    public function __construct(
        public string $code,
        public string $reason,
        public string $path,
        public int $line,
        public string $message,
        public array $trace = [],
        public ?array $route = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'reason' => $this->reason,
            'path' => $this->path,
            'line' => $this->line,
            'message' => $this->message,
            'trace' => $this->trace,
            'route' => $this->route,
        ];
    }
}
