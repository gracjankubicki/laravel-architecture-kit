<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

final class PennantCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    private int $visited = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        foreach ($php->elements as $element) {
            $this->owners[$element->offset] = $element;
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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pennant facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        if (($node instanceof Expr\StaticCall || $node instanceof Expr\MethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && in_array($method = strtolower($node->name->toString()), ['define', ...CatalogPennantOperations::READS, ...CatalogPennantOperations::WRITES], true)) {
            $chain = [];
            $root = $node;
            while ($root instanceof Expr\MethodCall && count($chain) < 8) {
                if ($root !== $node) {
                    $chain[] = $root;
                }
                $root = $root->var;
            }
            if ($root instanceof Expr\StaticCall && $root->class instanceof Node\Name && $file->resolvedName($root->class) === 'Laravel\\Pennant\\Feature') {
                if ($root !== $node) {
                    $chain[] = $root;
                }
                $store = null;
                $scope = false;
                $valid = ! $root->isFirstClassCallable();
                $storeSelected = false;
                foreach (array_reverse($chain) as $link) {
                    $name = $link->name instanceof Node\Identifier ? strtolower($link->name->toString()) : '';
                    $valid = $valid && ! $link->isFirstClassCallable() && in_array($name, ['store', 'driver', 'for', 'globally'], true);
                    $arg = count($link->args) === 1 ? $link->getArgs()[0] : null;
                    if (in_array($name, ['store', 'driver'], true)) {
                        $store = $arg?->value instanceof Scalar\String_ && CatalogPennantOperations::name($arg->value->value) ? $arg->value->value : null;
                        $valid = $valid && ! $storeSelected && ! $scope && $arg !== null && ! $arg->unpack && $store !== null;
                        $storeSelected = true;
                    } elseif ($name === 'for') {
                        $valid = $valid && $arg !== null && ! $arg->unpack;
                        $scope = true;
                    } elseif ($name === 'globally') {
                        $valid = $valid && $link->args === [];
                        $scope = true;
                    }
                }
                $parameters = match ($method) {
                    'define' => ['feature', 'resolver'], 'activate', 'activateforeveryone' => ['feature', 'value'],
                    'when' => ['feature', 'whenActive', 'whenInactive'], 'unless' => ['feature', 'whenInactive', 'whenActive'],
                    default => [in_array($method, ['values', 'load', 'loadmissing', 'forget', 'purge', 'allareactive', 'someareactive', 'allareinactive', 'someareinactive'], true) ? 'features' : 'feature'],
                };
                $values = [];
                $valid = $valid && count($node->args) >= 1 && count($node->args) <= count($parameters);
                foreach ($node->getArgs() as $position => $arg) {
                    $name = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                    $valid = $valid && ! $arg->unpack && in_array($name, $parameters, true) && ! isset($values[$name]);
                    $values[$name] = $arg->value;
                }
                $selector = $values[$parameters[0]] ?? null;
                $valid = $valid && (! in_array($method, ['define', 'value', 'active', 'inactive', 'when', 'unless'], true) || ! $selector instanceof Expr\Array_)
                    && (! in_array($method, ['when', 'unless'], true) || isset($values[$parameters[1]]));
                $features = [];
                $selectors = $selector instanceof Expr\Array_ && count($selector->items) <= 128 ? $selector->items : [$selector];
                if ($selector instanceof Expr\Array_ && count($selector->items) > 128) {
                    $selectors = [];
                    $valid = false;
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pennant feature selection exceeds its 128-entry source budget.', max(1, $node->getStartLine()), $owner);
                }
                foreach ($selectors as $item) {
                    if ($item instanceof Node\ArrayItem && ($item->unpack || $item->byRef)) {
                        $valid = false;

                        continue;
                    }
                    $value = $item instanceof Node\ArrayItem ? $item->value : $item;
                    $class = $value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class';
                    $name = $value instanceof Scalar\String_ ? $value->value : ($class ? $file->resolvedName($value->class) : null);
                    $valid = $valid && $name !== null && CatalogPennantOperations::name($name);
                    if ($name !== null && CatalogPennantOperations::name($name)) {
                        $features[] = ['name' => $name, 'class' => $class];
                    }
                }
                $callbacks = [];
                $complete = true;
                $classDefinition = $method === 'define' && count($node->args) === 1;
                foreach (['resolver', 'whenActive', 'whenInactive'] as $parameter) {
                    $value = $values[$parameter] ?? null;
                    if (in_array($method, ['when', 'unless'], true) && $value !== null && $parameter !== 'resolver'
                        && ! $value instanceof Expr\Closure && ! $value instanceof Expr\ArrowFunction
                        && ! ($parameter === $parameters[2] && $value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null')) {
                        $valid = false;
                    }
                    if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
                        $id = $this->owners[$value->getStartFilePos()]->id ?? null;
                        if ($id !== null) {
                            $callbacks[] = ['target' => $id, 'condition' => ['resolver' => 'resolver', 'whenActive' => 'active', 'whenInactive' => 'inactive'][$parameter]];
                        } else {
                            $complete = false;
                        }
                    } elseif ($parameter === 'resolver' && $value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                        && CatalogPennantOperations::name($type = $file->resolvedName($value->class))) {
                        // A class-string supplied as the second argument is a constant value, not a class resolver.
                    } elseif ($value !== null && ! $value instanceof Scalar && ! ($value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true))) {
                        $complete = false;
                    }
                }
                $offset = max(0, $node->getStartFilePos());
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'pennant-operation', $method, $offset), 'Pennant '.$method, 'pennant-operation',
                    max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: ['method' => $method, 'features' => $features, 'store' => $store, 'scope_supplied' => $scope, 'class_definition' => $classDefinition,
                        'callbacks' => $callbacks, 'callbacks_resolved' => $complete, 'resolved' => $valid]);
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
}
