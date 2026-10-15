<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

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
        foreach ($file->syntax() ?? [] as $node) {
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
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        $declaration = $this->declarations[$owner] ?? null;
        $class = $declaration === null ? null : ($this->declarations[$declaration->parent ?? ''] ?? null);
        if (Node::is($node, 'statement.return') && $node->expr !== null && $declaration?->kind === 'method' && $class?->kind === 'class'
            && in_array($method = strtolower(substr($declaration->name, strrpos($declaration->name, '::') + 2)), ['share', 'shareonce'], true)) {
            $callbacks = [];
            $complete = true;
            $budget = 0;
            $this->callbacks($file, $node->expr, $method === 'shareonce' ? 'once' : 'ordinary', $callbacks, $complete, $budget);
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'inertia-operation', $method, $offset), 'Inertia middleware '.$method, 'inertia-operation',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['package' => 'inertiajs/inertia-laravel', 'method' => $method, 'form' => 'method', 'receiver' => $class->name, 'functions' => [],
                    'selector' => null, 'callbacks' => $callbacks, 'resolved' => Node::is($node->expr, 'expression.array'), 'callbacks_resolved' => $complete, 'execution_proven' => false]);
            if ($budget > 128) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Inertia middleware props exceed their 128-value source budget.', max(1, $node->getStartLine()), $owner);
            }
        }
        $invocation = $this->invocation($file, $node);
        if ($invocation !== null && Node::is($node, 'expression.call-like')) {
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
            $selector = Node::is($component, 'scalar.string') && strlen($component->value) <= 256
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
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }

    /** @return array{method: string, form: string, functions: list<string>}|null */
    private function invocation(FileContext $file, Node $node): ?array
    {
        if (Node::is($node, 'expression.static-call') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Inertia\\Inertia' && in_array($method = strtolower($node->name->toString()), ['render', 'share'], true)) {
            return ['method' => $method, 'form' => 'static', 'functions' => []];
        }
        $helper = Node::is($node, 'expression.func-call') ? $node : (Node::is($node, 'expression.method-call') ? $node->var : null);
        if (! Node::is($helper, 'expression.func-call') || ! Node::is($helper->name, 'name') || $helper->isFirstClassCallable()
            || ! Node::is($node, 'expression.call-like') || $node->isFirstClassCallable()) {
            return null;
        }
        $namespace = $helper->name->getAttribute('namespacedName');
        $functions = array_values(array_unique(Node::is($namespace, 'name') ? [$namespace->toString(), $file->resolvedName($helper->name)] : [$file->resolvedName($helper->name)]));
        if (! in_array('inertia', array_map('strtolower', $functions), true)) {
            return null;
        }
        if (Node::is($node, 'expression.func-call') && $node->args !== []) {
            return ['method' => 'render', 'form' => 'helper', 'functions' => $functions];
        }
        if (Node::is($node, 'expression.method-call') && $helper->args === [] && Node::is($node->name, 'identifier') && in_array($method = strtolower($node->name->toString()), ['render', 'share'], true)) {
            return ['method' => $method, 'form' => 'helper', 'functions' => $functions];
        }

        return null;
    }

    /** @param list<array{target: string, mode: string, line: int, end_line: int}> $callbacks */
    private function callbacks(FileContext $file, Node $value, string $mode, array &$callbacks, bool &$complete, int &$budget): void
    {
        if (++$budget > 128) {
            $complete = false;

            return;
        }
        if (Node::is($value, 'expression.closure') || Node::is($value, 'expression.arrow-function')) {
            $target = $this->owners[$value->getStartFilePos()] ?? null;
            if ($target?->kind === 'closure') {
                $callbacks[] = ['target' => $target->id, 'mode' => $mode, 'line' => max(1, $value->getStartLine()), 'end_line' => max(1, $value->getEndLine())];
            } else {
                $complete = false;
            }

            return;
        }
        if (Node::is($value, 'expression.array')) {
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
        if (Node::is($value, 'expression.static-call') && Node::is($value->class, 'name') && $file->resolvedName($value->class) === 'Inertia\\Inertia'
            && Node::is($value->name, 'identifier') && ! $value->isFirstClassCallable()
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
        if (! Node::is($value, 'scalar') && ! Node::is($value, 'expression.const-fetch') && ! Node::is($value, 'expression.class-const-fetch')) {
            $complete = false;
        }
    }
}
