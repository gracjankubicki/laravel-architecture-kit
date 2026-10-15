<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Pool member class selectors share the existing AST; constructor payloads are omitted. */
final class SaloonPoolCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, string> */
    private array $owners = [];

    private int $visited = 0;

    /** @var list<array{offset: int, end_offset: int}> */
    private array $promiseSites = [];

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'interface', 'trait', 'enum', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element->id;
            }
        }
        foreach ($file->syntax() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path));
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner, bool $conditional = false): void
    {
        if ($this->visited >= 25000) {
            return;
        }
        if (++$this->visited >= 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->visited = 25000;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Saloon pool extraction reached its node or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
            $conditional = false;
        }
        $conditional = $conditional || Node::is($node, 'statement.if') || Node::is($node, 'statement.else-if') || Node::is($node, 'statement.else')
            || Node::is($node, 'statement.switch') || Node::is($node, 'statement.for') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while')
            || Node::is($node, 'statement.do') || Node::is($node, 'statement.try-catch') || Node::is($node, 'expression.ternary')
            || Node::is($node, 'expression.binary-op.boolean-and') || Node::is($node, 'expression.binary-op.boolean-or')
            || Node::is($node, 'expression.binary-op.logical-and') || Node::is($node, 'expression.binary-op.logical-or') || Node::is($node, 'expression.binary-op.coalesce')
            || Node::is($node, 'expression.nullsafe-method-call');
        if ((Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call')) && Node::is($node->name, 'identifier') && in_array(strtolower($node->name->toString()), ['pool', ...array_keys(CatalogSaloonOperations::POOL_SETTERS)], true) && ! $node->isFirstClassCallable()
            || Node::is($node, 'expression.new') && CatalogSaloonOperations::poolConstructor($file, $node)) {
            $arguments = [];
            $this->promiseSites = [];
            $constructor = Node::is($node, 'expression.new');
            $form = $constructor ? 'new' : strtolower($node->name->toString());
            $setter = CatalogSaloonOperations::POOL_SETTERS[$form] ?? null;
            $names = $setter !== null ? [$setter[0]] : [...($constructor ? ['connector'] : []), 'requests', 'concurrency', 'responseHandler', 'exceptionHandler'];
            $valid = count($node->args) <= count($names);
            $named = false;
            foreach ($node->getArgs() as $position => $argument) {
                $name = $argument->name?->toString() ?? ($names[$position] ?? 'unknown');
                $valid = $valid && ! $argument->unpack && ! $argument->byRef && in_array($name, $names, true)
                    && ! isset($arguments[$name]) && (! $named || $argument->name !== null);
                $named = $named || $argument->name !== null;
                $arguments[$name] = $argument->value;
            }
            $valid = $valid && (! $constructor || isset($arguments['connector'])) && ($setter === null || isset($arguments[$setter[0]]));
            $callbacks = [];
            foreach ($setter === null ? ['concurrency' => 'concurrency', 'response' => 'responseHandler', 'exception' => 'exceptionHandler'] : ($setter[1] === null ? [] : [$setter[1] => $setter[0]]) as $kind => $parameter) {
                $callback = $this->callback($arguments[$parameter] ?? null, $kind, $setter !== null);
                $valid = $valid && $callback['valid'];
                $callbacks[$kind] = ['id' => $callback['id'], 'resolved' => $callback['resolved']];
            }
            $requests = $arguments['requests'] ?? null;
            $factory = Node::is($requests, 'expression.closure') || Node::is($requests, 'expression.arrow-function') ? ($this->owners[$requests->getStartFilePos()] ?? null) : null;
            $generator = false;
            $targets = [];
            $resolved = $requests === null;
            if (Node::is($requests, 'expression.array')) {
                [$targets, $resolved] = $this->objects($file, $requests);
            } elseif (Node::is($requests, 'expression.arrow-function')) {
                [$generator, $complete] = $this->generator($requests, $owner);
                if (Node::is($requests->expr, 'expression.yield')) {
                    $type = $this->object($file, $requests->expr->value);
                    $targets = $type === null ? [] : [$type];
                    $resolved = $complete && $type !== null;
                } else {
                    [$targets, $resolved] = $this->objects($file, $requests->expr);
                    $resolved = $resolved && $complete && ! $generator;
                }
            } elseif (Node::is($requests, 'expression.closure')) {
                [$generator, $resolved] = $this->generator($requests, $owner);
                $returned = false;
                foreach ($requests->stmts as $statement) {
                    if (Node::is($statement, 'statement.return')) {
                        if (! $generator) {
                            [$members, $complete] = $this->objects($file, $statement->expr);
                            $targets = [...$targets, ...$members];
                            $resolved = $resolved && $complete && ! $returned;
                        }
                        $returned = true;
                    } elseif (Node::is($statement, 'statement.expression') && Node::is($statement->expr, 'expression.yield')) {
                        $generator = true;
                        $type = $this->object($file, $statement->expr->value);
                        $resolved = $resolved && $type !== null;
                        if ($type !== null) {
                            $targets[] = $type;
                        }
                    } elseif (! Node::is($statement, 'statement.nop')) {
                        // Unmodeled statements can change the returned members or yield conditionally.
                        $resolved = false;
                    }
                }
                $resolved = $resolved && ($returned || $generator);
            }
            if (count($targets) + count($this->promiseSites) > 128) {
                $targets = array_slice($targets, 0, 128);
                $this->promiseSites = array_slice($this->promiseSites, 0, 128 - count($targets));
                $resolved = false;
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Saloon pool member list exceeds 128 source candidates.', max(1, $node->getStartLine()), $owner, limitReason: 'structure');
            }
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'saloon-pool-site', 'pool:'.max(0, $node->getEndFilePos()), $offset), 'Saloon pool source', 'saloon-pool-site',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['end_offset' => max(0, $node->getEndFilePos()), 'valid' => $valid, 'targets' => array_values(array_unique($targets)),
                    'resolved' => $resolved, 'factory' => $factory, 'generator' => $generator, 'form' => $form, 'conditional' => $conditional, 'callbacks' => $callbacks,
                    'promise_sites' => $this->promiseSites]);
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner, $conditional);
                }
            }
        }
    }

    /** @return array{id: ?string, resolved: bool, valid: bool} */
    private function callback(?Node $value, string $kind, bool $required): array
    {
        if ($value === null || ! $required && $kind !== 'concurrency' && Node::is($value, 'expression.const-fetch') && strtolower($value->name->toString()) === 'null'
            || $kind === 'concurrency' && Node::is($value, 'scalar.int')) {
            return ['id' => null, 'resolved' => true, 'valid' => true];
        }
        if (Node::is($value, 'expression.closure') || Node::is($value, 'expression.arrow-function')) {
            $id = $this->owners[$value->getStartFilePos()] ?? null;
            [$generator, $complete] = $this->generator($value, $id ?? '');
            if ($generator || ! $complete) {
                // Pool handlers do not consume the Generator returned by a generator callback.
                return ['id' => null, 'resolved' => false, 'valid' => true];
            }

            return ['id' => $id, 'resolved' => $id !== null, 'valid' => true];
        }
        $invalid = Node::is($value, 'scalar') && ! Node::is($value, 'scalar.string')
            || Node::is($value, 'expression.const-fetch') && in_array(strtolower($value->name->toString()), ['null', 'true', 'false'], true);

        return ['id' => null, 'resolved' => false, 'valid' => ! $invalid];
    }

    /** @return array{list<string>, bool} */
    private function objects(FileContext $file, ?Node $node): array
    {
        if (Node::is($node, 'expression.array') && count($node->items) > 128) {
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Saloon pool member list exceeds 128 source candidates.', max(1, $node->getStartLine()), limitReason: 'structure');

            return [[], false];
        }
        if (! Node::is($node, 'expression.array')) {
            return [[], false];
        }
        if (! CatalogSaloonOperations::poolMemberArray($node)) {
            return [[], false];
        }
        $targets = [];
        $resolved = true;
        foreach ($node->items as $item) {
            $before = count($this->promiseSites);
            $type = $item !== null && ! $item->unpack && ! $item->byRef ? $this->object($file, $item->value) : null;
            $resolved = $resolved && ($type !== null || count($this->promiseSites) > $before);
            if ($type !== null) {
                $targets[] = $type;
            }
        }

        return [$targets, $resolved];
    }

    /** Detect yields in this callable, excluding nested callable/class bodies.
     * @return array{bool, bool}
     */
    private function generator(Node $callable, string $owner): array
    {
        $pending = Node::is($callable, 'expression.arrow-function') ? [$callable->expr] : $callable->stmts;
        $generator = false;
        $visited = 0;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (++$visited > 10000 || $visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Saloon pool factory inspection reached its node or memory budget.', max(1, $callable->getStartLine()), $owner);

                // Unknown generator status must never allow immediate callback execution.
                return [true, false];
            }
            if (Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function') || Node::is($node, 'statement.class-like') || Node::is($node, 'statement.function')) {
                continue;
            }
            if (Node::is($node, 'expression.yield') || Node::is($node, 'expression.yield-from')) {
                $generator = true;
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if (Node::is($child, 'node')) {
                        $pending[] = $child;
                    }
                }
            }
        }

        return [$generator, true];
    }

    private function object(FileContext $file, ?Node $node): ?string
    {
        if ((Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call')) && Node::is($node->name, 'identifier')
            && strcasecmp($node->name->toString(), 'sendAsync') === 0 && ! $node->isFirstClassCallable()) {
            if (count($this->promiseSites) < 128) {
                $this->promiseSites[] = ['offset' => max(0, $node->getStartFilePos()), 'end_offset' => max(0, $node->getEndFilePos())];
            }

            return null;
        }
        if (! Node::is($node, 'expression.new') || ! Node::is($node->class, 'name') || in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $type = $file->resolvedName($node->class);

        return strlen($type) <= 500 && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) === 1 ? $type : null;
    }
}
