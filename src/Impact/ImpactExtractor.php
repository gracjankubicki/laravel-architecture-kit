<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiAttachments;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiGateways;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiOperations;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSaloonOperations;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Per-file extraction with no application execution or retained AST. */
final class ImpactExtractor
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $notices = [];

    private int $nodes = 0;

    private bool $limited = false;

    private bool $staticContext = false;

    private bool $catalogMode = false;

    /** @var array<string, bool> */
    private array $referenceScopes = [];

    /** @var array<string, bool> */
    private array $referenceNotices = [];

    /** @var list<array<string, mixed>> */
    private array $catalogReturns = [];

    /** @return list<array<string, mixed>> */
    public function catalogReturns(): array
    {
        return $this->catalogReturns;
    }

    public function extract(FileContext $file, bool $catalog = false): ImpactFacts
    {
        $this->catalogMode = $catalog;
        $this->referenceScopes = $this->referenceNotices = [];
        $this->catalogReturns = [];
        $this->calls = $this->notices = [];
        $this->nodes = 0;
        $this->limited = false;
        $classes = [];
        if (($reason = self::sourceLimit(strlen($file->contents))) !== null) {
            return new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => $reason]]);
        }
        $ast = $file->ast();
        if ($ast === null) {
            return new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => 'Unparseable source.']]);
        }
        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
            if (! isset($class->namespacedName) && ! $this->catalogMode) {
                $this->notices[] = ['line' => $class->getStartLine(), 'reason' => 'Anonymous class dispatch is unresolved.'];

                continue;
            }
            $name = isset($class->namespacedName) ? $class->namespacedName->toString() : '(anonymous) '.$file->path.':'.$class->getStartFilePos();
            $parents = [];
            if ($class instanceof Stmt\Class_ && $class->extends !== null) {
                $parents[] = $file->resolvedName($class->extends);
            }
            foreach ($class instanceof Stmt\Interface_ ? $class->extends : ($class instanceof Stmt\Class_ || $class instanceof Stmt\Enum_ ? $class->implements : []) as $parent) {
                $parents[] = $file->resolvedName($parent);
            }
            $traits = [];
            $adaptations = false;
            foreach ($class->getTraitUses() as $use) {
                $adaptations = $adaptations || $use->adaptations !== [];
                foreach ($use->traits as $trait) {
                    $traits[] = $file->resolvedName($trait);
                }
            }
            $properties = [];
            foreach ($class->getProperties() as $property) {
                $type = $this->type($file, $property->type, $name, $parents[0] ?? null);
                if ($type !== null) {
                    foreach ($property->props as $prop) {
                        $properties[$prop->name->toString()] = $type;
                    }
                }
            }
            $constructor = $class->getMethod('__construct');
            foreach ($constructor->params ?? [] as $param) {
                if ($param->flags !== 0 && is_string($param->var->name) && ($type = $this->type($file, $param->type, $name, $parents[0] ?? null)) !== null) {
                    $properties[$param->var->name] = $type;
                }
            }
            $methods = [];
            foreach ($class->getMethods() as $method) {
                $signature = MethodSignature::extract($method, $name, $parents[0] ?? null);
                $signature['abstract'] = $class instanceof Stmt\Interface_ || $signature['abstract'];
                $methods[strtolower($method->name->toString())] = ['name' => $method->name->toString(), 'line' => $method->getStartLine(), 'final' => $method->isFinal(), 'signature' => $signature];
                $this->staticContext = $method->isStatic();
                $vars = $method->isStatic() ? [] : ['this' => ['type' => $name, 'exact' => false]];
                if ($this->catalogMode && isset($vars['this'])) {
                    $vars['this']['bound_this'] = true;
                }
                foreach ($method->params as $param) {
                    if ($param->byRef) {
                        $this->referenceScopes[$name.'::'.$method->name->toString()] = true;
                    }
                    if (is_string($param->var->name)) {
                        $vars[$param->var->name] = ['type' => $this->type($file, $param->type, $name, $parents[0] ?? null), 'exact' => false];
                    }
                }
                $this->walk($method->stmts ?? [], $file, $name, $name.'::'.$method->name->toString(), $parents[0] ?? null, $properties, $vars);
            }
            $classes[$name] = ['kind' => $class instanceof Stmt\Interface_ ? 'interface' : ($class instanceof Stmt\Trait_ ? 'trait' : 'class'),
                'line' => $class->getStartLine(), 'final' => $class instanceof Stmt\Class_ && $class->isFinal(), 'abstract' => $class instanceof Stmt\Class_ && $class->isAbstract(), 'parents' => $parents,
                'traits' => $traits, 'adaptations' => $adaptations, 'properties' => $properties, 'methods' => $methods];
        }
        $this->staticContext = true;
        $vars = [];
        $this->walk($ast, $file, '', '(file) '.$file->path, null, [], $vars);

        return new ImpactFacts($file->path, $classes, $this->calls, $this->notices);
    }

    /** Explicitly analyzes a registered route callback, leaving arbitrary callbacks unresolved.
     */
    public function extractCallback(FileContext $file, Expr\Closure|Expr\ArrowFunction $callback, string $from): ImpactFacts
    {
        $this->catalogMode = false;
        $this->catalogReturns = [];
        $this->calls = $this->notices = [];
        $this->nodes = 0;
        $this->limited = false;
        $this->staticContext = true;
        $vars = [];
        foreach ($callback->params as $param) {
            if (is_string($param->var->name)) {
                $vars[$param->var->name] = ['type' => $this->type($file, $param->type, '', null), 'exact' => false];
            }
        }
        if ($callback instanceof Expr\Closure) {
            $this->walk($callback->stmts, $file, '', $from, null, [], $vars);
        } else {
            $this->expression($callback->expr, $file, '', $from, null, [], $vars);
        }

        return new ImpactFacts($file->path, [], $this->calls, $this->notices);
    }

    /** Check before allocating either source or parser nodes. */
    public static function sourceLimit(int $bytes): ?string
    {
        if ($bytes > 100_000) {
            return 'Impact source size limit exceeded.';
        }
        $memoryLimit = MemoryLimit::bytes();
        if ($memoryLimit !== null && memory_get_usage(true) + $bytes * 200 + 262144 > $memoryLimit * 0.65) {
            return 'Impact memory limit reached during extraction.';
        }

        return null;
    }

    /** @param list<Node> $nodes
     * @param  array<string, string>  $properties
     * @param  array<string, array{type: ?string, exact: bool, bound_this?: bool, catalog_origin?: int, catalog_instance_origin?: int, catalog_attachments?: array<string, mixed>, catalog_pool_members?: array{complete: bool, members: list<array{receiver: string, origin: ?int}>}, callable_form?: string, callable_name?: array{string, ?string, ?string}, factory_call?: array{creator: string, form: string, binding: ?string, receiver_call?: array<string, mixed>}, bound_callable?: array{method: string, this: bool, late: bool, lexical: ?string, creator: string, scope_bound: bool}}>  $vars
     */
    private function walk(array $nodes, FileContext $file, string $class, string $from, ?string $parent, array $properties, array &$vars): void
    {
        foreach ($nodes as $node) {
            if (++$this->nodes > 20000) {
                if (! $this->limited) {
                    $this->notices[] = ['line' => $node->getStartLine(), 'reason' => 'Per-file impact AST limit reached.'];
                    $this->limited = true;
                }

                return;
            }
            if ($node instanceof Stmt\Function_) {
                if ($this->catalogMode) {
                    $functionVars = [];
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                            $functionVars[$param->var->name] = ['type' => $this->type($file, $param->type, '', null), 'exact' => false];
                        }
                    }
                    $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                    if (array_filter($node->params, fn ($param) => $param->byRef) !== []) {
                        $this->referenceScopes[$name] = true;
                    }
                    $context = $this->staticContext;
                    $this->staticContext = true;
                    $this->walk($node->stmts, $file, '', $name, null, [], $functionVars);
                    $this->staticContext = $context;

                    continue;
                }
                $this->notices[] = ['line' => $node->getStartLine(), 'reason' => 'Standalone function bodies are outside method dispatch analysis.'];

                continue;
            }
            if ($node instanceof Stmt\ClassLike) {
                continue;
            }
            if ($node instanceof Expr) {
                $this->expression($node, $file, $class, $from, $parent, $properties, $vars);

                continue;
            }
            if ($node instanceof Stmt\Global_ || $node instanceof Stmt\Static_ || $node instanceof Stmt\Foreach_ && $node->byRef) {
                $this->referenceScopes[$from] = true;
            }
            if ($node instanceof Stmt\Unset_) {
                foreach ($node->vars as $var) {
                    if ($var instanceof Expr\Variable && is_string($var->name)) {
                        $vars[$var->name] = ['type' => null, 'exact' => false];
                    }
                }
            }
            if ($node instanceof Stmt\Foreach_) {
                foreach ([$node->keyVar, $node->valueVar] as $var) {
                    if ($var instanceof Expr\Variable && is_string($var->name)) {
                        $vars[$var->name] = ['type' => null, 'exact' => false];
                    }
                }
            }
            if ($this->catalogMode && $node instanceof Stmt\Return_ && $node->expr !== null) {
                $value = $this->expression($node->expr, $file, $class, $from, $parent, $properties, $vars);
                $this->catalogReturns[] = ['from' => $from, 'line' => $node->getStartLine(), 'end_line' => $node->getEndLine(), 'offset' => $node->getStartFilePos(), 'value' => $value];

                continue;
            }
            if ($node instanceof Stmt\Expression) {
                $this->expression($node->expr, $file, $class, $from, $parent, $properties, $vars);

                continue;
            }
            $branched = $node instanceof Stmt\If_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_;
            // Conditions execute before branch bodies. Loops may revisit the body
            // after any write in the loop, so never seed it with a stale local type.
            $keys = $node->getSubNodeNames();
            if ($node instanceof Stmt\If_ || $node instanceof Stmt\Switch_) {
                $this->expression($node->cond, $file, $class, $from, $parent, $properties, $vars);
                $keys = array_values(array_diff($keys, ['cond']));
            }
            if ($node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_) {
                foreach ((new NodeFinder)->find([$node], static fn (Node $child): bool => $child instanceof Expr\Assign || $child instanceof Expr\AssignRef || $child instanceof Expr\AssignOp || $child instanceof Expr\PreInc || $child instanceof Expr\PostInc || $child instanceof Expr\PreDec || $child instanceof Expr\PostDec || $child instanceof Expr\CallLike || $child instanceof Stmt\Unset_ || $child instanceof Stmt\Foreach_) as $write) {
                    if ($write instanceof Expr\AssignRef) {
                        // An alias may mutate any local on the next iteration.
                        foreach (array_keys($vars) as $var) {
                            if ($var !== 'this') {
                                $vars[$var] = ['type' => null, 'exact' => false];
                            }
                        }

                        continue;
                    }
                    if ($write instanceof Expr\CallLike) {
                        $targets = $write->isFirstClassCallable() ? [] : array_map(static fn (Node\Arg $arg): Expr => $arg->value, $write->getArgs());
                    } elseif ($write instanceof Stmt\Unset_) {
                        $targets = $write->vars;
                    } elseif ($write instanceof Stmt\Foreach_) {
                        $targets = [$write->keyVar, $write->valueVar];
                    } else {
                        /** @var Expr\Assign|Expr\AssignOp|Expr\PreInc|Expr\PostInc|Expr\PreDec|Expr\PostDec $write */
                        $targets = [$write->var];
                        if (! ($write->var instanceof Expr\Variable && is_string($write->var->name)) && ! $write->var instanceof Expr\PropertyFetch) {
                            // Destructuring/dynamic targets can overwrite any local.
                            foreach (array_keys($vars) as $var) {
                                if ($var !== 'this') {
                                    $vars[$var] = ['type' => null, 'exact' => false];
                                }
                            }
                        }
                    }
                    foreach ($targets as $target) {
                        if ($target instanceof Expr\Variable && is_string($target->name)) {
                            $vars[$target->name] = ['type' => null, 'exact' => false];
                        }
                    }
                }
            }
            $changed = [];
            foreach ($keys as $key) {
                $branch = $vars;
                $this->walk($this->children($node->$key), $file, $class, $from, $parent, $properties, $branch);
                if (! $branched) {
                    $vars = $branch;
                } else {
                    foreach (array_unique([...array_keys($vars), ...array_keys($branch)]) as $var) {
                        if (($vars[$var] ?? null) !== ($branch[$var] ?? null)) {
                            $changed[$var] = true;
                        }
                    }
                }
            }
            foreach ($changed as $var => $_) {
                $vars[$var] = ['type' => null, 'exact' => false];
            }
        }
    }

    /** @param array<string, string> $properties
     * @param  array<string, array{type: ?string, exact: bool, bound_this?: bool, catalog_origin?: int, catalog_instance_origin?: int, catalog_attachments?: array<string, mixed>, catalog_pool_members?: array{complete: bool, members: list<array{receiver: string, origin: ?int}>}, callable_form?: string, callable_name?: array{string, ?string, ?string}, factory_call?: array{creator: string, form: string, binding: ?string, receiver_call?: array<string, mixed>}, bound_callable?: array{method: string, this: bool, late: bool, lexical: ?string, creator: string, scope_bound: bool}}>  $vars
     * @return array{type: ?string, exact: bool, bound_this?: bool, catalog_origin?: int, catalog_instance_origin?: int, catalog_attachments?: array<string, mixed>, catalog_pool_members?: array{complete: bool, members: list<array{receiver: string, origin: ?int}>}, callable_form?: string, callable_name?: array{string, ?string, ?string}, factory_call?: array{creator: string, form: string, binding: ?string, receiver_call?: array<string, mixed>}, bound_callable?: array{method: string, this: bool, late: bool, lexical: ?string, creator: string, scope_bound: bool}}
     */
    private function expression(Expr $expr, FileContext $file, string $class, string $from, ?string $parent, array $properties, array &$vars): array
    {
        $unknown = ['type' => null, 'exact' => false];
        if ($this->catalogMode && $expr instanceof Node\Scalar\String_) {
            return [...$unknown, 'callable_name' => self::namedCallable($expr->value)];
        }
        if (++$this->nodes > 20000) {
            if (! $this->limited) {
                $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Per-file impact AST limit reached.'];
                $this->limited = true;
            }

            return $unknown;
        }
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name) ? ($vars[$expr->name] ?? $unknown) : $unknown;
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            if ($this->catalogMode) {
                $callbackVars = $expr instanceof Expr\ArrowFunction ? $vars : [];
                if ($expr instanceof Expr\Closure) {
                    foreach ($expr->uses as $use) {
                        if ($use->byRef) {
                            $this->referenceScopes[$from] = true;
                        }
                        if (is_string($use->var->name)) {
                            $callbackVars[$use->var->name] = $use->byRef ? $unknown : ($vars[$use->var->name] ?? $unknown);
                        }
                    }
                }
                if (! $expr->static && isset($vars['this'])) {
                    $callbackVars['this'] = $vars['this'];
                } else {
                    unset($callbackVars['this']);
                }
                foreach ($expr->params as $param) {
                    if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                        $callbackVars[$param->var->name] = ['type' => $this->type($file, $param->type, $class, $parent), 'exact' => false];
                    }
                }
                $name = '(closure) '.$file->path.':'.$expr->getStartFilePos();
                if (array_filter($expr->params, fn ($param) => $param->byRef) !== []) {
                    $this->referenceScopes[$name] = true;
                }
                $context = $this->staticContext;
                $this->staticContext = $expr->static || $context;
                if ($expr instanceof Expr\Closure) {
                    $this->walk($expr->stmts, $file, $class, $name, $parent, $properties, $callbackVars);
                } else {
                    $value = $this->expression($expr->expr, $file, $class, $name, $parent, $properties, $callbackVars);
                    $this->catalogReturns[] = ['from' => $name, 'line' => $expr->getStartLine(), 'end_line' => $expr->getEndLine(), 'offset' => $expr->getStartFilePos(), 'value' => $value];
                }
                $this->staticContext = $context;

                return ['type' => '@closure:'.$name, 'exact' => true];
            }
            $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Callback body is not a proved immediate invocation.'];

            return $unknown;
        }
        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            $value = $this->expression($expr->expr, $file, $class, $from, $parent, $properties, $vars);
            if ($expr instanceof Expr\AssignRef) {
                $this->referenceScopes[$from] = true;
                foreach (array_keys($vars) as $var) {
                    if ($var !== 'this') {
                        $vars[$var] = $unknown;
                    }
                }

                return $unknown;
            }
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $value;
            } elseif ($expr->var instanceof Expr\PropertyFetch && $expr->var->name instanceof Node\Identifier) {
                $owner = $this->expression($expr->var->var, $file, $class, $from, $parent, $properties, $vars);
                if ($owner['type'] !== null) {
                    $vars['@property:'.$owner['type'].'#'.$expr->var->name->toString()] = $unknown;
                }
            } else {
                // Destructuring or dynamic write targets cannot retain previous locals.
                foreach (array_keys($vars) as $var) {
                    if ($var !== 'this') {
                        $vars[$var] = $unknown;
                    }
                }
            }

            return $value;
        }
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $owner = $this->expression($expr->var, $file, $class, $from, $parent, $properties, $vars);
            if ($owner['type'] !== null && $expr->name instanceof Node\Identifier) {
                $prop = $expr->name->toString();
                if (isset($vars['@property:'.$owner['type'].'#'.$prop])) {
                    return $vars['@property:'.$owner['type'].'#'.$prop];
                }

                return ['type' => $owner['type'] === $class && isset($properties[$prop]) ? $properties[$prop] : '@property:'.$owner['type'].'#'.$prop, 'exact' => false];
            }

            return $unknown;
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) {
            $receiver = $expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall
                ? $this->expression($expr->var, $file, $class, $from, $parent, $properties, $vars)
                : ['type' => $expr->class instanceof Node\Name ? $this->type($file, $expr->class, $class, $parent) : null, 'exact' => $expr->class instanceof Node\Name && strtolower($expr->class->toString()) !== 'static'];
            if ($this->catalogMode && $expr instanceof Expr\New_ && $expr->class instanceof Stmt\Class_) {
                $receiver = ['type' => '(anonymous) '.$file->path.':'.$expr->class->getStartFilePos(), 'exact' => true];
            }
            $method = $expr instanceof Expr\New_ ? '__construct' : ($expr->name instanceof Node\Identifier ? $expr->name->toString() : null);
            $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => $method, 'exact' => $receiver['exact'],
                'kind' => $expr->isFirstClassCallable() ? 'reference' : 'call', 'line' => $expr->getStartLine(), 'site' => MethodSignature::site($expr, $class, $this->staticContext)];
            if ($this->catalogMode) {
                $position = array_key_last($this->calls);
                if (isset($receiver['factory_call'])) {
                    $this->calls[$position]['receiver_factory_call'] = $receiver['factory_call'];
                }
                if (isset($receiver['catalog_origin'])) {
                    $this->calls[$position]['receiver_origin'] = $receiver['catalog_origin'];
                }
                if (isset($receiver['catalog_instance_origin'])) {
                    $this->calls[$position]['receiver_instance_origin'] = $receiver['catalog_instance_origin'];
                }
                $this->calls[$position]['offset'] = $expr->getStartFilePos();
                $this->calls[$position]['end_offset'] = $expr->getEndFilePos();
                $this->calls[$position]['end_line'] = $expr->getEndLine();
                if (strtolower($method ?? '') === 'send' && ! $expr->isFirstClassCallable()) {
                    $this->calls[$position]['saloon_pool_send'] = $expr->getArgs() === [];
                }
                $this->calls[$position]['this_receiver'] = ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
                    && ($receiver['bound_this'] ?? false);
                $this->calls[$position]['late_static_receiver'] = $expr instanceof Expr\StaticCall
                    && $expr->class instanceof Node\Name && strtolower($expr->class->toString()) === 'static';
                $this->calls[$position]['lexical_static_receiver'] = $expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name
                    && in_array(strtolower($expr->class->toString()), ['self', 'parent'], true) ? strtolower($expr->class->toString()) : null;
                if ($expr instanceof Expr\New_ && in_array(count($expr->args), [2, 3], true)
                    && array_filter($expr->getArgs(), static fn ($argument) => $argument->name !== null && strlen($argument->name->toString()) > 1000) === []) {
                    $this->calls[$position]['provider_constructor_arguments'] = array_map(static fn ($argument) => [
                        'name' => $argument->name?->toString(), 'unpack' => $argument->unpack, 'by_ref' => $argument->byRef,
                    ], $expr->getArgs());
                }
            }
            $saloonArguments = null;
            if ($this->catalogMode && ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
                && ! $expr->isFirstClassCallable() && isset(CatalogSaloonOperations::PARAMETERS[strtolower($method ?? '')])) {
                $saloonArguments = CatalogSaloonOperations::arguments(strtolower($method), $expr->getArgs());
                $this->calls[$position]['saloon_request'] = ['valid' => $saloonArguments['valid'], 'receiver' => null,
                    'request_side_valid' => CatalogSaloonOperations::requestSideArguments(strtolower($method), $expr->getArgs()), 'mock_receiver' => null];
            }
            if (! $expr->isFirstClassCallable()) {
                foreach ($expr->getArgs() as $argumentPosition => $arg) {
                    $value = $this->expression($arg->value, $file, $class, $from, $parent, $properties, $vars);
                    $poolRequests = $this->catalogMode && ($expr instanceof Expr\New_ && CatalogSaloonOperations::poolConstructor($file, $expr)
                        && ($arg->name?->toString() ?? ($argumentPosition === 1 ? 'requests' : '')) === 'requests'
                        || ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall)
                        && in_array(strtolower($method ?? ''), ['pool', 'setrequests'], true)
                        && ($arg->name?->toString() ?? ($argumentPosition === 0 ? 'requests' : '')) === 'requests');
                    if ($this->catalogMode && ! $arg->unpack && ! $arg->byRef
                        && ($value['type'] !== null || isset($value['callable_name']))) {
                        $poolConstructor = $expr instanceof Expr\New_ && CatalogSaloonOperations::poolConstructor($file, $expr);
                        $poolMethod = $expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall ? strtolower($method ?? '') : '';
                        $setter = CatalogSaloonOperations::POOL_SETTERS[$poolMethod] ?? null;
                        $parameters = $poolConstructor ? ['connector', 'requests', 'concurrency', 'responseHandler', 'exceptionHandler']
                            : ($poolMethod === 'pool' ? ['requests', 'concurrency', 'responseHandler', 'exceptionHandler'] : ($setter !== null && $setter[1] !== null ? [$setter[0]] : []));
                        $name = $arg->name?->toString() ?? ($parameters[$argumentPosition] ?? null);
                        $kind = $setter[1] ?? match ($name) {
                            'concurrency' => 'concurrency', 'responseHandler' => 'response', 'exceptionHandler' => 'exception', default => null
                        };
                        if ($kind !== null && in_array($name, $parameters, true)) {
                            $this->calls[$position]['saloon_pool_callback_values'][$kind] = $value;
                        }
                    }
                    if ($poolRequests && isset($value['catalog_pool_members']) && ! $arg->unpack && ! $arg->byRef) {
                        $this->calls[$position]['saloon_pool_members'] = $value['catalog_pool_members'];
                    }
                    if ($poolRequests && ! $arg->unpack && ! $arg->byRef
                        && str_starts_with($value['type'] ?? '', '@return:') && isset($value['factory_call'])) {
                        $this->calls[$position]['saloon_pool_member_factory'] = ['receiver' => $value['type'], 'call' => $value['factory_call']];
                    }
                    if ($this->catalogMode && $expr instanceof Expr\New_ && CatalogSaloonOperations::poolConstructor($file, $expr)
                        && ($arg->name?->toString() ?? ($argumentPosition === 0 ? 'connector' : '')) === 'connector') {
                        $this->calls[$position]['saloon_pool_connector'] = $value['type'];
                    }
                    if ($saloonArguments !== null && $saloonArguments['request'] === $argumentPosition) {
                        $this->calls[$position]['saloon_request']['receiver'] = $value['type'];
                    }
                    if ($saloonArguments !== null && $argumentPosition === 0) {
                        $this->calls[$position]['saloon_request']['mock_receiver'] = $value['type'];
                    }
                    if ($this->catalogMode && $expr instanceof Expr\New_ && count($expr->args) === 3
                        && ($arg->name?->toString() === 'gateway' || $arg->name === null && $argumentPosition === 0)
                        && ! $arg->unpack && ! $arg->byRef && $value['type'] !== null) {
                        $this->calls[$position]['provider_gateway_value'] = $value;
                    }
                    if ($this->catalogMode && in_array(strtolower($method ?? ''), CatalogAiGateways::SETTERS, true) && $argumentPosition === 0
                        && ! $arg->unpack && ! $arg->byRef && $value['type'] !== null) {
                        $this->calls[$position]['provider_gateway_value'] = $value;
                    }
                    if ($this->catalogMode && strtolower($method ?? '') === 'ontoolinvocation' && $argumentPosition < 128
                        && ! $arg->unpack && ! $arg->byRef && $value['type'] !== null) {
                        $this->calls[$position]['gateway_callback_values'][$argumentPosition] = $value;
                    }
                    $parameters = $this->catalogMode ? CatalogAiOperations::invocationParameters($method ?? '') : null;
                    if ($this->catalogMode && $parameters !== null && ! $arg->unpack && ! $arg->byRef
                        && ($arg->name?->toString() ?? ($parameters[$argumentPosition] ?? null)) === 'attachments'
                        && isset($value['catalog_attachments'])) {
                        $this->calls[$position]['ai_attachments'] = $value['catalog_attachments'];
                    }
                    if ($this->catalogMode && $parameters !== null && ! $arg->unpack && ! $arg->byRef
                        && ($arg->name?->toString() ?? ($parameters[$argumentPosition] ?? null)) === 'attachments'
                        && str_starts_with($value['type'] ?? '', '@return:') && isset($value['factory_call'])) {
                        $this->calls[$position]['ai_attachment_factory'] = ['receiver' => $value['type'], 'call' => $value['factory_call']];
                    }
                    if ($this->catalogMode && in_array(strtolower($method ?? ''), ['each', 'then', 'catch'], true) && count($expr->args) === 1
                        && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'callback') && ($value['type'] !== null || isset($value['callable_name']))) {
                        $this->calls[$position]['callback_value'] = $value;
                    }
                    // Application calls may mutate arguments by reference. Do not carry stale types.
                    if ($arg->value instanceof Expr\Variable && is_string($arg->value->name)) {
                        $vars[$arg->value->name] = $this->catalogMode
                            ? $this->argumentValue($value, $this->calls[$position], $expr, $class, $argumentPosition)
                            : $unknown;
                    }
                }
            }

            if ($expr instanceof Expr\New_) {
                if ($this->catalogMode) {
                    $receiver['catalog_instance_origin'] = max(0, $expr->getEndFilePos());
                }

                return $receiver;
            }
            if ($this->catalogMode && $receiver['type'] !== null && $method !== null) {
                $result = ['type' => ($expr->isFirstClassCallable() ? '@method-callable:' : '@return:').json_encode([$receiver['type'], $method, $receiver['exact']], JSON_THROW_ON_ERROR), 'exact' => false];
                if (! $expr->isFirstClassCallable()) {
                    $result['catalog_origin'] = max(0, $expr->getEndFilePos());
                    $result['factory_call'] = ['creator' => $class, 'form' => $expr instanceof Expr\StaticCall ? 'static' : 'instance',
                        'binding' => $this->calls[$position]['late_static_receiver'] ? 'static' : $this->calls[$position]['lexical_static_receiver']];
                }
                if (isset($result['factory_call'], $receiver['factory_call'])) {
                    $context = $receiver['factory_call'];
                    $levels = 0;
                    while (isset($context['receiver_call']) && $levels < 8) {
                        $context = $context['receiver_call'];
                        $levels++;
                    }
                    if ($levels >= 7) {
                        unset($result['factory_call']);
                        $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Factory receiver context depth limit reached.'];
                    } else {
                        $result['factory_call']['receiver_call'] = $receiver['factory_call'];
                    }
                }
                if ($expr->isFirstClassCallable()) {
                    $call = $this->calls[$position];
                    $result['callable_form'] = $expr instanceof Expr\StaticCall
                        && (! in_array($call['site']['form'], ['self', 'parent', 'static'], true) || $this->staticContext) ? 'static' : 'instance';
                    if ($expr instanceof Expr\StaticCall && $call['site']['form'] === 'class' && ! $this->staticContext) {
                        $result['callable_form'] = 'bound-class';
                    }
                    $result['bound_callable'] = ['method' => $method, 'this' => $call['this_receiver'], 'late' => $call['late_static_receiver'], 'lexical' => $call['lexical_static_receiver'], 'creator' => $from, 'scope_bound' => true];
                }

                return $result;
            }

            return $unknown;
        }
        if ($this->catalogMode && $expr instanceof Expr\FuncCall) {
            if ($expr->name instanceof Node\Name) {
                $resolved = $file->resolvedName($expr->name);
                $namespaced = $expr->name->getAttribute('namespacedName');
                $candidates = $namespaced instanceof Node\Name ? [$namespaced->toString(), $resolved] : [$resolved];
                $receiver = ['type' => '@function:'.json_encode(array_values(array_unique($candidates)), JSON_THROW_ON_ERROR), 'exact' => true];
            } else {
                $receiver = $this->expression($expr->name, $file, $class, $from, $parent, $properties, $vars);
            }
            $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => '__invoke', 'exact' => $receiver['exact'],
                'kind' => $expr->isFirstClassCallable() ? 'reference' : 'call', 'line' => $expr->getStartLine(), 'site' => MethodSignature::site($expr, $class, $this->staticContext)];
            $position = array_key_last($this->calls);
            $this->calls[$position]['offset'] = $expr->getStartFilePos();
            $this->calls[$position]['end_line'] = $expr->getEndLine();
            $this->calls[$position]['bound_callable'] = $receiver['bound_callable'] ?? null;
            $this->calls[$position]['callable_form'] = $receiver['callable_form'] ?? null;
            $jsonNames = is_string($receiver['type']) && str_starts_with($receiver['type'], '@function:') ? json_decode(substr($receiver['type'], 10), true, 32) : [];
            $jsonEncode = is_array($jsonNames) && count(array_filter($jsonNames, fn ($name) => is_string($name) && strcasecmp($name, 'json_encode') === 0)) > 0;
            $globalJson = $jsonEncode && count($jsonNames) === 1;
            foreach ($expr->isFirstClassCallable() ? [] : $expr->getArgs() as $argPosition => $arg) {
                $value = $this->expression($arg->value, $file, $class, $from, $parent, $properties, $vars);
                if ($jsonEncode && ! $arg->unpack && ($arg->name?->toString() === 'value' || $arg->name === null && $argPosition === 0) && $value['type'] !== null) {
                    $this->calls[$position]['json_serialization_receiver'] = $value['type'];
                    if (isset($value['factory_call'])) {
                        $this->calls[$position]['receiver_factory_call'] = $value['factory_call'];
                    }
                }
                if (! $globalJson && $arg->value instanceof Expr\Variable && is_string($arg->value->name)) {
                    $vars[$arg->value->name] = $this->argumentValue($value, $this->calls[$position], $expr, $class, $argPosition, $receiver);
                }
            }

            $result = $expr->isFirstClassCallable() ? $receiver : ($receiver['type'] !== null
                ? ['type' => '@return:'.json_encode([$receiver['type'], '__invoke', $receiver['exact']], JSON_THROW_ON_ERROR), 'exact' => false,
                    'catalog_origin' => max(0, $expr->getEndFilePos()),
                    'factory_call' => ['creator' => $class, 'form' => $expr->name instanceof Node\Name ? 'function' : 'instance', 'binding' => null]]
                : $unknown);
            if (! $expr->isFirstClassCallable() && isset($result['factory_call'], $receiver['factory_call'])) {
                $context = $receiver['factory_call'];
                $levels = 0;
                while (isset($context['receiver_call']) && $levels < 8) {
                    $context = $context['receiver_call'];
                    $levels++;
                }
                if ($levels >= 7) {
                    unset($result['factory_call']);
                    $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Factory receiver context depth limit reached.'];
                } else {
                    $result['factory_call']['receiver_call'] = $receiver['factory_call'];
                }
            }

            return $result;
        }
        if ($expr instanceof Expr\Array_ && count($expr->items) === 2) {
            $items = $this->catalogMode ? self::callableArrayItems($expr) : $expr->items;
            $first = $items[0] ?? null;
            $second = $items[1] ?? null;
            if ($first !== null && $second?->value instanceof Node\Scalar\String_
                && ! $first->unpack && ! $first->byRef && ! $second->unpack && ! $second->byRef
                && ($first->key === null || ($first->key instanceof Node\Scalar\Int_ && $first->key->value === 0))
                && ($second->key === null || ($second->key instanceof Node\Scalar\Int_ && $second->key->value === 1))) {
                $value = $first->value;
                if ($this->catalogMode && $value instanceof Node\Scalar\String_) {
                    return [...$unknown, 'callable_name' => self::namedCallable($value->value.'::'.$second->value->value)];
                }
                $receiver = $value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                    ? ['type' => $this->type($file, $value->class, $class, $parent), 'exact' => true]
                    : $this->expression($value, $file, $class, $from, $parent, $properties, $vars);
                if ($receiver['type'] !== null) {
                    $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => $second->value->value, 'exact' => $receiver['exact'], 'kind' => 'reference', 'line' => $expr->getStartLine(), 'site' => ['form' => 'reference', 'class' => $class, 'static_context' => $this->staticContext, 'arguments' => []]];

                    if ($this->catalogMode) {
                        $position = array_key_last($this->calls);
                        $this->calls[$position]['offset'] = $expr->getStartFilePos();
                        $this->calls[$position]['end_line'] = $expr->getEndLine();
                        $this->calls[$position]['site']['form'] = $value instanceof Expr\ClassConstFetch ? 'class' : 'instance';

                        return ['type' => '@method-callable:'.json_encode([$receiver['type'], $second->value->value, $receiver['exact']], JSON_THROW_ON_ERROR), 'exact' => false,
                            'callable_form' => $value instanceof Expr\ClassConstFetch ? 'static' : 'instance',
                            'bound_callable' => ['method' => $second->value->value, 'this' => $receiver['bound_this'] ?? false, 'late' => false, 'lexical' => null, 'creator' => $from, 'scope_bound' => false]];
                    }

                    return $unknown;
                }
            }
        }
        if ($this->catalogMode && $expr instanceof Expr\Array_ && CatalogSaloonOperations::poolMemberArray($expr)) {
            $members = [];
            $complete = true;
            foreach ($expr->items as $item) {
                if ($this->limited || $this->nodes % 128 === 0 && self::sourceLimit(0) !== null) {
                    $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Source array member evaluation reached its node or memory limit.'];
                    $complete = false;
                    break;
                }
                if ($item === null) {
                    $complete = false;

                    continue;
                }
                if ($item->key !== null) {
                    $this->expression($item->key, $file, $class, $from, $parent, $properties, $vars);
                }
                $member = $this->expression($item->value, $file, $class, $from, $parent, $properties, $vars);
                $complete = $complete && ! $item->unpack && ! $item->byRef && $member['type'] !== null;
                if (! $item->unpack && ! $item->byRef && $member['type'] !== null) {
                    $members[] = ['receiver' => $member['type'], 'origin' => $member['catalog_origin'] ?? $member['catalog_instance_origin'] ?? null];
                }
                if ($item->byRef && $item->value instanceof Expr\Variable && is_string($item->value->name)) {
                    $vars[$item->value->name] = $unknown;
                }
            }

            return [...$unknown, 'catalog_attachments' => CatalogAiAttachments::extract($file, $expr),
                'catalog_pool_members' => ['complete' => $complete, 'members' => $members]];
        }
        $branching = $expr instanceof Expr\Ternary || $expr instanceof Expr\Match_ || $expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\Coalesce;
        foreach ($expr->getSubNodeNames() as $key) {
            $branch = $vars;
            $this->walk($this->children($expr->$key), $file, $class, $from, $parent, $properties, $branch);
            if ($branching) {
                foreach (array_keys($branch) as $var) {
                    if ($branch[$var] !== ($vars[$var] ?? null)) {
                        $vars[$var] = $unknown;
                    }
                }
            } else {
                $vars = $branch;
            }
        }
        if ($expr instanceof Expr\FuncCall && ! $expr->isFirstClassCallable()) {
            foreach ($expr->getArgs() as $arg) {
                if ($arg->value instanceof Expr\Variable && is_string($arg->value->name)) {
                    $vars[$arg->value->name] = $unknown;
                }
            }
        }
        if ($expr instanceof Expr\AssignOp || $expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc || $expr instanceof Expr\PreDec || $expr instanceof Expr\PostDec) {
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $unknown;
            }
        }

        return $this->catalogMode && $expr instanceof Expr\Array_
            ? [...$unknown, 'catalog_attachments' => CatalogAiAttachments::extract($file, $expr)] : $unknown;
    }

    private function type(FileContext $file, Node|string|null $type, string $class, ?string $parent): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->type($file, $type->type, $class, $parent);
        }
        if ($this->catalogMode && ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType)) {
            $types = [];
            foreach ($type->types as $member) {
                $resolved = $this->type($file, $member, $class, $parent);
                if ($resolved !== null) {
                    $types[] = $resolved;
                }
            }

            return $types === [] ? null : '@types:'.json_encode(array_values(array_unique($types)), JSON_THROW_ON_ERROR);
        }
        if (! $type instanceof Node\Name) {
            return null;
        }

        return match (strtolower($type->toString())) {
            'self', 'static' => $class !== '' ? $class : null,
            'parent' => $parent ?? ($this->catalogMode && $class !== '' ? '@parent:'.$class : null),
            default => $file->resolvedName($type),
        };
    }

    /** @return array{string, ?string, ?string} */
    public static function namedCallable(string $value): array
    {
        $name = hash('sha256', strtolower(ltrim($value, '\\')));
        if (strlen($value) > 1000 || preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_\\\\]*::[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value) !== 1) {
            return [$name, null, null];
        }
        [$receiver, $method] = explode('::', $value, 2);

        return [$name, hash('sha256', strtolower(ltrim($receiver, '\\'))), hash('sha256', strtolower($method))];
    }

    /** @return array<int, Node\ArrayItem> */
    public static function callableArrayItems(Expr\Array_ $array): array
    {
        if (count($array->items) !== 2) {
            return [];
        }
        $items = [];
        $next = 0;
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || $item->key !== null && ! $item->key instanceof Node\Scalar\Int_) {
                return [];
            }
            $key = $item->key->value ?? $next;
            if (! in_array($key, [0, 1], true) || isset($items[$key])) {
                return [];
            }
            $items[$key] = $item;
            $next = max($next, $key + 1);
        }

        return $items;
    }

    /** @param array{type: ?string, exact: bool} $value
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $invocation
     * @return array{type: ?string, exact: bool}
     */
    private function argumentValue(array $value, array $call, Expr\CallLike $expression, string $creator, int $position, array $invocation = []): array
    {
        $unknown = ['type' => null, 'exact' => false];
        if (str_starts_with($call['from'], '(file) ') || isset($this->referenceScopes[$call['from']])) {
            if (! isset($this->referenceNotices[$call['from']])) {
                $this->referenceNotices[$call['from']] = true;
                $this->notices[] = ['line' => $expression->getStartLine(), 'reason' => 'Argument type preservation requires unresolved reference or global alias analysis.'];
            }

            return $unknown;
        }
        if ($value['type'] === null || $call['receiver'] === null || $call['method'] === null
            || $expression instanceof Expr\New_) {
            return $unknown;
        }
        if (count($expression->getArgs()) > 128) {
            $this->notices[] = ['line' => $expression->getStartLine(), 'reason' => 'Argument value flow exceeded its argument count budget.'];

            return $unknown;
        }
        $arguments = [];
        $aliases = [];
        $variable = $expression->getArgs()[$position]->value;
        foreach ($expression->getArgs() as $offset => $argument) {
            if ($argument->unpack || $argument->byRef || $argument->name !== null && strlen($argument->name->toString()) > 1000) {
                return $unknown;
            }
            $arguments[] = ['name' => $argument->name?->toString(), 'unpack' => false, 'by_ref' => false];
            if ($variable instanceof Expr\Variable && $argument->value instanceof Expr\Variable && $variable->name === $argument->value->name) {
                $aliases[] = $offset;
            }
        }
        $namedFunction = $expression instanceof Expr\FuncCall && ($expression->name instanceof Node\Name
            || str_starts_with($call['receiver'], '@function:') || str_starts_with($call['receiver'], '@closure:'));
        $form = $namedFunction ? 'function' : ($expression instanceof Expr\StaticCall ? 'static' : 'instance');
        $binding = $expression instanceof Expr\StaticCall && $expression->class instanceof Node\Name
            && in_array(strtolower($expression->class->toString()), ['self', 'parent', 'static'], true) ? strtolower($expression->class->toString()) : null;
        if ($expression instanceof Expr\FuncCall && str_starts_with($call['receiver'], '@method-callable:')) {
            $bound = $invocation['bound_callable'] ?? null;
            $callableForm = $invocation['callable_form'] ?? null;
            $target = json_decode(substr($call['receiver'], 17), true, 32);
            if (! is_array($bound) || ! ($bound['scope_bound'] ?? false)
                || ! in_array($callableForm, ['instance', 'static', 'bound-class'], true)
                || ! is_array($target) || count($target) !== 3 || ! is_string($target[0]) || ! is_string($target[1]) || ! is_bool($target[2])) {
                return $unknown;
            }
            $call['receiver'] = $target[0];
            $call['method'] = $target[1];
            $call['exact'] = $target[2];
            $form = $callableForm === 'static' ? 'static' : 'instance';
            $binding = ($bound['late'] ?? false) ? 'static' : ($bound['lexical'] ?? null);
            $boundCreator = $bound['creator'];
            $separator = strrpos($boundCreator, '::');
            $creator = $separator === false ? '' : substr($boundCreator, 0, $separator);
        }
        $context = ['creator' => $creator, 'form' => $form, 'binding' => $binding];
        if ($expression instanceof Expr\FuncCall && str_starts_with($call['receiver'], '@return:') && isset($invocation['factory_call'])) {
            $context['factory_call'] = $invocation['factory_call'];
        }
        $descriptor = json_encode([$value['type'], $call['receiver'], $call['method'], $call['exact'],
            $context, $arguments, $aliases], JSON_THROW_ON_ERROR);
        if (strlen($descriptor) > 8192) {
            $this->notices[] = ['line' => $expression->getStartLine(), 'reason' => 'Argument value flow descriptor exceeded its source budget.'];

            return $unknown;
        }

        return [...$value, 'type' => '@after-argument:'.$descriptor];
    }

    /** @return list<Node> */
    private function children(mixed $value): array
    {
        return $value instanceof Node ? [$value] : (is_array($value) ? array_values(array_filter($value, fn ($v): bool => $v instanceof Node)) : []);
    }
}
