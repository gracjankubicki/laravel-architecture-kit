<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Source factory chains retain callback identities, never attribute/state payloads. */
final class FactoryLifecycleCatalogExtractor
{
    public const TERMINALS = ['make', 'makeone', 'create', 'createone', 'createquietly', 'createonequietly'];

    public const PREPARATIONS = ['count', 'state', 'aftermaking', 'aftercreating', 'withoutaftermaking', 'withoutaftercreating', 'connection', 'has', 'for', 'hasattached'];

    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    private int $visited = 0;

    private bool $limited = false;

    private int $pipelines = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        $this->limited = false;
        $this->pipelines = 0;
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'trait', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element;
            }
        }
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path), '');
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner, string $class): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Factory lifecycle reached its AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (isset($this->owners[$node->getStartFilePos()]) && ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction)) {
            $declaration = $this->owners[$node->getStartFilePos()];
            $owner = $declaration->id;
            if ($node instanceof Stmt\ClassLike) {
                $class = $declaration->name;
            }
        }
        if ($node instanceof Stmt\ClassMethod && ! $node->isStatic()
            && count($node->stmts ?? []) === 1 && $node->stmts[0] instanceof Stmt\Return_
            && ($node->stmts[0]->expr instanceof Expr\MethodCall || $node->stmts[0]->expr instanceof Expr\NullsafeMethodCall || $node->stmts[0]->expr instanceof Expr\Variable)) {
            $signatureValid = array_filter($node->params, fn ($param) => $param->default === null && ! $param->variadic) === [];
            $this->operation($file, $node->stmts[0]->expr, $owner, $class, configuration: true, signatureValid: $signatureValid, configurationName: strtolower($node->name->toString()));
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier
            && in_array(strtolower($node->name->toString()), self::TERMINALS, true) && ! $node->isFirstClassCallable()) {
            $this->operation($file, $node, $owner, $class);
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner, $class);
                }
            }
        }
    }

    private function operation(FileContext $file, Expr\MethodCall|Expr\NullsafeMethodCall|Expr\Variable $site, string $owner, string $class, bool $configuration = false, bool $signatureValid = true, string $configurationName = 'configure', bool $related = false, int $depth = 0): ?string
    {
        if (++$this->pipelines > 4096 || $depth >= 32) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Factory relationships reached their source pipeline or depth budget.', max(1, $site->getStartLine()), $owner);

            return null;
        }
        $chain = [];
        $root = $site;
        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            if (count($chain) >= 64 || ! $root->name instanceof Node\Identifier || $root->isFirstClassCallable()) {
                return null;
            }
            array_unshift($chain, $root);
            $root = $root->var;
        }
        if ($configuration) {
            if (! $root instanceof Expr\Variable || $root->name !== 'this') {
                return null;
            }
            $receiver = $class;
            $resolved = $signatureValid;
            $calls = $chain;
        } else {
            if (! $root instanceof Expr\StaticCall || ! $root->class instanceof Node\Name || ! $root->name instanceof Node\Identifier || $root->isFirstClassCallable()
                || ! in_array(strtolower($root->name->toString()), ['new', 'factory'], true)) {
                return null;
            }
            $receiver = strtolower($root->class->toString()) === 'self' ? $class : $file->resolvedName($root->class);
            $resolved = ! in_array(strtolower($root->class->toString()), ['static', 'parent'], true);
            $calls = [$root, ...$chain];
        }
        if ($receiver === '') {
            $this->diagnostics[] = new CatalogDiagnostic('call_analysis', 'Factory chain has no lexical self receiver.', max(1, $site->getStartLine()), $owner);

            return null;
        }
        $callbacks = [];
        $cleared = [];
        $instances = true;
        $countOverridden = false;
        $methods = $configuration ? [$configurationName] : [];
        $steps = [];
        foreach ($calls as $call) {
            $method = strtolower($call->name->toString());
            $stepCallbacks = [];
            $connection = null;
            $relationship = null;
            $methods[] = $method;
            $parameters = match ($method) {
                'new' => ['attributes'], 'factory' => ['count', 'state'], 'count' => ['count'], 'state' => ['state'],
                'connection' => ['connection'],
                'has', 'for' => ['factory', 'relationship'], 'hasattached' => ['factory', 'pivot', 'relationship'],
                'aftermaking', 'aftercreating' => ['callback'], 'withoutaftermaking', 'withoutaftercreating' => [],
                'makeone', 'createone', 'createonequietly' => ['attributes'],
                'make', 'create', 'createquietly' => ['attributes', 'parent'], default => [],
            };
            $seen = [];
            foreach ($call->getArgs() as $position => $arg) {
                $parameter = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                if ($arg->unpack || ! in_array($parameter, $parameters, true) || isset($seen[$parameter])) {
                    $resolved = false;
                }
                $seen[$parameter] = true;
            }
            if (in_array($method, ['has', 'for', 'hasattached'], true)) {
                $argument = $this->argument($call, 'factory');
                $name = $this->argument($call, 'relationship', $method === 'hasattached' ? 2 : 1);
                $literal = $name instanceof Node\Scalar\String_ && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,255}\z/D', $name->value) === 1 ? $name->value : null;
                $default = $name === null || $name instanceof Expr\ConstFetch && strtolower($name->name->toString()) === 'null';
                $target = null;
                if ($argument instanceof Expr\StaticCall || $argument instanceof Expr\MethodCall) {
                    $terminal = new Expr\MethodCall($argument, 'create', [], $argument->getAttributes());
                    $target = $this->operation($file, $terminal, $owner, $class, related: true, depth: $depth + 1);
                }
                if ($target === null || ! $default && $literal === null) {
                    $resolved = false;
                } else {
                    $relationship = ['factory' => $target, 'name' => $literal];
                }
                if ($method === 'hasattached') {
                    $pivot = $this->argument($call, 'pivot', 1);
                    // Source callable and sequence pivot forms require their own lifecycle composition.
                    if ($pivot !== null && ! $pivot instanceof Expr\Array_) {
                        $resolved = false;
                    }
                }
            } elseif ($method === 'connection') {
                $argument = $this->argument($call, 'connection');
                if ($argument instanceof Node\Scalar\String_ && preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $argument->value) === 1) {
                    $connection = ['kind' => 'named', 'name' => $argument->value];
                } elseif ($argument instanceof Expr\ConstFetch && strtolower($argument->name->toString()) === 'null') {
                    $connection = ['kind' => 'model', 'name' => null];
                } else {
                    $resolved = false;
                }
            } elseif (in_array($method, ['withoutaftermaking', 'withoutaftercreating'], true)) {
                $stage = $method === 'withoutaftermaking' ? 'aftermaking' : 'aftercreating';
                $cleared[] = $stage;
                $callbacks = array_values(array_filter($callbacks, fn ($row) => $row['stage'] !== $stage));
                $resolved = $resolved && $call->getArgs() === [];
            } elseif (in_array($method, ['state', 'aftermaking', 'aftercreating'], true)) {
                $argument = $this->argument($call, $method === 'state' ? 'state' : 'callback');
                $target = $argument === null ? null : ($this->owners[$argument->getStartFilePos()] ?? null);
                if (($argument instanceof Expr\Closure || $argument instanceof Expr\ArrowFunction) && $target?->kind === 'closure') {
                    $resolved = $resolved && ($method !== 'state' || ! $argument->static);
                    $callbacks[] = $stepCallbacks[] = ['stage' => $method, 'target' => $target->id, 'line' => max(1, $call->getStartLine()), 'end_line' => max(1, $call->getEndLine())];
                } elseif ($method !== 'state' || ! $argument instanceof Expr\Array_) {
                    $resolved = false;
                }
            } elseif ($method === 'count' || $method === 'factory') {
                $countOverridden = true;
                $count = $this->argument($call, 'count');
                $instances = true;
                if ($count instanceof Node\Scalar\Int_) {
                    $instances = $count->value > 0;
                } elseif ($count instanceof Expr\UnaryMinus && $count->expr instanceof Node\Scalar\Int_) {
                    $instances = -$count->expr->value > 0;
                }
                // HasFactory passes numeric literals through its weakly typed count(int).
                // Fractions truncate before Factory::make checks whether count is below one.
                if ($method === 'factory') {
                    $numeric = $count instanceof Node\Scalar\Float_ || $count instanceof Node\Scalar\String_
                        ? $count->value : null;
                    if (($count instanceof Expr\UnaryMinus || $count instanceof Expr\UnaryPlus)
                        && ($count->expr instanceof Node\Scalar\Int_ || $count->expr instanceof Node\Scalar\Float_)) {
                        $numeric = $count instanceof Expr\UnaryMinus ? -$count->expr->value : $count->expr->value;
                    }
                    if ($numeric !== null && is_numeric($numeric)) {
                        $number = (float) $numeric;
                        if (! is_finite($number) || $number >= (float) PHP_INT_MAX || $number < (float) PHP_INT_MIN) {
                            $resolved = false;
                        } else {
                            $instances = (int) $numeric > 0;
                        }
                    }
                }
            }
            if (! $configuration && ($call === $site || in_array($method, ['new', 'factory'], true))) {
                $factoryCount = $method === 'factory' ? $this->argument($call, 'count') : null;
                $stateParameters = $factoryCount instanceof Expr\Closure || $factoryCount instanceof Expr\ArrowFunction || $factoryCount instanceof Expr\Array_ ? ['count'] : ['state'];
                foreach ($method === 'factory' ? $stateParameters : ['attributes'] as $parameter) {
                    $argument = $this->argument($call, $parameter, $parameter === 'state' ? 1 : 0);
                    if ($argument instanceof Expr\Closure || $argument instanceof Expr\ArrowFunction) {
                        $resolved = $resolved && ! $argument->static;
                        $target = $this->owners[$argument->getStartFilePos()] ?? null;
                        if ($target?->kind === 'closure') {
                            $callbacks[] = $stepCallbacks[] = ['stage' => 'state', 'target' => $target->id, 'line' => max(1, $call->getStartLine()), 'end_line' => max(1, $call->getEndLine())];
                        } else {
                            $resolved = false;
                        }
                    }
                }
            }
            $steps[] = ['method' => $method, 'callbacks' => $stepCallbacks, 'count_overridden' => in_array($method, ['count', 'factory'], true), 'instances_possible' => $instances,
                'clear_stage' => match ($method) {
                    'withoutaftermaking' => 'aftermaking', 'withoutaftercreating' => 'aftercreating', default => null
                },
                'argument_count' => count($call->getArgs()), 'line' => max(1, $call->getStartLine()), 'end_line' => max(1, $call->getEndLine()), 'connection' => $connection, 'relationship' => $relationship];
        }
        $operation = $configuration ? $configurationName : strtolower($site->name->toString());
        if (! $configuration && in_array($operation, ['makeone', 'createone', 'createonequietly'], true)) {
            $instances = true;
            $countOverridden = true;
            $steps[array_key_last($steps)]['count_overridden'] = true;
            $steps[array_key_last($steps)]['instances_possible'] = true;
        }
        $kind = $configuration ? ($configurationName === 'configure' ? 'factory-configuration' : 'factory-state') : ($related ? 'factory-related' : 'factory-operation');
        $id = CatalogElement::identity($file->path, $kind, $operation, $site->getStartFilePos());
        $this->elements[] = new CatalogElement($id, $operation, $kind, max(1, $site->getStartLine()), max(1, $site->getEndLine()), max(0, $site->getStartFilePos()), $owner,
            metadata: ['receiver' => $receiver, 'operation' => $operation, 'chain_methods' => $methods, 'callbacks' => $callbacks, 'selector_resolved' => $resolved,
                'instances_possible' => $instances, 'count_overridden' => $countOverridden, 'cleared_callbacks' => array_values(array_unique($cleared)), 'steps' => $steps, 'execution_proven' => false]);

        return $id;
    }

    private function argument(Expr\CallLike $call, string $name, int $index = 0): ?Node
    {
        foreach ($call->getArgs() as $position => $argument) {
            if (! $argument->unpack && ($argument->name?->toString() === $name || $argument->name === null && $position === $index)) {
                return $argument->value;
            }
        }

        return null;
    }
}
