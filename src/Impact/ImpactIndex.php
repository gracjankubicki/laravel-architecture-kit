<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;

/** Query-local indices over cached facts. Class and method channels stay separate. */
final class ImpactIndex
{
    /** @var array<string, array<string, mixed>> */
    public array $classes = [];

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $notices = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $incoming = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $outgoing = [];

    /** @var array<string, list<string>> */
    private array $descendants = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $unresolvedByFrom = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $unknownByMethod = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $declarations = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $sites = [];

    private int $edgeCount = 0;

    private bool $limited = false;

    public function __construct(ProjectGraphSnapshot $graph, bool $buildCalls = true)
    {
        foreach ($graph->impactFacts as $facts) {
            foreach ($facts->classes as $name => $class) {
                if (! $this->headroom()) {
                    $this->noticeLimit();
                    break 2;
                }
                $this->classes[strtolower($name)] = [...$class, 'name' => $name, 'path' => $facts->path];
            }
            foreach ($buildCalls ? $facts->calls : [] as $call) {
                if (! $this->headroom()) {
                    $this->noticeLimit();
                    break 2;
                }
                $this->calls[] = [...$call, 'path' => $facts->path];
            }
            foreach ($facts->notices as $notice) {
                $this->notices[] = [...$notice, 'path' => $facts->path];
            }
        }
        if (! $buildCalls) {
            return;
        }
        // Build subtype lookup once. A call must not scan every class in the project.
        foreach ($this->classes as $class) {
            if (! $this->headroom()) {
                $this->noticeLimit();
                break;
            }
            foreach ($this->ancestors($class['name']) as $ancestor) {
                $this->descendants[strtolower($ancestor)][] = $class['name'];
            }
        }
        foreach ($this->calls as $call) {
            if (! $this->headroom()) {
                $this->noticeLimit();
                break;
            }
            $receiver = $this->receiver($call['receiver']);
            if ($receiver === null || $call['method'] === null) {
                $this->unknownByMethod[strtolower($call['method'] ?? '*')][] = $this->unresolvedCall($call, $receiver);

                continue;
            }
            $declared = $this->method($receiver, $call['method']);
            if ($declared !== null) {
                $this->edge($call, $declared['symbol'], 'resolved');
            } elseif ($call['method'] !== '__construct' || ! isset($this->classes[strtolower($receiver)])) {
                $this->unresolvedByFrom[strtolower($call['from'])][] = $this->unresolvedCall($call, $receiver);
            }
            if (! $call['exact'] && ! ($declared['final'] ?? false)) {
                $candidates = $this->descendants[strtolower($receiver)] ?? [];
                if (count($candidates) > 1000) {
                    $this->noticeLimit();
                }
                foreach (array_slice($candidates, 0, 1000) as $subtype) {
                    $found = $this->method($subtype, $call['method']);
                    if ($found !== null && ($declared === null || strcasecmp($found['symbol'], $declared['symbol']) !== 0)) {
                        $this->edge($call, $found['symbol'], 'possible');
                    }
                }
            }
        }
    }

    private function receiver(?string $type): ?string
    {
        if ($type === null || ! str_starts_with($type, '@property:')) {
            return $type;
        }
        [$class, $property] = explode('#', substr($type, 10), 2);

        return $this->property($class, $property);
    }

