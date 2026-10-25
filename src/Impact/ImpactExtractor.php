<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiAttachments;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiGateways;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiOperations;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSaloonOperations;
use GracjanKubicki\ArchitectureKit\Catalog\ReceiverDescriptors;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;

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

    private AnalysisMode $mode = AnalysisMode::Basic;

    /** @var array<string, bool> */
    private array $referenceScopes = [];

    /** @var array<string, bool> */
    private array $referenceNotices = [];

    /** @var list<array<string, mixed>> */
    private array $catalogReturns = [];

    /** @var array<string, string> */
    private array $descriptorReceivers = [];

    /** @return array<string, string> */
    public function descriptorReceivers(): array
    {
        return $this->descriptorReceivers;
    }

    /** @return list<array<string, mixed>> */
    public function catalogReturns(): array
    {
        return $this->catalogReturns;
    }

    public function extract(FileContext $file, bool $catalog = false, AnalysisMode $mode = AnalysisMode::Basic): ImpactFacts
    {
        $this->mode = $mode;
        $this->catalogMode = $catalog;
        if ($mode === AnalysisMode::Deep && $catalog) {
            AnalysisRuntime::stage('deep.calls.extract');
        }
        $this->referenceScopes = $this->referenceNotices = [];
        $this->catalogReturns = [];
        $this->descriptorReceivers = [];
        $this->calls = $this->notices = [];
        $this->nodes = 0;
        $this->limited = false;
        $classes = [];
        if (($reason = self::sourceLimit(strlen($file->contents))) !== null) {
            return new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => $reason]]);
        }
        $ast = $file->syntax();
        if ($ast === null) {
            return new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => 'Unparseable source.']]);
        }
        foreach ((new NodeFinder)->findOfKind($ast, 'statement.class-like') as $class) {
            if (! isset($class->namespacedName) && ! $this->catalogMode) {
                $this->notices[] = ['line' => $class->getStartLine(), 'reason' => 'Anonymous class dispatch is unresolved.'];

                continue;
            }
            $name = isset($class->namespacedName) ? $class->namespacedName->toString() : '(anonymous) '.$file->path.':'.$class->getStartFilePos();
            $parents = [];
            if (Node::is($class, 'statement.class') && $class->extends !== null) {
                $parents[] = $file->resolvedName($class->extends);
            }
            foreach (Node::is($class, 'statement.interface') ? $class->extends : (Node::is($class, 'statement.class') || Node::is($class, 'statement.enum') ? $class->implements : []) as $parent) {
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
                $signature['abstract'] = Node::is($class, 'statement.interface') || $signature['abstract'];
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
            $classes[$name] = ['kind' => Node::is($class, 'statement.interface') ? 'interface' : (Node::is($class, 'statement.trait') ? 'trait' : 'class'),
                'line' => $class->getStartLine(), 'final' => Node::is($class, 'statement.class') && $class->isFinal(), 'abstract' => Node::is($class, 'statement.class') && $class->isAbstract(), 'parents' => $parents,
                'traits' => $traits, 'adaptations' => $adaptations, 'properties' => $properties, 'methods' => $methods];
        }
        $this->staticContext = true;
        $vars = [];
        $this->walk($ast, $file, '', '(file) '.$file->path, null, [], $vars);

        return new ImpactFacts($file->path, $classes, $this->calls, $this->notices);
    }

    /** Explicitly analyzes a registered route callback, leaving arbitrary callbacks unresolved.
     */
    public function extractCallback(FileContext $file, Node $callback, string $from): ImpactFacts
    {
        $this->catalogMode = false;
        $this->catalogReturns = [];
        $this->descriptorReceivers = [];
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
        if (Node::is($callback, 'expression.closure')) {
            $this->walk($callback->stmts, $file, '', $from, null, [], $vars);
        } else {
            $this->expression($callback->expr, $file, '', $from, null, [], $vars);
        }

        return new ImpactFacts($file->path, [], $this->calls, $this->notices);
    }

    /** Check before allocating either source or parser nodes.
     * @phpstan-impure
     */
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
            if ($this->limited) {
                return;
            }
            if (++$this->nodes > 20000) {
                $this->notices[] = ['line' => $node->getStartLine(), 'reason' => 'Per-file impact AST limit reached.'];
                $this->limited = true;

                return;
            }
            if (Node::is($node, 'statement.function')) {
                if ($this->catalogMode) {
                    $functionVars = [];
                    foreach ($node->params as $param) {
                        if (Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
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
            if (Node::is($node, 'statement.class-like')) {
                continue;
            }
            if (Node::is($node, 'expression')) {
                $this->expression($node, $file, $class, $from, $parent, $properties, $vars);

                continue;
            }
            if (Node::is($node, 'statement.global') || Node::is($node, 'statement.static') || Node::is($node, 'statement.foreach') && $node->byRef) {
                $this->referenceScopes[$from] = true;
            }
            if (Node::is($node, 'statement.unset')) {
                foreach ($node->vars as $var) {
                    if (Node::is($var, 'expression.variable') && is_string($var->name)) {
                        $vars[$var->name] = ['type' => null, 'exact' => false];
                    }
                }
            }
            if (Node::is($node, 'statement.foreach')) {
                foreach ([$node->keyVar, $node->valueVar] as $var) {
                    if (Node::is($var, 'expression.variable') && is_string($var->name)) {
                        $vars[$var->name] = ['type' => null, 'exact' => false];
                    }
                }
            }
            if ($this->catalogMode && Node::is($node, 'statement.return') && $node->expr !== null) {
                $value = $this->expression($node->expr, $file, $class, $from, $parent, $properties, $vars);
                $this->catalogReturns[] = ['from' => $from, 'line' => $node->getStartLine(), 'end_line' => $node->getEndLine(), 'offset' => $node->getStartFilePos(), 'value' => $value];

                continue;
            }
            if (Node::is($node, 'statement.expression')) {
                $this->expression($node->expr, $file, $class, $from, $parent, $properties, $vars);

                continue;
            }
            $branched = Node::is($node, 'statement.if') || Node::is($node, 'statement.switch') || Node::is($node, 'statement.try-catch') || Node::is($node, 'statement.for') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while') || Node::is($node, 'statement.do');
            // Conditions execute before branch bodies. Loops may revisit the body
            // after any write in the loop, so never seed it with a stale local type.
            $keys = $node->getSubNodeNames();
            if (Node::is($node, 'statement.if') || Node::is($node, 'statement.switch')) {
                $this->expression($node->cond, $file, $class, $from, $parent, $properties, $vars);
                $keys = array_values(array_diff($keys, ['cond']));
            }
            if (Node::is($node, 'statement.for') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while') || Node::is($node, 'statement.do')) {
                foreach ((new NodeFinder)->find([$node], static fn (Node $child): bool => Node::is($child, 'expression.assign') || Node::is($child, 'expression.assign-ref') || Node::is($child, 'expression.assign-op') || Node::is($child, 'expression.pre-inc') || Node::is($child, 'expression.post-inc') || Node::is($child, 'expression.pre-dec') || Node::is($child, 'expression.post-dec') || Node::is($child, 'expression.call-like') || Node::is($child, 'statement.unset') || Node::is($child, 'statement.foreach')) as $write) {
                    if (Node::is($write, 'expression.assign-ref')) {
                        // An alias may mutate any local on the next iteration.
                        foreach (array_keys($vars) as $var) {
                            if ($var !== 'this') {
                                $vars[$var] = ['type' => null, 'exact' => false];
                            }
                        }

                        continue;
                    }
                    if (Node::is($write, 'expression.call-like')) {
                        $targets = $write->isFirstClassCallable() ? [] : array_map(static fn (Node $arg): Node => $arg->value, $write->getArgs());
                    } elseif (Node::is($write, 'statement.unset')) {
                        $targets = $write->vars;
                    } elseif (Node::is($write, 'statement.foreach')) {
                        $targets = [$write->keyVar, $write->valueVar];
                    } else {
                        /** @var Node $write */
                        $targets = [$write->var];
                        if (! (Node::is($write->var, 'expression.variable') && is_string($write->var->name)) && ! Node::is($write->var, 'expression.property-fetch')) {
                            // Destructuring/dynamic targets can overwrite any local.
                            foreach (array_keys($vars) as $var) {
                                if ($var !== 'this') {
                                    $vars[$var] = ['type' => null, 'exact' => false];
                                }
                            }
                        }
                    }
                    foreach ($targets as $target) {
                        if (Node::is($target, 'expression.variable') && is_string($target->name)) {
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
     *
     * @phpstan-impure
     */
    private function expression(Node $expr, FileContext $file, string $class, string $from, ?string $parent, array $properties, array &$vars): array
    {
        $unknown = ['type' => null, 'exact' => false];
        if ($this->limited) {
            return $unknown;
        }
        if ($this->catalogMode && Node::is($expr, 'scalar.string')) {
            return [...$unknown, 'callable_name' => self::namedCallable($expr->value)];
        }
        if (++$this->nodes > 20000) {
            $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Per-file impact AST limit reached.'];
            $this->limited = true;

            return $unknown;
        }
        if (Node::is($expr, 'expression.variable')) {
            return is_string($expr->name) ? ($vars[$expr->name] ?? $unknown) : $unknown;
        }
        if (Node::is($expr, 'expression.closure') || Node::is($expr, 'expression.arrow-function')) {
            if ($this->catalogMode) {
                $callbackVars = Node::is($expr, 'expression.arrow-function') ? $vars : [];
                if (Node::is($expr, 'expression.closure')) {
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
                    if (Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
                        $callbackVars[$param->var->name] = ['type' => $this->type($file, $param->type, $class, $parent), 'exact' => false];
                    }
                }
                $name = '(closure) '.$file->path.':'.$expr->getStartFilePos();
                if (array_filter($expr->params, fn ($param) => $param->byRef) !== []) {
                    $this->referenceScopes[$name] = true;
                }
                $context = $this->staticContext;
                $this->staticContext = $expr->static || $context;
                if (Node::is($expr, 'expression.closure')) {
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
        if (Node::is($expr, 'expression.assign') || Node::is($expr, 'expression.assign-ref')) {
            $value = $this->expression($expr->expr, $file, $class, $from, $parent, $properties, $vars);
            if (Node::is($expr, 'expression.assign-ref')) {
                $this->referenceScopes[$from] = true;
                foreach (array_keys($vars) as $var) {
                    if ($var !== 'this') {
                        $vars[$var] = $unknown;
                    }
                }

                return $unknown;
            }
            if (Node::is($expr->var, 'expression.variable') && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $value;
            } elseif (Node::is($expr->var, 'expression.property-fetch') && Node::is($expr->var->name, 'identifier')) {
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
        if (Node::is($expr, 'expression.property-fetch') || Node::is($expr, 'expression.nullsafe-property-fetch')) {
            $owner = $this->expression($expr->var, $file, $class, $from, $parent, $properties, $vars);
            if ($owner['type'] !== null && Node::is($expr->name, 'identifier')) {
                $prop = $expr->name->toString();
                if (isset($vars['@property:'.$owner['type'].'#'.$prop])) {
                    return $vars['@property:'.$owner['type'].'#'.$prop];
                }

                return ['type' => $owner['type'] === $class && isset($properties[$prop]) ? $properties[$prop] : '@property:'.($this->catalogMode ? $this->compactReceiver($owner['type']) : $owner['type']).'#'.$prop, 'exact' => false];
            }

            return $unknown;
        }
        if (Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call') || Node::is($expr, 'expression.static-call') || Node::is($expr, 'expression.new')) {
            $receiver = Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call')
                ? $this->expression($expr->var, $file, $class, $from, $parent, $properties, $vars)
                : ['type' => Node::is($expr->class, 'name') ? $this->type($file, $expr->class, $class, $parent) : null, 'exact' => Node::is($expr->class, 'name') && strtolower($expr->class->toString()) !== 'static'];
            if ($this->catalogMode && Node::is($expr, 'expression.new') && Node::is($expr->class, 'statement.class')) {
                $receiver = ['type' => '(anonymous) '.$file->path.':'.$expr->class->getStartFilePos(), 'exact' => true];
            }
            $method = Node::is($expr, 'expression.new') ? '__construct' : (Node::is($expr->name, 'identifier') ? $expr->name->toString() : null);
            $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => $method, 'exact' => $receiver['exact'],
                'kind' => $expr->isFirstClassCallable() ? 'reference' : 'call', 'line' => $expr->getStartLine(), 'site' => MethodSignature::site($expr, $class, $this->staticContext)];
            $position = array_key_last($this->calls);
            if ($this->catalogMode) {
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
                $this->calls[$position]['this_receiver'] = (Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call'))
                    && ($receiver['bound_this'] ?? false);
                $this->calls[$position]['late_static_receiver'] = Node::is($expr, 'expression.static-call')
                    && Node::is($expr->class, 'name') && strtolower($expr->class->toString()) === 'static';
                $this->calls[$position]['lexical_static_receiver'] = Node::is($expr, 'expression.static-call') && Node::is($expr->class, 'name')
                    && in_array(strtolower($expr->class->toString()), ['self', 'parent'], true) ? strtolower($expr->class->toString()) : null;
                if (Node::is($expr, 'expression.new') && in_array(count($expr->args), [2, 3], true)
                    && array_filter($expr->getArgs(), static fn ($argument) => $argument->name !== null && strlen($argument->name->toString()) > 1000) === []) {
                    $this->calls[$position]['provider_constructor_arguments'] = array_map(static fn ($argument) => [
                        'name' => $argument->name?->toString(), 'unpack' => $argument->unpack, 'by_ref' => $argument->byRef,
                    ], $expr->getArgs());
                }
            }
            $deep = $this->mode === AnalysisMode::Deep;
            $saloonArguments = null;
            if (($this->catalogMode && $deep) && (Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call'))
                && ! $expr->isFirstClassCallable() && isset(CatalogSaloonOperations::PARAMETERS[strtolower($method ?? '')])) {
                $saloonArguments = CatalogSaloonOperations::arguments(strtolower($method), $expr->getArgs());
                $this->calls[$position]['saloon_request'] = ['valid' => $saloonArguments['valid'], 'receiver' => null,
                    'request_side_valid' => CatalogSaloonOperations::requestSideArguments(strtolower($method), $expr->getArgs()), 'mock_receiver' => null];
            }
            if (! $expr->isFirstClassCallable()) {
                foreach ($expr->getArgs() as $argumentPosition => $arg) {
                    $value = $this->expression($arg->value, $file, $class, $from, $parent, $properties, $vars);
                    $poolRequests = ($this->catalogMode && $deep) && (Node::is($expr, 'expression.new') && CatalogSaloonOperations::poolConstructor($file, $expr)
                        && ($arg->name?->toString() ?? ($argumentPosition === 1 ? 'requests' : '')) === 'requests'
                        || (Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call'))
                        && in_array(strtolower($method ?? ''), ['pool', 'setrequests'], true)
                        && ($arg->name?->toString() ?? ($argumentPosition === 0 ? 'requests' : '')) === 'requests');
                    if (($this->catalogMode && $deep) && ! $arg->unpack && ! $arg->byRef
                        && ($value['type'] !== null || isset($value['callable_name']))) {
                        $poolConstructor = Node::is($expr, 'expression.new') && CatalogSaloonOperations::poolConstructor($file, $expr);
                        $poolMethod = Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call') ? strtolower($method ?? '') : '';
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
                    if (($this->catalogMode && $deep) && Node::is($expr, 'expression.new') && CatalogSaloonOperations::poolConstructor($file, $expr)
                        && ($arg->name?->toString() ?? ($argumentPosition === 0 ? 'connector' : '')) === 'connector') {
                        $this->calls[$position]['saloon_pool_connector'] = $value['type'];
                    }
                    if ($saloonArguments !== null && $saloonArguments['request'] === $argumentPosition) {
                        $this->calls[$position]['saloon_request']['receiver'] = $value['type'];
                    }
                    if ($saloonArguments !== null && $argumentPosition === 0) {
                        $this->calls[$position]['saloon_request']['mock_receiver'] = $value['type'];
                    }
                    if (($this->catalogMode && $deep) && Node::is($expr, 'expression.new') && count($expr->args) === 3
                        && ($arg->name?->toString() === 'gateway' || $arg->name === null && $argumentPosition === 0)
                        && ! $arg->unpack && ! $arg->byRef && $value['type'] !== null) {
                        $this->calls[$position]['provider_gateway_value'] = $value;
                    }
                    if (($this->catalogMode && $deep) && in_array(strtolower($method ?? ''), CatalogAiGateways::SETTERS, true) && $argumentPosition === 0
                        && ! $arg->unpack && ! $arg->byRef && $value['type'] !== null) {
                        $this->calls[$position]['provider_gateway_value'] = $value;
                    }
                    if (($this->catalogMode && $deep) && strtolower($method ?? '') === 'ontoolinvocation' && $argumentPosition < 128
                        && ! $arg->unpack && ! $arg->byRef && $value['type'] !== null) {
                        $this->calls[$position]['gateway_callback_values'][$argumentPosition] = $value;
                    }
                    $parameters = ($this->catalogMode && $deep) ? CatalogAiOperations::invocationParameters($method ?? '') : null;
                    if (($this->catalogMode && $deep) && $parameters !== null && ! $arg->unpack && ! $arg->byRef
                        && ($arg->name?->toString() ?? ($parameters[$argumentPosition] ?? null)) === 'attachments'
                        && isset($value['catalog_attachments'])) {
                        $this->calls[$position]['ai_attachments'] = $value['catalog_attachments'];
                    }
                    if (($this->catalogMode && $deep) && $parameters !== null && ! $arg->unpack && ! $arg->byRef
                        && ($arg->name?->toString() ?? ($parameters[$argumentPosition] ?? null)) === 'attachments'
                        && str_starts_with($value['type'] ?? '', '@return:') && isset($value['factory_call'])) {
                        $this->calls[$position]['ai_attachment_factory'] = ['receiver' => $value['type'], 'call' => $value['factory_call']];
                    }
                    if (($this->catalogMode && $deep) && in_array(strtolower($method ?? ''), ['each', 'then', 'catch'], true) && count($expr->args) === 1
                        && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'callback') && ($value['type'] !== null || isset($value['callable_name']))) {
                        $this->calls[$position]['callback_value'] = $value;
                    }
                    // Application calls may mutate arguments by reference. Do not carry stale types.
                    if (Node::is($arg->value, 'expression.variable') && is_string($arg->value->name)) {
                        $vars[$arg->value->name] = ($this->catalogMode && $deep)
                            ? $this->argumentValue($value, $this->calls[$position], $expr, $class, $argumentPosition)
                            : $unknown;
                    }
                }
            }

            if (Node::is($expr, 'expression.new')) {
                if ($this->catalogMode) {
                    $receiver['catalog_instance_origin'] = max(0, $expr->getEndFilePos());
                }

                return $receiver;
            }
            if ($this->catalogMode && $this->mode === AnalysisMode::Deep && $receiver['type'] !== null && $method !== null) {
                if (self::sourceLimit(0) !== null) {
                    $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Impact memory limit reached during extraction.'];
                    $this->limited = true;

                    return $unknown;
                }
                // Each previous result is stored once, not quoted inside every later result.
                $type = $this->compactReceiver($receiver['type']);
                $descriptor = json_encode([$type, $method, $receiver['exact']], JSON_THROW_ON_ERROR);
                if (strlen($descriptor) > 8192) {
                    $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Receiver descriptor exceeded its source budget.'];

                    return $unknown;
                }
                $result = ['type' => ($expr->isFirstClassCallable() ? '@method-callable:' : '@return:').$descriptor, 'exact' => false];
                if (! $expr->isFirstClassCallable()) {
                    $result['catalog_origin'] = max(0, $expr->getEndFilePos());
                    $result['factory_call'] = ['creator' => $class, 'form' => Node::is($expr, 'expression.static-call') ? 'static' : 'instance',
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
                    $result['callable_form'] = Node::is($expr, 'expression.static-call')
                        && (! in_array($call['site']['form'], ['self', 'parent', 'static'], true) || $this->staticContext) ? 'static' : 'instance';
                    if (Node::is($expr, 'expression.static-call') && $call['site']['form'] === 'class' && ! $this->staticContext) {
                        $result['callable_form'] = 'bound-class';
                    }
                    $result['bound_callable'] = ['method' => $method, 'this' => $call['this_receiver'], 'late' => $call['late_static_receiver'], 'lexical' => $call['lexical_static_receiver'], 'creator' => $from, 'scope_bound' => true];
                }

                return $result;
            }

            return $unknown;
        }
        if ($this->catalogMode && Node::is($expr, 'expression.func-call')) {
            if (Node::is($expr->name, 'name')) {
                $resolved = $file->resolvedName($expr->name);
                $namespaced = $expr->name->getAttribute('namespacedName');
                $candidates = Node::is($namespaced, 'name') ? [$namespaced->toString(), $resolved] : [$resolved];
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
                if (! $globalJson && Node::is($arg->value, 'expression.variable') && is_string($arg->value->name)) {
                    $vars[$arg->value->name] = $this->mode === AnalysisMode::Deep ? $this->argumentValue($value, $this->calls[$position], $expr, $class, $argPosition, $receiver) : $unknown;
                }
            }

            if ($this->mode === AnalysisMode::Basic) {
                return $expr->isFirstClassCallable() ? $receiver : $unknown;
            }
            $result = $expr->isFirstClassCallable() ? $receiver : ($receiver['type'] !== null
                ? ['type' => '@return:'.json_encode([$receiver['type'], '__invoke', $receiver['exact']], JSON_THROW_ON_ERROR), 'exact' => false,
                    'catalog_origin' => max(0, $expr->getEndFilePos()),
                    'factory_call' => ['creator' => $class, 'form' => Node::is($expr->name, 'name') ? 'function' : 'instance', 'binding' => null]]
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
        if (Node::is($expr, 'expression.array') && count($expr->items) === 2) {
            $items = $this->catalogMode ? self::callableArrayItems($expr) : $expr->items;
            $first = $items[0] ?? null;
            $second = $items[1] ?? null;
            if ($first !== null && Node::is($second?->value, 'scalar.string')
                && ! $first->unpack && ! $first->byRef && ! $second->unpack && ! $second->byRef
                && ($first->key === null || (Node::is($first->key, 'scalar.int') && $first->key->value === 0))
                && ($second->key === null || (Node::is($second->key, 'scalar.int') && $second->key->value === 1))) {
                $value = $first->value;
                if ($this->catalogMode && Node::is($value, 'scalar.string')) {
                    return [...$unknown, 'callable_name' => self::namedCallable($value->value.'::'.$second->value->value)];
                }
                $receiver = Node::is($value, 'expression.class-const-fetch') && Node::is($value->class, 'name') && Node::is($value->name, 'identifier') && strtolower($value->name->toString()) === 'class'
                    ? ['type' => $this->type($file, $value->class, $class, $parent), 'exact' => true]
                    : $this->expression($value, $file, $class, $from, $parent, $properties, $vars);
                if ($receiver['type'] !== null) {
                    $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => $second->value->value, 'exact' => $receiver['exact'], 'kind' => 'reference', 'line' => $expr->getStartLine(), 'site' => ['form' => 'reference', 'class' => $class, 'static_context' => $this->staticContext, 'arguments' => []]];

                    if ($this->catalogMode) {
                        $position = array_key_last($this->calls);
                        $this->calls[$position]['offset'] = $expr->getStartFilePos();
                        $this->calls[$position]['end_line'] = $expr->getEndLine();
                        $this->calls[$position]['site']['form'] = Node::is($value, 'expression.class-const-fetch') ? 'class' : 'instance';

                        return ['type' => '@method-callable:'.json_encode([$receiver['type'], $second->value->value, $receiver['exact']], JSON_THROW_ON_ERROR), 'exact' => false,
                            'callable_form' => Node::is($value, 'expression.class-const-fetch') ? 'static' : 'instance',
                            'bound_callable' => ['method' => $second->value->value, 'this' => $receiver['bound_this'] ?? false, 'late' => false, 'lexical' => null, 'creator' => $from, 'scope_bound' => false]];
                    }

                    return $unknown;
                }
            }
        }
        if ($this->catalogMode && $this->mode === AnalysisMode::Deep && Node::is($expr, 'expression.array') && CatalogSaloonOperations::poolMemberArray($expr)) {
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
                if ($item->byRef && Node::is($item->value, 'expression.variable') && is_string($item->value->name)) {
                    $vars[$item->value->name] = $unknown;
                }
            }

            return [...$unknown, 'catalog_attachments' => CatalogAiAttachments::extract($file, $expr),
                'catalog_pool_members' => ['complete' => $complete, 'members' => $members]];
        }
        $branching = Node::is($expr, 'expression.ternary') || Node::is($expr, 'expression.match') || Node::is($expr, 'expression.binary-op.boolean-and') || Node::is($expr, 'expression.binary-op.boolean-or') || Node::is($expr, 'expression.binary-op.coalesce');
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
        if (Node::is($expr, 'expression.func-call') && ! $expr->isFirstClassCallable()) {
            foreach ($expr->getArgs() as $arg) {
                if (Node::is($arg->value, 'expression.variable') && is_string($arg->value->name)) {
                    $vars[$arg->value->name] = $unknown;
                }
            }
        }
        if (Node::is($expr, 'expression.assign-op') || Node::is($expr, 'expression.pre-inc') || Node::is($expr, 'expression.post-inc') || Node::is($expr, 'expression.pre-dec') || Node::is($expr, 'expression.post-dec')) {
            if (Node::is($expr->var, 'expression.variable') && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $unknown;
            }
        }

        return $this->catalogMode && $this->mode === AnalysisMode::Deep && Node::is($expr, 'expression.array')
            ? [...$unknown, 'catalog_attachments' => CatalogAiAttachments::extract($file, $expr)] : $unknown;
    }

    private function type(FileContext $file, Node|string|null $type, string $class, ?string $parent): ?string
    {
        if (Node::is($type, 'nullable-type')) {
            return $this->type($file, $type->type, $class, $parent);
        }
        if ($this->catalogMode && (Node::is($type, 'union-type') || Node::is($type, 'intersection-type'))) {
            $types = [];
            foreach ($type->types as $member) {
                $resolved = $this->type($file, $member, $class, $parent);
                if ($resolved !== null) {
                    $types[] = $resolved;
                }
            }

            return $types === [] ? null : '@types:'.json_encode(array_values(array_unique($types)), JSON_THROW_ON_ERROR);
        }
        if (! Node::is($type, 'name')) {
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

    /** @return array<int, Node> */
    public static function callableArrayItems(Node $array): array
    {
        if (count($array->items) !== 2) {
            return [];
        }
        $items = [];
        $next = 0;
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || $item->key !== null && ! Node::is($item->key, 'scalar.int')) {
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
    private function argumentValue(array $value, array $call, Node $expression, string $creator, int $position, array $invocation = []): array
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
            || Node::is($expression, 'expression.new')) {
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
            if (Node::is($variable, 'expression.variable') && Node::is($argument->value, 'expression.variable') && $variable->name === $argument->value->name) {
                $aliases[] = $offset;
            }
        }
        $namedFunction = Node::is($expression, 'expression.func-call') && (Node::is($expression->name, 'name')
            || str_starts_with($call['receiver'], '@function:') || str_starts_with($call['receiver'], '@closure:'));
        $form = $namedFunction ? 'function' : (Node::is($expression, 'expression.static-call') ? 'static' : 'instance');
        $binding = Node::is($expression, 'expression.static-call') && Node::is($expression->class, 'name')
            && in_array(strtolower($expression->class->toString()), ['self', 'parent', 'static'], true) ? strtolower($expression->class->toString()) : null;
        if (Node::is($expression, 'expression.func-call') && str_starts_with($call['receiver'], '@method-callable:')) {
            $bound = $invocation['bound_callable'] ?? null;
            $callableForm = $invocation['callable_form'] ?? null;
            $target = ReceiverDescriptors::decode(substr($call['receiver'], 17), $this->descriptorReceivers);
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
        if (Node::is($expression, 'expression.func-call') && str_starts_with($call['receiver'], '@return:') && isset($invocation['factory_call'])) {
            $context['factory_call'] = $invocation['factory_call'];
        }
        if (self::sourceLimit(0) !== null) {
            $this->notices[] = ['line' => $expression->getStartLine(), 'reason' => 'Impact memory limit reached during extraction.'];
            $this->limited = true;

            return $unknown;
        }
        $descriptor = json_encode([$this->compactReceiver($value['type']), $this->compactReceiver($call['receiver']), $call['method'], $call['exact'],
            $context, $arguments, $aliases], JSON_THROW_ON_ERROR);
        if (strlen($descriptor) > 8192) {
            $this->notices[] = ['line' => $expression->getStartLine(), 'reason' => 'Argument value flow descriptor exceeded its source budget.'];

            return $unknown;
        }

        return [...$value, 'type' => '@after-argument:'.$descriptor];
    }

    private function compactReceiver(string $receiver): string
    {
        if (! str_starts_with($receiver, '@return:') && ! str_starts_with($receiver, '@method-callable:') && ! str_starts_with($receiver, '@after-argument:') && ! str_starts_with($receiver, '@property:')) {
            return $receiver;
        }
        $hash = hash('sha256', $receiver);
        $this->descriptorReceivers[$hash] = $receiver;

        return ReceiverDescriptors::PREFIX.$hash;
    }

    /** @return list<Node> */
    private function children(mixed $value): array
    {
        return Node::is($value, 'node') ? [$value] : (is_array($value) ? array_values(array_filter($value, fn ($v): bool => Node::is($v, 'node'))) : []);
    }
}
