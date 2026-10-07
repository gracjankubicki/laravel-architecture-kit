<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Middleware and terminal failure are separate conditional branches of queued execution. */
final class CatalogJobLifecycleResolver
{
    private int $visits = 0;

    /** @var array<string, bool>|null */
    private ?array $syncDispatches = null;

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array<string, mixed> $edge
     * @param  array<string, mixed>  $facts
     */
    public function resolve(string $from, array $edge, array $facts, ExecutionLinks $links): void
    {
        $job = $edge['job'] ?? null;
        if (! is_string($job)) {
            return;
        }
        if ($this->syncDispatches === null) {
            $this->syncDispatches = [];
            foreach ($facts['operations'] as $operation) {
                if ($operation['kind'] === 'dispatch' && in_array(strtolower($operation['method']), ['dispatchsync', 'dispatch_sync'], true)) {
                    $this->syncDispatches[$this->site($operation['source'])] = true;
                }
            }
        }
        $syncQueue = isset($this->syncDispatches[$this->site($edge)]) && $links->inherits($job, 'Illuminate\\Contracts\\Queue\\ShouldQueue')
            && ($links->inherits($job, 'Illuminate\\Bus\\Queueable') || $links->inherits($job, 'Illuminate\\Foundation\\Queue\\Queueable') || $links->method($job, 'onConnection') !== null);
        if (($edge['mode'] ?? null) !== 'queue-requested' && ! $syncQueue) {
            return;
        }
        if ($syncQueue) {
            $edge['conditions'][] = 'dispatchSync uses the synchronous queue transport when the queue resolver and onConnection are available.';
        }
        if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($edge, 'Queued job lifecycle composition reached its dispatch or memory budget.');

            return;
        }
        $failed = $links->method($job, 'failed');
        if ($failed !== null) {
            $this->method($from, $failed, 'job-failed', $edge, ['The queued job reaches terminal failure; exceptions, retries and explicit fail calls are not evaluated.']);
        }
        $middleware = $links->method($job, 'middleware');
        if ($middleware !== null) {
            $this->method($from, $middleware, 'job-middleware-declaration', $edge, ['Queue transport reaches middleware construction before the job handler.']);
            if ($middleware['returns'] === []) {
                $this->notice($edge, 'Queued job middleware return is unresolved.');
            }
            foreach ($middleware['returns'] as $returned) {
                $values = is_array($returned) && array_is_list($returned) ? $returned : [$returned];
                foreach ($values as $position => $value) {
                    $this->middleware($from, $value, $position, $middleware['source'], $edge, $links);
                }
            }
        }
        $properties = $this->properties($job, $facts['classes']);
        foreach ($properties as $property) {
            foreach (is_array($property['value']) && array_is_list($property['value']) ? $property['value'] : [$property['value']] as $position => $value) {
                $this->middleware($from, $value, $position, $property['source'], $edge, $links);
            }
        }
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $dispatch
     */
    private function middleware(string $from, mixed $value, int $position, array $source, array $dispatch, ExecutionLinks $links): void
    {
        $type = is_string($value) ? $value : (is_array($value) && in_array($value['type'] ?? null, ['object', 'class'], true) ? ($value['class'] ?? null) : null);
        if (! is_string($type) || ++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($source, 'Queued job middleware is dynamic or exceeds the composition budget.');

            return;
        }
        $method = $links->method($type, 'handle');
        if ($method === null) {
            $this->notice($source, 'Queued job middleware handle is absent, external or ambiguous in source.');

            return;
        }
        $this->method($from, $method, 'job-middleware', $dispatch, ['Queue transport reaches this middleware; earlier middleware may release, delete or stop the job.'], ['registration' => $source, 'position' => $position]);
    }

    /** @param array<string, mixed> $method
     * @param  array<string, mixed>  $dispatch
     * @param  list<string>  $conditions
     * @param  array<string, mixed>  $metadata
     */
    private function method(string $from, array $method, string $kind, array $dispatch, array $conditions, array $metadata = []): void
    {
        $ids = $this->index->names[strtolower($method['symbol'])] ?? [];
        if (! $method['public'] || $method['abstract'] || count($ids) !== 1) {
            $this->notice($dispatch, 'Queued job lifecycle method is not a public unambiguous source implementation.');

            return;
        }
        $source = $method['source'];
        $this->index->addRelation(['from' => $from, 'to' => $ids[0], 'kind' => $kind, 'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'],
            'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => [...$metadata, 'job' => $dispatch['job'], 'mode' => $dispatch['mode'],
                'dispatch' => ['path' => $dispatch['path'], 'line' => $dispatch['line']], 'conditions' => [...$dispatch['conditions'], ...$conditions], 'execution_proven' => false]]);
    }

    /** @param array<string, array<string, mixed>> $classes
     * @param  array<string, bool>  $seen
     * @return list<array<string, mixed>>
     */
    private function properties(string $name, array $classes, array $seen = []): array
    {
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return [];
        }
        $seen[$key] = true;
        $class = $classes[$key] ?? null;
        if ($class === null || ($class['ambiguous'] ?? false)) {
            return [];
        }
        if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($class, 'Queued job middleware inheritance reached its traversal or memory budget.');

            return [];
        }
        if (array_key_exists('middleware', $class['properties'])) {
            $ids = $this->index->names[strtolower($name.'::$middleware')] ?? [];
            $declaration = count($ids) === 1 ? $this->index->elements[$ids[0]] : $class;

            return [['value' => $class['properties']['middleware'], 'source' => ['path' => $declaration['path'], 'line' => $declaration['line']]]];
        }
        $result = [];
        foreach ([...$class['traits'], ...$class['parents']] as $parent) {
            array_push($result, ...$this->properties($parent, $classes, $seen));
        }

        return $result;
    }

    /** @param array<string, mixed> $source */
    private function site(array $source): string
    {
        return $source['path'].':'.$source['line'].':'.($source['offset'] ?? -1);
    }

    /** @param array<string, mixed> $source */
    private function notice(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'job_lifecycle_analysis', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => null];
    }
}
