<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Framework\ContainerBindingResolver;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkValue;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

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
        foreach ($file->syntax() ?? [] as $node) {
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
        if (Node::is($node, 'statement.class-like')) {
            $element = $this->owners[$node->getStartFilePos()] ?? null;
            if ($element !== null) {
                $owner = $element->id;
                $source = new SourceClass($element->name, $file, $node);
            }
            $types = [];
            $hasThis = false;
        } elseif (Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
            if (Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function')) {
                $types = [];
                $hasThis = Node::is($node, 'statement.class-method') && ! $node->isStatic();
            } else {
                // Reassigned captured variables cannot prove a container receiver.
                $types = [];
                $hasThis = $hasThis && ! $node->static;
            }
            foreach ($node->params as $param) {
                if (Node::is($param->type, 'name') && Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
                    $types[$param->var->name] = $file->resolvedName($param->type);
                }
            }
            // Any reassignment in the callable invalidates that receiver conservatively.
            $this->invalidateAssigned($node, $types);
        }
        if ((Node::is($node, 'expression.static-call') || Node::is($node, 'expression.method-call')) && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()) {
            $method = strtolower($node->name->toString());
            if (in_array($method, ['bind', 'singleton', 'scoped', 'instance', 'bindif', 'singletonif', 'scopedif'], true)) {
                $receiver = $this->receiver($file, $node, $source, $types, $hasThis);
                if ($receiver !== null) {
                    $this->binding($file, $node, $owner, $source, $receiver, $method);
                }
            } elseif ($method === 'give' && Node::is($node, 'expression.method-call') && Node::is($node->var, 'expression.method-call')
                && Node::is($node->var->name, 'identifier') && strtolower($node->var->name->toString()) === 'needs'
                && (Node::is(($when = $node->var->var), 'expression.method-call') || Node::is($when, 'expression.static-call')) && Node::is($when->name, 'identifier') && strtolower($when->name->toString()) === 'when') {
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
                if (Node::is($child, 'node')) {
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
                if (! Node::is($child, 'node') || Node::is($child, 'statement.class-like') || Node::is($child, 'statement.function') || Node::is($child, 'expression.closure') || Node::is($child, 'expression.arrow-function')) {
                    continue;
                }
                if ((Node::is($child, 'expression.assign') || Node::is($child, 'expression.assign-ref') || Node::is($child, 'expression.assign-op') || Node::is($child, 'statement.unset') || Node::is($child, 'statement.foreach')) && $types !== []) {
                    // Only type parameters are supported here; mutation makes them uncertain.
                    $types = [];

                    return;
                }
                $this->invalidateAssigned($child, $types, $depth + 1);
            }
        }
    }

    /** @param array<string, string> $types */
    private function receiver(FileContext $file, Node $call, ?SourceClass $source, array $types, bool $hasThis): ?string
    {
        if (Node::is($call, 'expression.static-call')) {
            return Node::is($call->class, 'name') && strtolower($file->resolvedName($call->class)) === 'illuminate\\support\\facades\\app' ? 'framework' : null;
        }
        $receiver = $call->var;
        if (Node::is($receiver, 'expression.func-call') && Node::is($receiver->name, 'name') && strtolower($file->resolvedName($receiver->name)) === 'app' && $receiver->getArgs() === []) {
            return 'helper';
        }
        if (Node::is($receiver, 'expression.variable') && is_string($receiver->name) && isset($types[$receiver->name])) {
            return 'type:'.$types[$receiver->name];
        }
        if (Node::is($receiver, 'expression.property-fetch') && Node::is($receiver->name, 'identifier') && $receiver->name->toString() === 'app'
            && Node::is($receiver->var, 'expression.variable') && $receiver->var->name === 'this' && $source !== null && $hasThis) {
            return 'provider:'.$source->name;
        }

        return null;
    }

    /** @param list<string>|null $contexts */
    private function binding(FileContext $file, Node $call, string $owner, ?SourceClass $source, string $receiver, string $mode, ?Node $abstract = null, ?array $contexts = []): void
    {
        $abstract ??= $mode === 'contextual' ? null : $this->argument($call, 0, 'abstract');
        $implementation = $this->argument($call, $mode === 'contextual' ? 0 : 1, $mode === 'contextual' ? 'implementation' : ($mode === 'instance' ? 'instance' : 'concrete'));
        $callback = Node::is($implementation, 'expression.closure') || Node::is($implementation, 'expression.arrow-function') ? $implementation : null;
        if (Node::is($abstract, 'expression.closure') || Node::is($abstract, 'expression.arrow-function')) {
            $callback = $abstract;
            $abstract = null;
        }
        $abstracts = Node::is($abstract, 'expression.array') ? [] : ($this->names($file, $abstract) ?? []);
        $implementations = $mode === 'instance' || Node::is($implementation, 'expression.array') ? [] : ($this->names($file, $implementation) ?? []);
        $unknown = false;
        if ($callback !== null) {
            $callbackSource = $source ?? new SourceClass('(source)', $file, Derived::classContext($callback, '__Source'));
            $factory = FrameworkValue::callback($callbackSource, $callback, []);
            $resolver = new ContainerBindingResolver;
            if ($abstract === null) {
                $abstracts = $resolver->contracts($factory);
            }
            $result = $resolver->resolve($factory);
            $implementations = $result->typeNames();
            $unknown = $result->isUnknown() || $result->nullable;
        } elseif (Node::is($implementation, 'expression.new') && Node::is($implementation->class, 'name')) {
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

    private function argument(Node $call, int $position, string $name): ?Node
    {
        foreach ($call->getArgs() as $index => $argument) {
            if (Node::is($argument, 'arg') && ! $argument->unpack && ($argument->name?->toString() === $name || $argument->name === null && $index === $position)) {
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
        if (Node::is($node, 'expression.class-const-fetch') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier') && strtolower($node->name->toString()) === 'class') {
            return [$file->resolvedName($node->class)];
        }
        if (Node::is($node, 'scalar.string') && $node->value !== '' && strlen($node->value) <= 200) {
            return [$node->value];
        }
        if (Node::is($node, 'expression.array') && count($node->items) <= 128) {
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
