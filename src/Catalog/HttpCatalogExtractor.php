<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/** Keeps route templates file-local; no application registration or payload is executed/stored. */
final class HttpCatalogExtractor
{
    public const SOURCE_ROOT = '/__architecture_source__';

    public function __construct(private readonly string $basePath = self::SOURCE_ROOT) {}

    public function extract(FileContext $file, CatalogFacts $php, ?CatalogFacts $calls = null): CatalogFacts
    {
        if (count($php->elements) === 1 && array_filter($php->diagnostics, fn ($diagnostic) => in_array($diagnostic->code, ['source_limit', 'parse_error', 'catalog_limit'], true)) !== []) {
            return new CatalogFacts($file->path);
        }
        $boundThisCalls = [];
        foreach ($calls->relations ?? [] as $call) {
            if ($call->kind === 'calls' && ($call->metadata['this_receiver'] ?? false) && isset($call->metadata['offset'])) {
                $boundThisCalls[$call->metadata['offset']] = true;
            }
        }
        $facts = (new HttpRouteExtractor($this->basePath, catalog: true))->extract($file, $boundThisCalls);
        if ($facts['limited']) {
            return new CatalogFacts($file->path, diagnostics: array_map(fn ($reason) => new CatalogDiagnostic('http_limit', $reason === 'memory'
                ? 'HTTP template extraction reached its memory budget.'
                : 'HTTP template extraction reached its node or context budget.', 1, limitReason: $reason), $facts['limit_reasons']));
        }
        $closures = [];
        foreach ($php->elements as $element) {
            if ($element->kind === 'closure') {
                $closures[$element->offset] = $element->id;
            }
        }
        $callbacks = [];
        foreach ((new NodeFinder)->find($file->ast() ?? [], fn (Node $node) => $node instanceof Expr\CallLike) as $call) {
            if (! $call instanceof Expr\CallLike || $call->isFirstClassCallable()) {
                continue;
            }
            foreach ($call->getArgs() as $arg) {
                if ($arg instanceof Node\Arg && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction)) {
                    $callbacks[$call->getStartFilePos()] = $closures[$arg->value->getStartFilePos()] ?? null;
                }
            }
        }
        $relations = $diagnostics = [];
        $owner = CatalogElement::identity($file->path, 'file', $file->path);
        $reportedIncludes = [];
        foreach ($php->diagnostics as $diagnostic) {
            if ($diagnostic->code === 'include_search_path') {
                $reportedIncludes[$diagnostic->line] = true;
            }
        }
        foreach ($facts['notices'] as $notice) {
            if ($notice['reason'] === 'Relative include/source resolution depends on working directory or include_path.'
                && isset($reportedIncludes[max(1, $notice['line'])])) {
                continue; // The PHP catalog already reports this exact source uncertainty.
            }
            $diagnostics[] = new CatalogDiagnostic('http_analysis', $notice['reason'], max(1, $notice['line']), $owner);
        }
        foreach ($facts['operations'] as $op) {
            $context = [];
            foreach (['prefix', 'name', 'domain', 'namespace', 'controller', 'middleware', 'excluded_middleware', 'constraints', 'possible', 'reasons', 'specified'] as $key) {
                $context[$key] = $op['context'][$key];
            }
            $handler = null;
            if ($op['kind'] === 'route' && is_array($op['handler'])) {
                $raw = $op['handler'];
                if (isset($raw['callback'])) {
                    $handler = ['callback' => $callbacks[$op['site']['offset']] ?? null, 'parameters' => $raw['parameters'] ?? []];
                } elseif (isset($raw['class'], $raw['method'])) {
                    $handler = ['class' => $raw['class'], 'method' => $raw['method']];
                } elseif (isset($raw['string'])) {
                    $handler = ['string' => $raw['string']];
                }
            }
            $loadPaths = [];
            if ($op['kind'] === 'load') {
                $loadPaths[] = $this->localPath($op['path']);
                if (! str_starts_with($op['path'], '/')) {
                    $loadPaths[] = $this->localPath(dirname($file->path).'/'.$op['path']);
                }
                $loadPaths = array_values(array_unique(array_filter($loadPaths, 'is_string')));
            }
            $metadata = ['operation' => $op['kind'], 'offset' => max(0, $op['site']['offset']), 'context' => $context,
                'handler' => $handler, 'uri' => $op['uri'] ?? null, 'verbs' => $op['verbs'] ?? null,
                'provider' => $op['class'] ?? null, 'load_paths' => $loadPaths,
                'provider_owner' => ($op['context']['provider_candidate'] ?? false) ? ($op['context']['provider_owner'] ?? null) : null,
                'provider_method' => $op['context']['provider_method'] ?? null,
                'provider_scope' => $op['context']['provider_scope'] ?? null,
                'bootstrap_enabled' => $op['bootstrap_enabled'] ?? null,
                'resource' => $op['resource'] ?? ''];
            if ($op['kind'] === 'load' && $loadPaths === []) {
                $diagnostics[] = new CatalogDiagnostic('http_source_boundary', 'Route registration references a source outside the project or an unsafe path.', max(1, $op['site']['line']), $owner);
            }
            $relations[] = new CatalogRelation($owner, 'http:'.hash('xxh128', serialize($metadata)), 'http-template', max(1, $op['site']['line']), max(1, $op['site']['line']), 'conditional', $metadata);
        }

        return new CatalogFacts($file->path, relations: $relations, diagnostics: $diagnostics);
    }

    private function localPath(string $path): ?string
    {
        if (str_contains($path, "\0") || str_contains($path, ':')) {
            return null;
        }
        if (str_starts_with($path, '/')) {
            $base = rtrim($this->basePath, '/').'/';
            if (! str_starts_with($path, $base)) {
                return null;
            }
            $path = substr($path, strlen($base));
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode('/', $parts);
    }
}
