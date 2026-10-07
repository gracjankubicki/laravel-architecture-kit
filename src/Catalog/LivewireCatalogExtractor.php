<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Attribute selectors only; validation rules, messages and browser payloads are omitted. */
final class LivewireCatalogExtractor
{
    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $attributes = $owners = $propertyAttributeOwners = [];
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'trait', 'method', 'function', 'closure', 'property'], true)) {
                $owners[$element->offset] = $element->id;
            }
            if ($element->kind === 'attribute' && in_array($element->name, ['Livewire\\Attributes\\On', 'Livewire\\Attributes\\Computed', 'Livewire\\Attributes\\Validate'], true)) {
                $attributes[$element->offset] = $element;
            }
        }
        $elements = $diagnostics = [];
        if ($attributes === [] && stripos($file->contents, 'dispatch') === false && stripos($file->contents, 'component') === false && stripos($file->contents, 'listeners') === false) {
            return new CatalogFacts($file->path);
        }
        $fileOwner = CatalogElement::identity($file->path, 'file', $file->path);
        $pending = array_map(fn ($node) => [$node, $fileOwner, false, null], $file->ast() ?? []);
        $visited = 0;
        while ($pending !== []) {
            [$node, $owner, $bound, $parent] = array_pop($pending);
            if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                $owner = $owners[$node->getStartFilePos()] ?? $owner;
                $bound = $node instanceof Stmt\ClassMethod ? ! $node->isStatic() : (($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) && ! $node->static && $bound);
            }
            if ($node instanceof Stmt\ClassMethod && strtolower($node->name->toString()) === 'getlisteners') {
                $expression = count($node->stmts ?? []) === 1 && $node->stmts[0] instanceof Stmt\Return_ ? $node->stmts[0]->expr : null;
                [$listeners, $resolved] = $this->listenerMap($expression);
                $delegated = $expression instanceof Expr\PropertyFetch && $expression->var instanceof Expr\Variable && $expression->var->name === 'this'
                    && $expression->name instanceof Node\Identifier && $expression->name->toString() === 'listeners';
                $mode = $delegated ? 'property' : ($resolved ? 'literal' : 'dynamic');
                $resolved = $resolved || $delegated;
                $resolved = $resolved && count(array_filter($node->params, fn ($parameter) => $parameter->default === null && ! $parameter->variadic)) === 0;
                $offset = max(0, $node->getStartFilePos());
                $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'livewire-listeners', 'getListeners', $offset), 'Livewire getListeners',
                    'livewire-listeners', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: ['form' => 'method', 'mode' => $mode, 'listeners' => $listeners, 'resolved' => $resolved]);
                if ($expression instanceof Expr\Array_ && count($expression->items) > 128) {
                    $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Livewire listener map exceeds its 128-entry source budget.', max(1, $node->getStartLine()), $owner);
                }
                if (! $resolved) {
                    $diagnostics[] = new CatalogDiagnostic('livewire_listener_analysis', 'Livewire listener map is dynamic or uses an unsupported source shape.', max(1, $node->getStartLine()), $owner);
                }
            }
            if ($node instanceof Stmt\Property) {
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        foreach ($node->props as $property) {
                            $propertyOwner = $owners[$property->getStartFilePos()] ?? null;
                            if ($propertyOwner !== null) {
                                $propertyAttributeOwners[$attribute->getStartFilePos()][] = $propertyOwner;
                            }
                        }
                    }
                }
                foreach ($node->props as $property) {
                    if ($property->name->toString() !== 'listeners') {
                        continue;
                    }
                    [$listeners, $resolved] = $this->listenerMap($property->default);
                    $offset = max(0, $property->getStartFilePos());
                    $propertyOwner = $owners[$offset] ?? $owner;
                    $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'livewire-listeners', 'listeners', $offset), 'Livewire listeners property',
                        'livewire-listeners', max(1, $property->getStartLine()), max(1, $property->getEndLine()), $offset, $propertyOwner,
                        metadata: ['form' => 'property', 'mode' => $resolved ? 'literal' : 'dynamic', 'listeners' => $listeners, 'resolved' => $resolved]);
                    if ($property->default instanceof Expr\Array_ && count($property->default->items) > 128) {
                        $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Livewire listener map exceeds its 128-entry source budget.', max(1, $property->getStartLine()), $propertyOwner);
                    } elseif (! $resolved) {
                        $diagnostics[] = new CatalogDiagnostic('livewire_listener_analysis', 'Livewire listeners property is dynamic or has an unsupported source shape.', max(1, $property->getStartLine()), $propertyOwner);
                    }
                }
            }
            if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $file->resolvedName($node->class) === 'Livewire\\Livewire'
                && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'component' && ! $node->isFirstClassCallable()) {
                $arguments = [];
                $shape = count($node->args) === 2;
                foreach ($node->getArgs() as $position => $arg) {
                    $name = $arg->name?->toString() ?? (['name', 'class'][$position] ?? 'unknown');
                    $shape = $shape && ! $arg->unpack && in_array($name, ['name', 'class'], true) && ! isset($arguments[$name]);
                    $arguments[$name] = $arg->value;
                }
                $name = $arguments['name'] ?? null;
                $name = $name instanceof Scalar\String_ && self::eventName($name->value) ? $name->value : null;
                $class = $arguments['class'] ?? null;
                $class = $class instanceof Expr\ClassConstFetch && $class->class instanceof Node\Name && $class->name instanceof Node\Identifier
                    && strtolower($class->name->toString()) === 'class' ? $file->resolvedName($class->class) : null;
                $offset = max(0, $node->getStartFilePos());
                $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'livewire-registration', 'component', $offset), 'Livewire component '.($name ?? 'dynamic'),
                    'livewire-registration', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: ['name' => $name, 'class' => $class, 'resolved' => $shape && $name !== null && $class !== null]);
            }
            if ($node instanceof Expr\MethodCall && ! ($parent instanceof Expr\MethodCall && $parent->var === $node)) {
                $fact = $this->dispatchFact($file, $node, $owner, $bound);
                if ($fact !== null) {
                    $elements[] = $fact;
                }
            }
            if (++$visited > 25000 || ImpactExtractor::sourceLimit(0) !== null) {
                $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Livewire attribute extraction reached its source budget.', 1);
                break;
            }
            if ($node instanceof Node\Attribute && isset($attributes[$node->getStartFilePos()])) {
                $source = $attributes[$node->getStartFilePos()];
                $hook = strtolower(substr($source->name, strrpos($source->name, '\\') + 1));
                $events = [];
                $resolved = true;
                if ($hook === 'on') {
                    $argument = count($node->args) === 1 && ! $node->args[0]->unpack && ($node->args[0]->name === null || $node->args[0]->name->toString() === 'event') ? $node->args[0]->value : null;
                    $values = $argument instanceof Scalar\String_ ? [$argument] : [];
                    if ($argument instanceof Expr\Array_ && count($argument->items) <= 128) {
                        foreach ($argument->items as $item) {
                            if ($item === null || $item->unpack || $item->byRef) {
                                $resolved = false;

                                continue;
                            }
                            $values[] = $item->value;
                        }
                    } elseif (! $argument instanceof Scalar\String_) {
                        $resolved = false;
                    }
                    foreach ($values as $value) {
                        if ($value instanceof Scalar\String_ && self::eventName($value->value)) {
                            $events[] = $value->value;
                        } else {
                            $resolved = false;
                        }
                    }
                }
                foreach ($propertyAttributeOwners[$source->offset] ?? [$source->parent] as $attributeOwner) {
                    $identityName = $source->name.($attributeOwner !== $source->parent ? '#'.$attributeOwner : '');
                    $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'livewire-attribute', $identityName, $source->offset), 'Livewire '.$hook,
                        'livewire-attribute', $source->line, $source->endLine, $source->offset, $attributeOwner,
                        metadata: ['attribute' => $source->name, 'hook' => $hook, 'events' => $events, 'resolved' => $resolved]);
                }
                if (! $resolved) {
                    $diagnostics[] = new CatalogDiagnostic('livewire_attribute_analysis', 'Livewire listener event contains a dynamic selector or unsupported shape.', $source->line, $source->parent);
                }
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $pending[] = [$child, $owner, $bound, $node];
                    }
                }
            }
        }

        return new CatalogFacts($file->path, $elements, [], $diagnostics);
    }

    /** @return array{list<array{event: string, method: string}>, bool} */
    private function listenerMap(?Node $expression): array
    {
        if (! $expression instanceof Expr\Array_ || count($expression->items) > 128) {
            return [[], false];
        }
        $values = [];
        $resolved = true;
        foreach ($expression->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || ! $item->value instanceof Scalar\String_
                || ! self::listenerMethod($item->value->value)
                || $item->key !== null && ! $item->key instanceof Scalar\String_ && ! $item->key instanceof Scalar\Int_) {
                $resolved = false;

                continue;
            }
            if ($item->key === null) {
                $values[] = $item->value->value;
            } else {
                $values[$item->key->value] = $item->value->value;
            }
        }
        $listeners = [];
        foreach ($values as $key => $method) {
            $event = is_numeric($key) ? $method : $key;
            if (! self::eventName($event)) {
                $resolved = false;

                continue;
            }
            $listeners[] = ['event' => $event, 'method' => $method];
        }

        return [$listeners, $resolved];
    }

    public static function listenerMethod(string $method): bool
    {
        return $method === '$refresh' || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,255}\z/D', $method) === 1;
    }

    private function dispatchFact(FileContext $file, Expr\MethodCall $site, string $owner, bool $bound): ?CatalogElement
    {
        $root = $site;
        $chain = [];
        while ($root->var instanceof Expr\MethodCall && count($chain) < 16) {
            $chain[] = $root;
            $root = $root->var;
        }
        if (! $root->name instanceof Node\Identifier || strtolower($root->name->toString()) !== 'dispatch'
            || ! $root->var instanceof Expr\Variable || $root->var->name !== 'this' || $root->isFirstClassCallable()) {
            return null;
        }
        $argument = null;
        $shape = $bound;
        $mode = 'global';
        $target = null;
        $self = false;
        $controls = [];
        foreach ($root->getArgs() as $position => $arg) {
            $shape = $shape && ! $arg->unpack;
            if ($arg->name?->toString() === 'event' || $arg->name === null && $position === 0) {
                $shape = $shape && $argument === null;
                $argument = $arg->value;
            }
            if ($arg->name !== null) {
                $controls[$arg->name->toString()] = $arg->value;
            }
        }
        foreach (['ref', 'component', 'el', 'self', 'to'] as $key) {
            $value = $controls[$key] ?? null;
            if ($value === null || $value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null') {
                continue;
            }
            if ($key === 'self') {
                $self = true;
                $shape = $shape && ($value instanceof Scalar || $value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false'], true));
            } elseif (in_array($key, ['component', 'to'], true)) {
                $target = $this->target($file, $value);
                $mode = 'component';
                $shape = $shape && $target !== null;
            } else {
                $mode = 'runtime';
                $target = null;
            }
        }
        foreach (array_reverse($chain) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
                $shape = false;

                continue;
            }
            $method = strtolower($call->name->toString());
            if ($method === 'self' && $call->args === []) {
                $self = true;
            } elseif (in_array($method, ['to', 'component'], true) && count($call->args) === 1 && ! $call->args[0]->unpack
                && ($call->args[0]->name === null || $call->args[0]->name->toString() === ($method === 'to' ? 'component' : 'name'))) {
                $target = $this->target($file, $call->args[0]->value);
                $mode = 'component';
                $shape = $shape && $target !== null;
            } elseif (in_array($method, ['ref', 'el'], true)) {
                $mode = 'runtime';
                $target = null;
            } else {
                $shape = false;
            }
        }
        if (! $self && (isset($controls['ref']) || isset($controls['el']))) {
            $mode = 'runtime';
            $target = null;
        }
        if ($self) {
            $mode = 'self';
            $target = null;
        }
        $event = $argument instanceof Scalar\String_ && self::eventName($argument->value) ? $argument->value : null;
        $offset = max(0, $site->getStartFilePos());

        return new CatalogElement(CatalogElement::identity($file->path, 'livewire-dispatch', 'dispatch', $offset), 'Livewire dispatch '.($event ?? 'dynamic'),
            'livewire-dispatch', max(1, $site->getStartLine()), max(1, $site->getEndLine()), $offset, $owner,
            metadata: ['event' => $event, 'target_mode' => $mode, 'target' => $target, 'resolved' => $shape && $event !== null]);
    }

    private function target(FileContext $file, Expr $value): ?string
    {
        if ($value instanceof Scalar\String_ && self::eventName($value->value)) {
            return $value->value;
        }
        if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class') {
            return $file->resolvedName($value->class);
        }

        return null;
    }

    public static function eventName(string $name): bool
    {
        return preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,127}\z/D', $name) === 1;
    }
}
