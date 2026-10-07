<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/** Source configuration chains only; does not load Pest or inspect runtime registrations. */
final class PestHookCatalogExtractor
{
    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $elements = $relations = $diagnostics = $chains = $owners = [];
        foreach ($php->elements as $element) {
            $owners[$element->offset] = $element;
        }
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Expr\MethodCall::class) as $call) {
            $offset = $call->getStartFilePos();
            if (! isset($chains[$offset]) || $call->getEndFilePos() > $chains[$offset]->getEndFilePos()) {
                $chains[$offset] = $call;
            }
        }
        foreach (array_slice($chains, 0, 4096, true) as $outer) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pest hook selection reached its memory budget.', $outer->getStartLine(), limitReason: 'memory');
                break;
            }
            $root = $outer;
            $calls = [];
            while ($root instanceof Expr\MethodCall && count($calls) < 128) {
                $calls[] = $root;
                $root = $root->var;
            }
            if ($root instanceof Expr\MethodCall) {
                $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pest configuration chain exceeded its source depth budget.', $outer->getStartLine(), limitReason: 'structure');

                continue;
            }
            if (! $root instanceof Expr\FuncCall || ! $root->name instanceof Node\Name || $root->isFirstClassCallable()) {
                continue;
            }
            $api = strtolower($file->resolvedName($root->name));
            if (! in_array($api, ['pest', 'uses'], true)) {
                continue;
            }
            $valid = $api !== 'pest' || $root->args === [];
            if ($api === 'uses') {
                foreach ($root->getArgs() as $argument) {
                    $valid = $valid && ! $argument->unpack && ! $argument->byRef
                        && $this->stringArgument($argument, true);
                }
            }
            $targets = [$api === 'pest' && basename($file->path) === 'Pest.php' ? dirname($file->path) : $file->path];
            $hooks = [];
            foreach (array_reverse($calls) as $position => $call) {
                $method = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
                if ($call->isFirstClassCallable() || ! in_array($method, ['in', 'extend', 'extends', 'use', 'uses', 'group', 'beforeeach', 'beforeall', 'aftereach', 'afterall'], true)) {
                    $valid = false;

                    continue;
                }
                // These aliases belong to Configuration, not the returned UsesCall.
                if (in_array($method, ['extends', 'uses'], true) && ($api !== 'pest' || $position !== 0)) {
                    $valid = false;
                }
                if (in_array($method, ['extend', 'extends', 'use', 'uses', 'group'], true)) {
                    foreach ($call->getArgs() as $argument) {
                        $valid = $valid && $this->stringArgument($argument, $method !== 'group');
                    }
                }
                if ($method === 'in') {
                    $targets = [];
                    foreach ($call->getArgs() as $argument) {
                        $target = ! $argument->unpack && ! $argument->byRef && $argument->name === null ? CatalogSelector::literal($argument->value) : null;
                        if ($target === null || $target === '' || strlen($target) > 500 || str_starts_with($target, '/') || str_contains($target, '\\')
                            || preg_match('~(?:^|/)\.\.(?:/|$)|^[A-Za-z]:~', $target)) {
                            $valid = false;

                            continue;
                        }
                        while (str_starts_with($target, './')) {
                            $target = substr($target, 2);
                        }
                        $target = $target === '.' ? '' : $target;
                        $targets[] = rtrim(dirname($file->path).'/'.$target, '/');
                    }
                } elseif (in_array($method, ['beforeeach', 'beforeall', 'aftereach', 'afterall'], true)) {
                    $argument = $call->getArgs()[0] ?? null;
                    $callback = $argument !== null && ! $argument->unpack && ! $argument->byRef && ($argument->name === null || $argument->name->toString() === 'hook')
                        && count($call->args) === 1 && ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction)
                        ? ($owners[$argument->value->getStartFilePos()] ?? null) : null;
                    $hooks[$method] = [$call, $callback];
                }
            }
            foreach ($hooks as $method => [$call, $callback]) {
                $resolved = $valid && $callback !== null && count($targets) <= 128;
                $id = CatalogElement::identity($file->path, 'test-hook', $api.':'.$method, $call->getStartFilePos());
                $elements[] = new CatalogElement($id, $method, 'test-hook', $call->getStartLine(), $call->getEndLine(), $call->getStartFilePos(),
                    metadata: ['test_api' => $method, 'test_executed' => false, 'registration_scope' => null, 'registration_api' => $api,
                        'test_targets' => array_slice($targets, 0, 128), 'targets_resolved' => $resolved]);
                if (! $resolved) {
                    $diagnostics[] = new CatalogDiagnostic('test_analysis', 'Pest configuration hook callback or target selection is unresolved.', $call->getStartLine(), $id);
                }
                if ($callback !== null) {
                    $relations[] = new CatalogRelation($id, $callback->id, 'test-body', $call->getStartLine(), $call->getEndLine(), 'conditional',
                        ['execution_proven' => false, 'test_executed' => false]);
                }
            }
        }
        if (count($chains) > 4096) {
            $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pest configuration chains exceeded their source budget.', 1, limitReason: 'structure');
        }

        return new CatalogFacts($file->path, $elements, $relations, $diagnostics);
    }

    private function stringArgument(Node\Arg $argument, bool $allowClass): bool
    {
        return ! $argument->unpack && ! $argument->byRef && $argument->name === null
            && ($argument->value instanceof Node\Scalar\String_
                || $allowClass && $argument->value instanceof Expr\ClassConstFetch
                && $argument->value->class instanceof Node\Name && $argument->value->name instanceof Node\Identifier
                && strtolower($argument->value->name->toString()) === 'class');
    }
}
