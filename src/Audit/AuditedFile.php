<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use RuntimeException;

/** One source's local checks and graph contribution, in source order. */
final readonly class AuditedFile
{
    /** @param list<AuditFinding> $findings
     * @param  list<array<string, mixed>>  $classification
     */
    public function __construct(public FileGraphEntry $entry, public array $findings, public string $inlineSource, public array $classification) {}

    /** @return array<string, mixed> */
    public function toArray(string $path): array
    {
        return ['graph' => (new CachedGraph(new GraphCacheSignature('worker', []), [$path => $this->entry]))->toArray(),
            'findings' => array_map(static fn (AuditFinding $finding): array => get_object_vars($finding), $this->findings),
            'inline' => $this->inlineSource, 'classification' => $this->classification];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $path, array $data): self
    {
        $graph = CachedGraph::fromArray($data['graph'] ?? []);
        if ($graph === null || array_keys($graph->entries) !== [$path] || ! is_array($data['findings'] ?? null) || ! is_string($data['inline'] ?? null) || ! is_array($data['classification'] ?? null)) {
            throw new RuntimeException('Invalid audit worker contribution.');
        }
        $findings = [];
        foreach ($data['findings'] as $finding) {
            if (! is_array($finding)) {
                throw new RuntimeException('Invalid audit worker finding.');
            }
            $findings[] = new AuditFinding(...$finding);
        }

        return new self($graph->entries[$path], $findings, $data['inline'], $data['classification']);
    }
}
