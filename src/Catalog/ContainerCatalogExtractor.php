<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Framework\ContainerBindingResolver;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkValue;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Stores local registrations, deferring provider ancestry and competing bindings to composition. */
final class ContainerCatalogExtractor
{
    /** @var array<int, CatalogElement> */
    private array $owners = [];

    /** @var list<CatalogRelation> */
    private array $relations = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visits = 0;

    private bool $limited = false;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->owners = $this->relations = $this->diagnostics = [];
        $this->visits = 0;
        $this->limited = false;
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'interface', 'trait', 'enum', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element;
            }
        }
        if (count($php->elements) === 1 && array_filter($php->diagnostics, fn ($diagnostic) => in_array($diagnostic->code, ['source_limit', 'parse_error', 'catalog_limit'], true)) !== []) {
            return new CatalogFacts($file->path);
        }
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path));
        }

        return new CatalogFacts($file->path, relations: $this->relations, diagnostics: $this->diagnostics);
    }

    /** @param array<string, string> $types */
    private function visit(FileContext $file, Node $node, string $owner, ?SourceClass $source = null, array $types = [], bool $hasThis = false): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visits > 25000 || ($this->visits % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null)) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('container_limit', 'Container source analysis reached its node or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike) {
            $element = $this->owners[$node->getStartFilePos()] ?? null;
            if ($element !== null) {
                $owner = $element->id;
                $source = new SourceClass($element->name, $file, $node);
            }
            $types = [];
            $hasThis = false;
        } elseif ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
            if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
                $types = [];
                $hasThis = $node instanceof Stmt\ClassMethod && ! $node->isStatic();
            } else {
                // Reassigned captured variables cannot prove a container receiver.
                $types = [];
                $hasThis = $hasThis && ! $node->static;
            }
            foreach ($node->params as $param) {
                if ($param->type instanceof Node\Name && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $types[$param->var->name] = $file->resolvedName($param->type);
                }
            }
            // Any reassignment in the callable invalidates that receiver conservatively.
            $this->invalidateAssigned($node, $types);
        }
        if (($node instanceof Expr\StaticCall || $node instanceof Expr\MethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $method = strtolower($node->name->toString());
            if (in_array($method, ['bind', 'singleton', 'scoped', 'instance', 'bindif', 'singletonif', 'scopedif'], true)) {
                $receiver = $this->receiver($file, $node, $source, $types, $hasThis);
                if ($receiver !== null) {
                    $this->binding($file, $node, $owner, $source, $receiver, $method);
                }
            } elseif ($method === 'give' && $node instanceof Expr\MethodCall && $node->var instanceof Expr\MethodCall
                && $node->var->name instanceof Node\Identifier && strtolower($node->var->name->toString()) === 'needs'
                && (($when = $node->var->var) instanceof Expr\MethodCall || $when instanceof Expr\StaticCall) && $when->name instanceof Node\Identifier && strtolower($when->name->toString()) === 'when') {
                $receiver = $this->receiver($file, $when, $source, $types, $hasThis);
                if ($receiver !== null) {
                    $contexts = $this->names($file, $this->argument($when, 0, 'concrete'));
                    $this->binding($file, $node, $owner, $source, $receiver, 'contextual', $this->argument($node->var, 0, 'abstract'), $contexts);
                }
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner, $source, $types, $hasThis);
                }
            }
        }
    }

    /** @param array<string, string> $types */
    private function invalidateAssigned(Node $node, array &$types, int $depth = 0): void
    {
        if ($depth >= 64) {
            $types = [];

            return;
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (! $child instanceof Node || $child instanceof Stmt\ClassLike || $child instanceof Stmt\Function_ || $child instanceof Expr\Closure || $child instanceof Expr\ArrowFunction) {
                    continue;
                }
                if (($child instanceof Expr\Assign || $child instanceof Expr\AssignRef || $child instanceof Expr\AssignOp || $child instanceof Stmt\Unset_ || $child instanceof Stmt\Foreach_) && $types !== []) {
                    // Only type parameters are supported here; mutation makes them uncertain.
                    $types = [];

                    return;
                }
                $this->invalidateAssigned($child, $types, $depth + 1);
            }
        }
    }

    /** @param array<string, string> $types */
    private function receiver(FileContext $file, Expr\StaticCall|Expr\MethodCall $call, ?SourceClass $source, array $types, bool $hasThis): ?string
    {
        if ($call instanceof Expr\StaticCall) {
            return $call->class instanceof Node\Name && strtolower($file->resolvedName($call->class)) === 'illuminate\\support\\facades\\app' ? 'framework' : null;
        }
        $receiver = $call->var;
        if ($receiver instanceof Expr\FuncCall && $receiver->name instanceof Node\Name && strtolower($file->resolvedName($receiver->name)) === 'app' && $receiver->getArgs() === []) {
            return 'helper';
        }
        if ($receiver instanceof Expr\Variable && is_string($receiver->name) && isset($types[$receiver->name])) {
            return 'type:'.$types[$receiver->name];
        }
        if ($receiver instanceof Expr\PropertyFetch && $receiver->name instanceof Node\Identifier && $receiver->name->toString() === 'app'
            && $receiver->var instanceof Expr\Variable && $receiver->var->name === 'this' && $source !== null && $hasThis) {
            return 'provider:'.$source->name;
        }

        return null;
    }

    /** @param list<string>|null $contexts */
    private function binding(FileContext $file, Expr\StaticCall|Expr\MethodCall $call, string $owner, ?SourceClass $source, string $receiver, string $mode, ?Node $abstract = null, ?array $contexts = []): void
    {
        $abstract ??= $mode === 'contextual' ? null : $this->argument($call, 0, 'abstract');
        $implementation = $this->argument($call, $mode === 'contextual' ? 0 : 1, $mode === 'contextual' ? 'implementation' : ($mode === 'instance' ? 'instance' : 'concrete'));
        $callback = $implementation instanceof Expr\Closure || $implementation instanceof Expr\ArrowFunction ? $implementation : null;
        if ($abstract instanceof Expr\Closure || $abstract instanceof Expr\ArrowFunction) {
            $callback = $abstract;
            $abstract = null;
        }
        $abstracts = $abstract instanceof Expr\Array_ ? [] : ($this->names($file, $abstract) ?? []);
        $implementations = $mode === 'instance' || $implementation instanceof Expr\Array_ ? [] : ($this->names($file, $implementation) ?? []);
        $unknown = false;
        if ($callback !== null) {
            $callbackSource = $source ?? new SourceClass('(source)', $file, new Stmt\Class_('__Source'));
            $factory = FrameworkValue::callback($callbackSource, $callback, []);
            $resolver = new ContainerBindingResolver;
            if ($abstract === null) {
                $abstracts = $resolver->contracts($factory);
            }
            $result = $resolver->resolve($factory);
            $implementations = $result->typeNames();
            $unknown = $result->isUnknown() || $result->nullable;
        } elseif ($implementation instanceof Expr\New_ && $implementation->class instanceof Node\Name) {
            $implementations = [$file->resolvedName($implementation->class)];
        } elseif ($implementation === null && $mode !== 'instance' && $mode !== 'contextual') {
            $implementations = $abstracts;
        }
        if ($mode === 'contextual' && ($abstracts === [] || array_filter($abstracts, fn ($name) => str_starts_with($name, '$')) !== [])) {
            // Primitive contextual values are payloads, not implementation identifiers.
            $implementations = [];
        }
        $metadata = ['receiver' => $receiver, 'mode' => $mode, 'abstracts' => $abstracts, 'implementations' => $implementations,
            'contexts' => $contexts ?? [], 'context_unknown' => $contexts === null, 'factory_unknown' => $unknown,
            'offset' => max(0, $call->getStartFilePos()), 'callback' => $callback !== null ? ($this->owners[$callback->getStartFilePos()]->id ?? null) : null];
        $this->relations[] = new CatalogRelation($owner, 'container:'.hash('xxh128', serialize($metadata)), 'container-registration', max(1, $call->getStartLine()), max(1, $call->getEndLine()), 'conditional', $metadata);
    }

    private function argument(Expr\CallLike $call, int $position, string $name): ?Node
    {
        foreach ($call->getArgs() as $index => $argument) {
            if ($argument instanceof Node\Arg && ! $argument->unpack && ($argument->name?->toString() === $name || $argument->name === null && $index === $position)) {
                return $argument->value;
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function names(FileContext $file, ?Node $node, int $depth = 0): ?array
    {
        if ($depth >= 16) {
            return null;
        }
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'class') {
            return [$file->resolvedName($node->class)];
        }
        if ($node instanceof Node\Scalar\String_ && $node->value !== '' && strlen($node->value) <= 200) {
            return [$node->value];
        }
        if ($node instanceof Expr\Array_ && count($node->items) <= 128) {
            $names = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack || ($values = $this->names($file, $item->value, $depth + 1)) === null) {
                    return null;
                }
                array_push($names, ...$values);
                if (count($names) > 128) {
                    return null;
                }
            }

            return $names;
        }

        return null;
    }
}
