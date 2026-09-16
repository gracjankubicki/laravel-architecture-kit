<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use Throwable;

/**
 * A stored graph, kept per file so part of it can be reused.
 *
 * Entries are plain arrays rather than serialized objects. Restoring an object graph
 * would make the stored file a description of this package's classes, so a renamed
 * property would either fail loudly on read or, worse, restore an object in a shape the
 * current code no longer means.
 *
 * Paths are not stored inside the symbols and edges: an entry is keyed by its path, and
 * everything in it belongs to that file by definition. Writing the path 210 thousand
 * times would be the only way for an entry to contradict its own key, and it would also
 * be about a fifth of the file.
 */
final readonly class CachedGraph
{
    /**
     * @param  array<string, FileGraphEntry>  $entries  Project-relative path to its contribution.
     */
    public function __construct(
        public GraphCacheSignature $signature,
        public array $entries,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entries = [];

        foreach ($this->entries as $path => $entry) {
            $symbols = [];
            $edges = [];

            foreach ($entry->symbols as $symbol) {
                $symbols[] = [
                    $symbol->name,
                    $symbol->line,
                    $symbol->namespace,
                    $symbol->kind,
                    $symbol->role,
                    $symbol->hasMethods,
                ];
            }

            foreach ($entry->edges as $edge) {
                $edges[] = [
                    $edge->from,
                    $edge->to,
                    $edge->line,
                    $edge->kind,
                    $edge->strong,
                ];
            }

            $entries[$path] = ['s' => $symbols, 'e' => $edges, 't' => array_map(fn ($invocation) => $invocation->toArray(), $entry->testInvocations)];
        }

        return [
            'signature' => $this->signature->toArray(),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $signature = is_array($data['signature'] ?? null)
            ? GraphCacheSignature::fromArray($data['signature'])
            : null;
        $stored = $data['entries'] ?? null;

        if ($signature === null || ! is_array($stored)) {
            return null;
        }

        $entries = [];

        try {
            foreach ($stored as $path => $entry) {
                if (! is_string($path) || ! is_array($entry) || ! is_array($entry['s'] ?? null) || ! is_array($entry['e'] ?? null) || ! is_array($entry['t'] ?? null) || ! array_is_list($entry['t'])) {
                    return null;
                }

                $symbols = [];
                $edges = [];

                foreach ($entry['s'] as $symbol) {
                    if (! is_array($symbol) || count($symbol) !== 6) {
                        return null;
                    }

                    // The constructors are typed, so a value of the wrong type raises
                    // rather than producing a symbol that lies about itself. The path
                    // comes from the key, so it cannot disagree with it.
                    [$name, $line, $namespace, $kind, $role, $hasMethods] = array_values($symbol);
                    $symbols[] = new ProjectSymbol($name, $path, $line, $namespace, $kind, $role, $hasMethods);
                }

                foreach ($entry['e'] as $edge) {
                    if (! is_array($edge) || count($edge) !== 5) {
                        return null;
                    }

                    [$from, $to, $line, $kind, $strong] = array_values($edge);
                    $edges[] = new DependencyEdge($from, $to, $path, $line, $kind, $strong);
                }

                $entries[$path] = new FileGraphEntry($symbols, $edges, array_map(fn ($value) => TestInvocation::fromArray($path, $value), $entry['t']));
            }
        } catch (Throwable) {
            // A partially written or hand-edited file is not worth diagnosing: it is
            // treated as no cache at all, and the caller rebuilds.
            return null;
        }

        return new self($signature, $entries);
    }
}