    private function property(string $name, string $property, int $depth = 0): ?string
    {
        $class = $this->classes[strtolower($name)] ?? null;
        if ($depth >= 12 && $class !== null) {
            $this->hierarchyLimit($class);
        }
        if ($class === null || $depth >= 12 || $class['adaptations']) {
            return null;
        }
        if (isset($class['properties'][$property])) {
            return $class['properties'][$property];
        }
        foreach ([...$class['traits'], ...$class['parents']] as $parent) {
            $found = $this->property($parent, $property, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, bool> $seen
     * @return list<string> */
    public function ancestors(string $name, array $seen = [], int $depth = 0): array
    {
        $key = strtolower($name);
        $class = $this->classes[$key] ?? null;
        if ($depth >= 12 && $class !== null && ! isset($seen[$key])) {
            $this->hierarchyLimit($class);
        }
        if ($class === null || isset($seen[$key]) || $depth >= 12) {
            return [];
        }
        $seen[$key] = true;
        $result = [];
        foreach ([...$class['parents'], ...$class['traits']] as $parent) {
            $result[] = $parent;
            array_push($result, ...$this->ancestors($parent, $seen, $depth + 1));
        }

        return array_values(array_unique($result));
    }

    /** @return array<string, mixed>|null */
    public function method(string $name, string $method): ?array
    {
        $key = strtolower($name.'::'.$method);
        if (! array_key_exists($key, $this->declarations)) {
            $this->declarations[$key] = $this->findMethod($name, $method);
        }

        return $this->declarations[$key];
    }

    /** @param array<string, bool> $seen
     * @return array<string, mixed>|null */
    private function findMethod(string $name, string $method, array $seen = [], int $depth = 0): ?array
    {
        $key = strtolower($name);
        $class = $this->classes[$key] ?? null;
        if ($depth >= 12 && $class !== null && ! isset($seen[$key])) {
            $this->hierarchyLimit($class);
        }
        if ($class === null || isset($seen[$key]) || $depth >= 12) {
            return null;
        }
        $seen[$key] = true;
        $own = $class['methods'][strtolower($method)] ?? null;
        if ($own !== null) {
            return ['name' => $own['name'], 'line' => $own['line'], 'final' => $own['final'], 'symbol' => $class['name'].'::'.$own['name'], 'path' => $class['path'], 'class' => $class['name']];
        }
        if ($class['adaptations']) {
            return null;
        }
        $traitMethods = [];
        foreach ($class['traits'] as $trait) {
            $found = $this->findMethod($trait, $method, $seen, $depth + 1);
            if ($found !== null) {
                $traitMethods[$found['symbol']] = $found;
            }
        }
        if (count($traitMethods) > 1) {
            return null;
        }
        if ($traitMethods !== []) {
            return array_values($traitMethods)[0];
        }
        foreach ($class['parents'] as $parent) {
            $found = $this->findMethod($parent, $method, $seen, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $call */
    private function edge(array $call, string $to, string $certainty): void
    {
        if (++$this->edgeCount > 50000 || ! $this->headroom()) {
            $this->noticeLimit();

            return;
        }
        $edge = ['from' => $call['from'], 'to' => $to, 'kind' => $call['kind'], 'certainty' => $certainty,
            'path' => $call['path'], 'line' => $call['line'], 'receiver' => $this->receiver($call['receiver'])];
        $this->sites[$call['path'].'|'.$call['from'].'|'.$call['line'].'|'.strtolower($to)][] = $call['site'];
        $this->incoming[strtolower($to)][] = $edge;
        $this->outgoing[strtolower($call['from'])][] = $edge;
    }

    /** Signature analysis uses call evidence without adding fields to legacy edges.
     * @return iterable<array<string, mixed>> */
    public function signatureCalls(string $symbol): iterable
    {
        $offsets = [];
        foreach ($this->edges($symbol, false) as $edge) {
            $key = $edge['path'].'|'.$edge['from'].'|'.$edge['line'].'|'.strtolower($symbol);
            $offset = $offsets[$key] ?? 0;
            $offsets[$key] = $offset + 1;
            yield [...$edge, 'site' => $this->sites[$key][$offset]];
        }
    }

    public function limitReached(): bool
    {
        return $this->limited || count(array_filter($this->notices, static fn (array $notice): bool => str_contains(strtolower($notice['reason']), 'limit'))) > 0;
    }

    private function headroom(): bool
    {
        $limit = MemoryLimit::bytes();

        return $limit === null || memory_get_usage(true) + 65536 < $limit * 0.8;
    }

    /** @param array<string, mixed> $class */
    private function hierarchyLimit(array $class): void
    {
        $notice = ['path' => $class['path'], 'line' => $class['line'], 'reason' => 'Impact hierarchy depth limit (12) reached at '.$class['name'].'. Inspect this boundary class.'];
        if (! in_array($notice, $this->notices, true)) {
            $this->notices[] = $notice;
        }
    }

    private function noticeLimit(): void
    {
        if (! $this->limited) {
            $this->notices[] = ['path' => '(project)', 'line' => 1, 'reason' => 'Impact dispatch index limit reached.'];
            $this->limited = true;
        }
    }

    /** @return list<array<string, mixed>> */
    public function edges(string $symbol, bool $outgoing): array
    {
        return $outgoing ? ($this->outgoing[strtolower($symbol)] ?? []) : ($this->incoming[strtolower($symbol)] ?? []);
    }

    /** @param array<string, mixed> $call
     * @return array<string, mixed> */
    private function unresolvedCall(array $call, ?string $receiver): array
    {
        return ['path' => $call['path'], 'line' => $call['line'], 'from' => $call['from'], 'reason' => 'Unresolved receiver, dynamic method, unsupported trait dispatch or out-of-scope target.', 'receiver' => $receiver, 'method' => $call['method']];
    }

    /** @return list<array<string, mixed>> */
    public function unresolved(string $symbol, ?string $method, bool $includeGlobal = true): array
    {
        $unknown = $method === null ? array_merge([], ...array_values($this->unknownByMethod))
            : [...($this->unknownByMethod[strtolower($method)] ?? []), ...($this->unknownByMethod['*'] ?? [])];

        return [...($includeGlobal ? $this->notices : []), ...($this->unresolvedByFrom[strtolower($symbol)] ?? []), ...$unknown];
    }

    /** @return list<array<string, mixed>> */
    public function overrides(string $class, string $method, string $declaration): array
    {
        $result = [];
        foreach ($this->descendants[strtolower($class)] ?? [] as $name) {
            $found = $this->method($name, $method);
            if ($found !== null && strcasecmp($found['symbol'], $declaration) !== 0) {
                $result[$found['symbol']] = $found;
            }
        }

        return array_values($result);
    }
}
