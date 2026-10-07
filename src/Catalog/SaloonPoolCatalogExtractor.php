<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

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
        foreach ($file->ast() ?? [] as $node) {
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
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
            $conditional = false;
        }
        $conditional = $conditional || $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_
            || $node instanceof Stmt\Switch_ || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch || $node instanceof Expr\Ternary
            || $node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\LogicalAnd || $node instanceof Expr\BinaryOp\LogicalOr || $node instanceof Expr\BinaryOp\Coalesce
            || $node instanceof Expr\NullsafeMethodCall;
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), ['pool', ...array_keys(CatalogSaloonOperations::POOL_SETTERS)], true) && ! $node->isFirstClassCallable()
            || $node instanceof Expr\New_ && CatalogSaloonOperations::poolConstructor($file, $node)) {
            $arguments = [];
            $this->promiseSites = [];
            $constructor = $node instanceof Expr\New_;
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
            $factory = $requests instanceof Expr\Closure || $requests instanceof Expr\ArrowFunction ? ($this->owners[$requests->getStartFilePos()] ?? null) : null;
            $generator = false;
            $targets = [];
            $resolved = $requests === null;
            if ($requests instanceof Expr\Array_) {
                [$targets, $resolved] = $this->objects($file, $requests);
            } elseif ($requests instanceof Expr\ArrowFunction) {
                [$generator, $complete] = $this->generator($requests, $owner);
                if ($requests->expr instanceof Expr\Yield_) {
                    $type = $this->object($file, $requests->expr->value);
                    $targets = $type === null ? [] : [$type];
                    $resolved = $complete && $type !== null;
                } else {
                    [$targets, $resolved] = $this->objects($file, $requests->expr);
                    $resolved = $resolved && $complete && ! $generator;
                }
            } elseif ($requests instanceof Expr\Closure) {
                [$generator, $resolved] = $this->generator($requests, $owner);
                $returned = false;
                foreach ($requests->stmts as $statement) {
                    if ($statement instanceof Stmt\Return_) {
                        if (! $generator) {
                            [$members, $complete] = $this->objects($file, $statement->expr);
                            $targets = [...$targets, ...$members];
                            $resolved = $resolved && $complete && ! $returned;
                        }
                        $returned = true;
                    } elseif ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Yield_) {
                        $generator = true;
                        $type = $this->object($file, $statement->expr->value);
                        $resolved = $resolved && $type !== null;
                        if ($type !== null) {
                            $targets[] = $type;
                        }
                    } elseif (! $statement instanceof Stmt\Nop) {
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
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner, $conditional);
                }
            }
        }
    }

    /** @return array{id: ?string, resolved: bool, valid: bool} */
    private function callback(?Node $value, string $kind, bool $required): array
    {
        if ($value === null || ! $required && $kind !== 'concurrency' && $value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null'
            || $kind === 'concurrency' && $value instanceof Node\Scalar\Int_) {
            return ['id' => null, 'resolved' => true, 'valid' => true];
        }
        if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
            $id = $this->owners[$value->getStartFilePos()] ?? null;
            [$generator, $complete] = $this->generator($value, $id ?? '');
            if ($generator || ! $complete) {
                // Pool handlers do not consume the Generator returned by a generator callback.
                return ['id' => null, 'resolved' => false, 'valid' => true];
            }

            return ['id' => $id, 'resolved' => $id !== null, 'valid' => true];
        }
        $invalid = $value instanceof Node\Scalar && ! $value instanceof Node\Scalar\String_
            || $value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['null', 'true', 'false'], true);

        return ['id' => null, 'resolved' => false, 'valid' => ! $invalid];
    }

    /** @return array{list<string>, bool} */
    private function objects(FileContext $file, ?Node $node): array
    {
        if ($node instanceof Expr\Array_ && count($node->items) > 128) {
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Saloon pool member list exceeds 128 source candidates.', max(1, $node->getStartLine()), limitReason: 'structure');

            return [[], false];
        }
        if (! $node instanceof Expr\Array_) {
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
    private function generator(Expr\Closure|Expr\ArrowFunction $callable, string $owner): array
    {
        $pending = $callable instanceof Expr\ArrowFunction ? [$callable->expr] : $callable->stmts;
        $generator = false;
        $visited = 0;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (++$visited > 10000 || $visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Saloon pool factory inspection reached its node or memory budget.', max(1, $callable->getStartLine()), $owner);

                // Unknown generator status must never allow immediate callback execution.
                return [true, false];
            }
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                continue;
            }
            if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
                $generator = true;
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $pending[] = $child;
                    }
                }
            }
        }

        return [$generator, true];
    }

    private function object(FileContext $file, ?Node $node): ?string
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier
            && strcasecmp($node->name->toString(), 'sendAsync') === 0 && ! $node->isFirstClassCallable()) {
            if (count($this->promiseSites) < 128) {
                $this->promiseSites[] = ['offset' => max(0, $node->getStartFilePos()), 'end_offset' => max(0, $node->getEndFilePos())];
            }

            return null;
        }
        if (! $node instanceof Expr\New_ || ! $node->class instanceof Node\Name || in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $type = $file->resolvedName($node->class);

        return strlen($type) <= 500 && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) === 1 ? $type : null;
    }
}
