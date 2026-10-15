<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pennant facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        if ((Node::is($node, 'expression.static-call') || Node::is($node, 'expression.method-call')) && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()
            && in_array($method = strtolower($node->name->toString()), ['define', ...CatalogPennantOperations::READS, ...CatalogPennantOperations::WRITES], true)) {
            $chain = [];
            $root = $node;
            while (Node::is($root, 'expression.method-call') && count($chain) < 8) {
                if ($root !== $node) {
                    $chain[] = $root;
                }
                $root = $root->var;
            }
            if (Node::is($root, 'expression.static-call') && Node::is($root->class, 'name') && $file->resolvedName($root->class) === 'Laravel\\Pennant\\Feature') {
                if ($root !== $node) {
                    $chain[] = $root;
                }
                $store = null;
                $scope = false;
                $valid = ! $root->isFirstClassCallable();
                $storeSelected = false;
                foreach (array_reverse($chain) as $link) {
                    $name = Node::is($link->name, 'identifier') ? strtolower($link->name->toString()) : '';
                    $valid = $valid && ! $link->isFirstClassCallable() && in_array($name, ['store', 'driver', 'for', 'globally'], true);
                    $arg = count($link->args) === 1 ? $link->getArgs()[0] : null;
                    if (in_array($name, ['store', 'driver'], true)) {
                        $store = Node::is($arg?->value, 'scalar.string') && CatalogPennantOperations::name($arg->value->value) ? $arg->value->value : null;
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
                $valid = $valid && (! in_array($method, ['define', 'value', 'active', 'inactive', 'when', 'unless'], true) || ! Node::is($selector, 'expression.array'))
                    && (! in_array($method, ['when', 'unless'], true) || isset($values[$parameters[1]]));
                $features = [];
                $selectors = Node::is($selector, 'expression.array') && count($selector->items) <= 128 ? $selector->items : [$selector];
                if (Node::is($selector, 'expression.array') && count($selector->items) > 128) {
                    $selectors = [];
                    $valid = false;
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Pennant feature selection exceeds its 128-entry source budget.', max(1, $node->getStartLine()), $owner);
                }
                foreach ($selectors as $item) {
                    if (Node::is($item, 'array-item') && ($item->unpack || $item->byRef)) {
                        $valid = false;

                        continue;
                    }
                    $value = Node::is($item, 'array-item') ? $item->value : $item;
                    $class = Node::is($value, 'expression.class-const-fetch') && Node::is($value->class, 'name') && Node::is($value->name, 'identifier') && strtolower($value->name->toString()) === 'class';
                    $name = Node::is($value, 'scalar.string') ? $value->value : ($class ? $file->resolvedName($value->class) : null);
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
                        && ! Node::is($value, 'expression.closure') && ! Node::is($value, 'expression.arrow-function')
                        && ! ($parameter === $parameters[2] && Node::is($value, 'expression.const-fetch') && strtolower($value->name->toString()) === 'null')) {
                        $valid = false;
                    }
                    if (Node::is($value, 'expression.closure') || Node::is($value, 'expression.arrow-function')) {
                        $id = $this->owners[$value->getStartFilePos()]->id ?? null;
                        if ($id !== null) {
                            $callbacks[] = ['target' => $id, 'condition' => ['resolver' => 'resolver', 'whenActive' => 'active', 'whenInactive' => 'inactive'][$parameter]];
                        } else {
                            $complete = false;
                        }
                    } elseif ($parameter === 'resolver' && Node::is($value, 'expression.class-const-fetch') && Node::is($value->class, 'name') && Node::is($value->name, 'identifier') && strtolower($value->name->toString()) === 'class'
                        && CatalogPennantOperations::name($type = $file->resolvedName($value->class))) {
                        // A class-string supplied as the second argument is a constant value, not a class resolver.
                    } elseif ($value !== null && ! Node::is($value, 'scalar') && ! (Node::is($value, 'expression.const-fetch') && in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true))) {
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
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }
}
