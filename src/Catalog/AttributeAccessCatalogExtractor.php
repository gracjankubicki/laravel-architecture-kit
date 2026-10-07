<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Property sites store receiver types and names, never assigned values. */
final class AttributeAccessCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    private int $visited = 0;

    private bool $limited = false;

    private bool $hasProperty = false;

    /** @var array<string, string> Variable bindings to source object identities. */
    private array $objects = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $changes = [];

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        $this->limited = false;
        $this->hasProperty = false;
        $this->objects = $this->changes = [];
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element;
            }
        }
        $vars = [];
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path), '', $vars);
        }

        $diagnostics = $this->hasProperty ? $this->diagnostics : array_values(array_filter($this->diagnostics, fn ($row) => $row->code === 'catalog_limit'));

        return new CatalogFacts($file->path, $this->elements, [], $diagnostics);
    }

    /** @param array<string, string> $vars */
    private function visit(FileContext $file, Node $node, string $owner, string $class, array &$vars, string $direction = 'read', bool $returned = false): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Attribute accesses reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $declaration = $this->owners[$node->getStartFilePos()] ?? null;
            if ($declaration === null) {
                return;
            }
            $outerOwner = $owner;
            $owner = $declaration->id;
            $outerChanges = $this->changes;
            $outerObjects = $this->objects;
            $class = $node instanceof Stmt\ClassLike ? $declaration->name : $class;
            $local = $node instanceof Expr\ArrowFunction ? $vars : [];
            if ($node instanceof Expr\Closure) {
                foreach ($node->uses as $use) {
                    if (is_string($use->var->name) && isset($vars[$use->var->name])) {
                        if ($use->byRef) {
                            unset($vars[$use->var->name]);
                            $this->diagnostics[] = new CatalogDiagnostic('attribute_binding_analysis', 'Reference capture leaves callback receiver bindings unresolved.', max(1, $use->getStartLine()), $owner);
                        } else {
                            $local[$use->var->name] = $vars[$use->var->name];
                        }
                    }
                }
                if (isset($vars['this'])) {
                    $local['this'] = $vars['this'];
                }
            }
            if (($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) && $node->static) {
                unset($local['this']);
            }
            if ($node instanceof Stmt\ClassMethod && ! $node->isStatic() && $class !== '') {
                $local['this'] = $class;
            }
            foreach ($node instanceof Stmt\ClassLike ? [] : $node->params as $param) {
                $type = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    unset($local[$param->var->name]);
                    $types = [];
                    foreach ($type instanceof Node\UnionType ? $type->types : [$type] as $candidate) {
                        if ($candidate instanceof Node\Name) {
                            $types[] = in_array(strtolower($candidate->toString()), ['self', 'static'], true) ? $class : $file->resolvedName($candidate);
                        }
                    }
                    if ($types !== []) {
                        $receiver = count($types) === 1 ? $types[0] : '@types:'.json_encode($types, JSON_THROW_ON_ERROR);
                        if (count($types) > 128 || strlen($receiver) > 1000) {
                            $this->limited = true;
                            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Attribute receiver type reached its descriptor budget.', max(1, $param->getStartLine()), $owner);

                            return;
                        }
                        $local[$param->var->name] = $receiver;
                    }
                }
            }
            foreach ($local as $name => $type) {
                $captured = ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction)
                    && ! in_array($name, array_map(fn ($param) => $param->var instanceof Expr\Variable ? $param->var->name : null, $node->params), true);
                $this->objects[$owner.'#'.$name] = $captured
                    ? ($this->objects[$outerOwner.'#'.$name] ?? $outerOwner.'#'.$name) : $owner.'#'.$name;
            }
            foreach ($node instanceof Expr\ArrowFunction ? [$node->expr] : ($node->stmts ?? []) as $child) {
                $this->visit($file, $child, $owner, $class, $local, returned: $node instanceof Expr\ArrowFunction);
            }
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                $capturedObjects = [];
                foreach ($local as $name => $type) {
                    $capturedObjects[] = $outerObjects[$outerOwner.'#'.$name] ?? $outerOwner.'#'.$name;
                }
                foreach ($this->changes as $object => $steps) {
                    $shared = str_starts_with($object, $outerOwner.'#') || in_array($object, $capturedObjects, true);
                    if ($shared && $steps !== ($outerChanges[$object] ?? [])) {
                        // Defining a callback does not execute its captured mutations.
                        $step = $this->changes[$object][array_key_last($this->changes[$object])];
                        $step['resolved'] = false;
                        $outerChanges[$object] = [$step];
                    }
                }
                $this->changes = $outerChanges;
                foreach ($vars as $name => $type) {
                    $object = $outerObjects[$outerOwner.'#'.$name] ?? $outerOwner.'#'.$name;
                    foreach ($outerObjects as $key => $binding) {
                        if (str_starts_with($key, $object.'#property:') && ($this->objects[$key] ?? null) !== $binding) {
                            unset($vars[$name]);
                        }
                    }
                    foreach ($this->objects as $key => $binding) {
                        if (str_starts_with($key, $object.'#property:') && ! isset($outerObjects[$key])) {
                            unset($vars[$name]);
                        }
                    }
                }
                $this->objects = $outerObjects;
            }

            return;
        }
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp) {
            $this->visit($file, $node->expr, $owner, $class, $vars);
            $this->visit($file, $node->var, $owner, $class, $vars, $node instanceof Expr\AssignOp ? 'read-write' : 'write');
            if ($node instanceof Expr\AssignRef) {
                $vars = [];
                $this->diagnostics[] = new CatalogDiagnostic('attribute_binding_analysis', 'Reference assignment invalidates source attribute receiver bindings.', max(1, $node->getStartLine()), $owner);

                return;
            }
            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $receiver = $node instanceof Expr\Assign ? $this->receiver($file, $node->expr, $class, $vars) : null;
                $object = $this->object($node->expr, $owner);
                unset($vars[$node->var->name]);
                unset($this->objects[$owner.'#'.$node->var->name]);
                if ($receiver !== null) {
                    $vars[$node->var->name] = $receiver;
                    $this->objects[$owner.'#'.$node->var->name] = $object ?? $owner.'#new:'.$node->getStartFilePos();
                }
            }
            if ($node->var instanceof Expr\PropertyFetch && $node->var->name instanceof Node\Identifier) {
                $parentObject = $this->object($node->var->var, $owner);
                $sourceObject = $node instanceof Expr\Assign ? $this->object($node->expr, $owner) : null;
                if ($parentObject !== null && $sourceObject !== null) {
                    $this->objects[$parentObject.'#property:'.$node->var->name->toString()] = $sourceObject;
                } elseif ($node->var->var instanceof Expr\Variable && is_string($node->var->var->name)) {
                    unset($vars[$node->var->var->name]);
                    $this->diagnostics[] = new CatalogDiagnostic('attribute_binding_analysis', 'Assigned property receiver state is unresolved.', max(1, $node->getStartLine()), $owner);
                }
            }

            return;
        }
        if ($node instanceof Stmt\If_ || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\TryCatch) {
            $this->diagnostics[] = new CatalogDiagnostic('attribute_binding_analysis', 'Attribute receiver bindings inside and after control flow require composition.', max(1, $node->getStartLine()), $owner);
            $local = [];
            $this->children($file, $node, $owner, $class, $local);
            $vars = [];

            return;
        }
        if ($node instanceof Stmt\Unset_ || $node instanceof Expr\Isset_) {
            foreach ($node->vars as $value) {
                $this->visit($file, $value, $owner, $class, $vars, 'inspect');
                if ($value instanceof Expr\Variable && is_string($value->name) && $node instanceof Stmt\Unset_) {
                    unset($vars[$value->name]);
                }
            }

            return;
        }
        if ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
            $this->visit($file, $node->var, $owner, $class, $vars, 'read-write');

            return;
        }
        if (! $returned && ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch)) {
            $this->hasProperty = true;
            $receiver = $this->boundedReceiver($this->receiver($file, $node->var, $class, $vars), $node, $owner);
            if ($receiver !== null) {
                $offset = max(0, $node->getStartFilePos());
                $name = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'attribute-access', 'attribute-access:'.$node->getEndFilePos(), $offset),
                    'attribute access', 'attribute-access', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: ['receiver' => $receiver, 'attribute_key' => $name === null ? null : hash('sha256', $name), 'direction' => $direction, 'nullsafe' => $node instanceof Expr\NullsafePropertyFetch, 'form' => 'property', 'execution_proven' => false]);
            }
        }
        $transportReceiver = ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            ? $this->boundedReceiver($this->receiver($file, $node->var, $class, $vars), $node, $owner) : null;
        $invocation = CatalogSerializationInvocation::extract($file, $node, $returned, $transportReceiver);
        if ($invocation !== null) {
            $functions = $invocation['functions'];
            if (array_filter($functions, fn ($name) => strlen($name) > 1000) !== []) {
                $this->limited = true;
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'JSON serialization function reached its descriptor budget.', max(1, $node->getStartLine()), $owner, 'structure');

                return;
            }
            if ($returned) {
                $this->visit($file, $node, $owner, $class, $vars);
            } else {
                $this->children($file, $node, $owner, $class, $vars);
            }
            if ($this->limited) {
                return;
            }
            $value = $invocation['value'];
            $valid = $invocation['valid'];
            $rootValue = $value;
            $values = $value instanceof Expr ? [$value] : [];
            $visited = 0;
            while ($values !== []) {
                if (++$visited > 128) {
                    $this->limited = true;
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'JSON serialization values reached their descriptor budget.', max(1, $node->getStartLine()), $owner, 'structure');

                    return;
                }
                $value = array_shift($values);
                if ($value instanceof Expr\Array_) {
                    foreach ($value->items as $item) {
                        if ($item !== null && ! $item->unpack && ! $item->byRef) {
                            if (count($values) >= 128) {
                                $this->limited = true;
                                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'JSON serialization array reached its descriptor budget.', max(1, $node->getStartLine()), $owner, 'structure');

                                return;
                            }
                            $values[] = $item->value;
                        } else {
                            $this->hasProperty = true;
                            $this->diagnostics[] = new CatalogDiagnostic('attribute_binding_analysis', 'Unpacked or referenced JSON array values require source composition.', max(1, $node->getStartLine()), $owner);
                        }
                    }

                    continue;
                }
                $receiver = $this->boundedReceiver($this->receiver($file, $value, $class, $vars), $node, $owner);
                if ($receiver === null) {
                    continue;
                }
                $this->hasProperty = true;
                $offset = max(0, $node->getStartFilePos());
                $metadata = ['receiver' => $receiver, 'attribute_key' => null, 'direction' => $valid ? 'read' : 'inspect', 'nullsafe' => false,
                    'form' => $invocation['form'], 'execution_proven' => false, 'serialization_steps' => $this->changes[$this->object($value, $owner) ?? ''] ?? [],
                    'serialization_functions' => $functions];
                if (in_array($invocation['form'], ['http_json', 'http_return', 'http_instance'], true)) {
                    $metadata['serialization_transport'] = ['types' => $invocation['types'], 'dispatch' => $value === $rootValue ? 'tojson' : 'jsonserialize'];
                    if (isset($invocation['receiver'], $invocation['method'])) {
                        $metadata['serialization_transport']['receiver'] = $invocation['receiver'];
                        $metadata['serialization_transport']['method'] = $invocation['method'];
                    }
                }
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'attribute-access', 'json-model:'.$node->getEndFilePos().':'.$value->getStartFilePos(), $offset),
                    'attribute access', 'attribute-access', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: $metadata);
            }

            return;
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && in_array($form = strtolower($node->name->toString()), ['getattribute', 'getattributevalue', 'setattribute'], true)) {
            $this->hasProperty = true;
            $receiver = $this->boundedReceiver($this->receiver($file, $node->var, $class, $vars), $node, $owner);
            if ($receiver !== null) {
                $key = null;
                $valid = count($node->getArgs()) === ($form === 'setattribute' ? 2 : 1);
                $seen = [];
                foreach ($node->getArgs() as $position => $arg) {
                    $parameter = $arg->name?->toString() ?? (['key', 'value'][$position] ?? 'unknown');
                    $valid = $valid && ! $arg->unpack && in_array($parameter, $form === 'setattribute' ? ['key', 'value'] : ['key'], true) && ! isset($seen[$parameter]);
                    $seen[$parameter] = true;
                    if ($parameter === 'key' && $arg->value instanceof Scalar\String_) {
                        $key = hash('sha256', $arg->value->value);
                    }
                }
                $offset = max(0, $node->getStartFilePos());
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'attribute-access', 'attribute-access:'.$node->getEndFilePos(), $offset),
                    'attribute access', 'attribute-access', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: ['receiver' => $receiver, 'attribute_key' => $valid ? $key : null, 'direction' => $form === 'setattribute' ? 'write' : 'read',
                        'nullsafe' => $node instanceof Expr\NullsafeMethodCall, 'form' => $form, 'execution_proven' => false]);
            }
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $form = strtolower($node->name->toString());
            $serialization = in_array($form, ['toarray', 'attributestoarray', 'tojson', 'jsonserialize'], true);
            $mutation = in_array($form, CatalogSerializationChanges::METHODS, true);
            if ($serialization || $mutation) {
                // Fluent mutations occur before the enclosing serialization call.
                $this->children($file, $node, $owner, $class, $vars);
            }
            if (in_array($form, ['toarray', 'attributestoarray', 'tojson', 'jsonserialize'], true)) {
                $this->hasProperty = true;
                $receiver = $this->boundedReceiver($this->receiver($file, $node->var, $class, $vars), $node, $owner);
                if ($receiver !== null) {
                    $valid = $form === 'tojson' ? count($node->getArgs()) <= 1 : $node->getArgs() === [];
                    foreach ($node->getArgs() as $arg) {
                        $valid = $valid && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'options');
                    }
                    $offset = max(0, $node->getStartFilePos());
                    $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'attribute-access', 'attribute-access:'.$node->getEndFilePos(), $offset),
                        'attribute access', 'attribute-access', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                        metadata: ['receiver' => $receiver, 'attribute_key' => null, 'direction' => $valid ? 'read' : 'inspect',
                            'nullsafe' => $node instanceof Expr\NullsafeMethodCall, 'form' => $form, 'execution_proven' => false,
                            'serialization_steps' => $this->changes[$this->object($node->var, $owner) ?? ''] ?? []]);
                }
            }
            if ($mutation && $this->receiver($file, $node->var, $class, $vars) !== null) {
                $this->hasProperty = true;
                $object = $this->object($node->var, $owner);
                if ($object !== null) {
                    $step = CatalogSerializationChanges::extract($node);
                    if (count($this->changes[$object] ?? []) >= 128) {
                        $step['resolved'] = false;
                        $this->changes[$object] = [$step];
                        $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Instance serialization changes reached their operation budget.', max(1, $node->getStartLine()), $owner);
                    } else {
                        $this->changes[$object][] = $step;
                    }
                }
            }
            if ($serialization || $mutation) {
                return;
            }
        }
        $this->children($file, $node, $owner, $class, $vars);
        if (($node instanceof Expr\FuncCall || $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) && ! $node->isFirstClassCallable() || $node instanceof Expr\New_) {
            foreach ($node->getArgs() as $arg) {
                if ($arg->value instanceof Expr\Variable && is_string($arg->value->name) && isset($vars[$arg->value->name])) {
                    $object = $this->object($arg->value, $owner);
                    foreach (array_keys($vars) as $name) {
                        if (($this->objects[$owner.'#'.$name] ?? $owner.'#'.$name) === $object) {
                            unset($vars[$name]);
                        }
                    }
                    $this->diagnostics[] = new CatalogDiagnostic('attribute_binding_analysis', 'Argument receiver may be rebound by a source call.', max(1, $node->getStartLine()), $owner);
                }
            }
        }
    }

    private function boundedReceiver(?string $receiver, Node $site, string $owner): ?string
    {
        if ($receiver !== null && strlen($receiver) > 1000) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Attribute receiver reached its descriptor budget.', max(1, $site->getStartLine()), $owner);

            return null;
        }

        return $receiver;
    }

    private function object(Expr $node, string $owner): ?string
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            return $this->objects[$owner.'#'.$node->name] ?? $owner.'#'.$node->name;
        }
        if ($node instanceof Expr\New_) {
            return $owner.'#new:'.$node->getStartFilePos();
        }
        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $object = $this->object($node->var, $owner);

            $property = $object === null ? null : $object.'#property:'.$node->name->toString();

            return $property === null ? null : ($this->objects[$property] ?? $property);
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier
            && in_array(strtolower($node->name->toString()), CatalogSerializationChanges::METHODS, true)) {
            return $this->object($node->var, $owner);
        }

        return null;
    }

    /** @param array<string, string> $vars */
    private function children(FileContext $file, Node $node, string $owner, string $class, array &$vars): void
    {
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner, $class, $vars);
                }
            }
        }
    }

    /** @param array<string, string> $vars */
    private function receiver(FileContext $file, Expr $node, string $class, array $vars): ?string
    {
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && ! $node->isFirstClassCallable() && $node->args === []) {
            $resolved = $file->resolvedName($node->name);
            $namespaced = $node->name->getAttribute('namespacedName');
            $functions = array_values(array_unique($namespaced instanceof Node\Name ? [$namespaced->toString(), $resolved] : [$resolved]));
            if (in_array('response', array_map('strtolower', $functions), true)) {
                return '@response-factory:'.json_encode($functions, JSON_THROW_ON_ERROR);
            }
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && in_array(strtolower($node->name->toString()), CatalogSerializationChanges::METHODS, true)) {
            return $this->receiver($file, $node->var, $class, $vars);
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            return $vars[$node->name] ?? null;
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            return in_array(strtolower($node->class->toString()), ['self', 'static'], true) ? ($class === '' ? null : $class) : $file->resolvedName($node->class);
        }
        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier && $node->var instanceof Expr\Variable && is_string($node->var->name) && isset($vars[$node->var->name])) {
            return '@property:'.$vars[$node->var->name].'#'.$node->name->toString();
        }

        return null;
    }
}
