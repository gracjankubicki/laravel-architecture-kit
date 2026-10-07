<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Local Inertia declarations only; response composition decides when callbacks may run. */
final class InertiaCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    /** @var array<string, CatalogElement> */
    private array $declarations = [];

    private int $visited = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = $this->declarations = [];
        $this->visited = 0;
        foreach ($php->elements as $element) {
            $this->owners[$element->offset] = $element;
            $this->declarations[$element->id] = $element;
        }
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path));
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner): void
    {
        if ($this->visited >= 25000) {
            return;
        }
        if (++$this->visited >= 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->visited = 25000;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Inertia source facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        $declaration = $this->declarations[$owner] ?? null;
        $class = $declaration === null ? null : ($this->declarations[$declaration->parent ?? ''] ?? null);
        if ($node instanceof Stmt\Return_ && $node->expr !== null && $declaration?->kind === 'method' && $class?->kind === 'class'
            && in_array($method = strtolower(substr($declaration->name, strrpos($declaration->name, '::') + 2)), ['share', 'shareonce'], true)) {
            $callbacks = [];
            $complete = true;
            $budget = 0;
            $this->callbacks($file, $node->expr, $method === 'shareonce' ? 'once' : 'ordinary', $callbacks, $complete, $budget);
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'inertia-operation', $method, $offset), 'Inertia middleware '.$method, 'inertia-operation',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['package' => 'inertiajs/inertia-laravel', 'method' => $method, 'form' => 'method', 'receiver' => $class->name, 'functions' => [],
                    'selector' => null, 'callbacks' => $callbacks, 'resolved' => $node->expr instanceof Expr\Array_, 'callbacks_resolved' => $complete, 'execution_proven' => false]);
            if ($budget > 128) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Inertia middleware props exceed their 128-value source budget.', max(1, $node->getStartLine()), $owner);
            }
        }
        $invocation = $this->invocation($file, $node);
        if ($invocation !== null && $node instanceof Expr\CallLike) {
            $method = $invocation['method'];
            $parameters = $method === 'render' ? ['component', 'props'] : ['key', 'value'];
            $values = [];
            $resolved = count($node->getArgs()) >= 1 && count($node->getArgs()) <= 2;
            foreach ($node->getArgs() as $position => $arg) {
                $parameter = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                $resolved = $resolved && ! $arg->unpack && in_array($parameter, $parameters, true) && ! isset($values[$parameter]);
                $values[$parameter] = $arg->value;
            }
            $component = $method === 'render' ? ($values['component'] ?? null) : null;
            $selector = $component instanceof Scalar\String_ && strlen($component->value) <= 256
                && preg_match('/\A[a-zA-Z0-9_\-][a-zA-Z0-9_.\/\-]{0,255}\z/D', $component->value) === 1
                && ! in_array('..', explode('/', $component->value), true) ? $component->value : null;
            $resolved = $resolved && ($method !== 'render' || $selector !== null);
            $props = $method === 'render' ? ($values['props'] ?? null) : ($values['value'] ?? ($values['key'] ?? null));
            $callbacks = [];
            $complete = true;
            $budget = 0;
            if ($props !== null) {
                $this->callbacks($file, $props, 'ordinary', $callbacks, $complete, $budget);
            }
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'inertia-operation', $method, $offset), 'Inertia '.$method, 'inertia-operation',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['package' => 'inertiajs/inertia-laravel', 'method' => $method, 'form' => $invocation['form'], 'receiver' => 'Inertia\\Inertia', 'functions' => $invocation['functions'],
                    'selector' => $selector, 'callbacks' => $callbacks, 'resolved' => $resolved, 'callbacks_resolved' => $complete, 'execution_proven' => false]);
            if ($budget > 128) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Inertia prop facts exceed their 128-value source budget.', max(1, $node->getStartLine()), $owner);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }

    /** @return array{method: string, form: string, functions: list<string>}|null */
    private function invocation(FileContext $file, Node $node): ?array
    {
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Inertia\\Inertia' && in_array($method = strtolower($node->name->toString()), ['render', 'share'], true)) {
            return ['method' => $method, 'form' => 'static', 'functions' => []];
        }
        $helper = $node instanceof Expr\FuncCall ? $node : ($node instanceof Expr\MethodCall ? $node->var : null);
        if (! $helper instanceof Expr\FuncCall || ! $helper->name instanceof Node\Name || $helper->isFirstClassCallable()
            || ! $node instanceof Expr\CallLike || $node->isFirstClassCallable()) {
            return null;
        }
        $namespace = $helper->name->getAttribute('namespacedName');
        $functions = array_values(array_unique($namespace instanceof Node\Name ? [$namespace->toString(), $file->resolvedName($helper->name)] : [$file->resolvedName($helper->name)]));
        if (! in_array('inertia', array_map('strtolower', $functions), true)) {
            return null;
        }
        if ($node instanceof Expr\FuncCall && $node->args !== []) {
            return ['method' => 'render', 'form' => 'helper', 'functions' => $functions];
        }
        if ($node instanceof Expr\MethodCall && $helper->args === [] && $node->name instanceof Node\Identifier && in_array($method = strtolower($node->name->toString()), ['render', 'share'], true)) {
            return ['method' => $method, 'form' => 'helper', 'functions' => $functions];
        }

        return null;
    }

    /** @param list<array{target: string, mode: string, line: int, end_line: int}> $callbacks */
    private function callbacks(FileContext $file, Expr $value, string $mode, array &$callbacks, bool &$complete, int &$budget): void
    {
        if (++$budget > 128) {
            $complete = false;

            return;
        }
        if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
            $target = $this->owners[$value->getStartFilePos()] ?? null;
            if ($target?->kind === 'closure') {
                $callbacks[] = ['target' => $target->id, 'mode' => $mode, 'line' => max(1, $value->getStartLine()), 'end_line' => max(1, $value->getEndLine())];
            } else {
                $complete = false;
            }

            return;
        }
        if ($value instanceof Expr\Array_) {
            foreach ($value->items as $item) {
                if ($item === null || $item->unpack || $item->byRef) {
                    $complete = false;

                    continue;
                }
                $this->callbacks($file, $item->value, $mode, $callbacks, $complete, $budget);
                if ($budget > 128) {
                    break;
                }
            }

            return;
        }
        if ($value instanceof Expr\StaticCall && $value->class instanceof Node\Name && $file->resolvedName($value->class) === 'Inertia\\Inertia'
            && $value->name instanceof Node\Identifier && ! $value->isFirstClassCallable()
            && in_array($wrapper = strtolower($value->name->toString()), ['optional', 'defer', 'once', 'always', 'merge', 'deepmerge'], true)) {
            $parameters = in_array($wrapper, ['optional', 'defer'], true) ? ['callback', 'group', 'rescue'] : ['value'];
            $selected = null;
            $seen = [];
            $valid = count($value->args) >= 1 && count($value->args) <= ($wrapper === 'defer' ? 3 : 1);
            foreach ($value->getArgs() as $position => $arg) {
                $parameter = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                $valid = $valid && ! $arg->unpack && in_array($parameter, $parameters, true) && ! isset($seen[$parameter]);
                $seen[$parameter] = true;
                if ($parameter === $parameters[0]) {
                    $selected = $arg->value;
                }
            }
            if ($valid && $selected !== null) {
                $this->callbacks($file, $selected, $wrapper, $callbacks, $complete, $budget);
            } else {
                $complete = false;
            }

            return;
        }
        if (! $value instanceof Scalar && ! $value instanceof Expr\ConstFetch && ! $value instanceof Expr\ClassConstFetch) {
            $complete = false;
        }
    }
}
