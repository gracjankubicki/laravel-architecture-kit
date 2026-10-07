<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Returned rule candidates retain source evidence without recording validation data. */
final class ValidationCatalogExtractor
{
    /** @var list<CatalogRelation> */
    private array $relations = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visits = 0;

    /** @var array<string, string> */
    private array $owners = [];

    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var array<int, string> */
    private array $sites = [];

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->relations = $this->diagnostics = [];
        $this->visits = 0;
        $this->owners = [];
        $this->elements = [];
        $this->sites = [];
        $methods = [];
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['method', 'function', 'closure'], true)) {
                $methods[$element->offset] = $element;
                if ($element->kind === 'method') {
                    $this->owners[$element->id] = substr($element->name, 0, (int) strrpos($element->name, '::'));
                }
            }
        }
        foreach ((new NodeFinder)->find($file->ast() ?? [], fn ($node) => $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) as $method) {
            if (! $method instanceof Stmt\ClassMethod && ! $method instanceof Stmt\Function_ && ! $method instanceof Expr\Closure && ! $method instanceof Expr\ArrowFunction) {
                continue;
            }
            $element = $methods[$method->getStartFilePos()] ?? null;
            if ($element !== null) {
                $nodes = $method instanceof Expr\ArrowFunction ? [new Stmt\Return_($method->expr, $method->expr->getAttributes())] : ($method->stmts ?? []);
                $vars = [];
                foreach ($method->params as $parameterIndex => $param) {
                    if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                        $vars[$param->var->name] = ['parameter_index' => $parameterIndex];
                    }
                    if ($param->var instanceof Expr\Variable && is_string($param->var->name) && $param->type !== null) {
                        $types = $this->parameterTypes($file, $param->type, $element->id, 0);
                        if (count($types) > 128) {
                            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Validation parameter exceeds its 128 type alternative budget.', max(1, $param->getStartLine()), $element->id);
                            $types = array_slice($types, 0, 128);
                        }
                        if ($types !== []) {
                            $vars[$param->var->name]['type'] = count($types) === 1 ? $types[0] : '@types:'.json_encode($types, JSON_THROW_ON_ERROR);
                        }
                    }
                }
                $generator = (new NodeFinder)->findFirst($nodes, fn ($node) => $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) !== null;
                $this->returns($file, $nodes, $element->id, vars: $vars, extractRules: $element->kind === 'method' && str_ends_with(strtolower($element->name), '::rules'), recordReturns: ! $generator);
                if (! $method instanceof Expr\ArrowFunction && $method->stmts !== null
                    && ! $generator && ($method->returnType === null || $method->returnType instanceof Node\Identifier && strtolower($method->returnType->toString()) === 'void')
                    && count(array_filter($nodes, fn ($node) => ! $node instanceof Stmt\Nop && ! ($node instanceof Stmt\Expression && ! $node->expr instanceof Expr\Throw_ && ! $node->expr instanceof Expr\Exit_))) === 0) {
                    $this->relations[] = new CatalogRelation($element->id, 'value:null', 'returns-null', max(1, $method->getEndLine()), max(1, $method->getEndLine()), 'conditional', ['return_origin' => 'implicit']);
                }
            }
        }
        $this->returns($file, $file->ast() ?? [], CatalogElement::identity($file->path, 'file', $file->path), extractRules: false);

        return new CatalogFacts($file->path, elements: $this->elements, relations: $this->relations, diagnostics: $this->diagnostics);
    }

    /** @param list<Node> $nodes
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    private function returns(FileContext $file, array $nodes, string $id, int $depth = 0, array $vars = [], bool $extractRules = true, bool $recordReturns = false): array
    {
        foreach ($nodes as $node) {
            if (! $this->budget($node, $id, $depth)) {
                return [];
            }
            if ($node instanceof Stmt\Return_) {
                if ($recordReturns) {
                    $return = $node->expr;
                    $bindings = $vars;
                    if ($return !== null) {
                        [$return, $bindings] = $this->boundValue($return, $vars, $id, $depth + 1);
                    }
                    if ($return === null || $return instanceof Expr\ConstFetch && strtolower($return->name->toString()) === 'null') {
                        $this->relations[] = new CatalogRelation($id, 'value:null', 'returns-null', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional', ['offset' => max(0, $node->getStartFilePos())]);
                    } elseif ($return instanceof Expr\Variable && is_string($return->name) && isset($bindings[$return->name]['parameter_index'])) {
                        $index = $bindings[$return->name]['parameter_index'];
                        $this->relations[] = new CatalogRelation($id, 'value:parameter:'.$index, 'returns-parameter', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional', ['parameter_index' => $index, 'offset' => max(0, $node->getStartFilePos())]);
                    }
                }
                if ($node->expr !== null) {
                    $this->standalone($file, $node->expr, $id, $vars, $depth + 1);
                    if ($extractRules) {
                        $this->value($file, $node->expr, $id, $depth + 1, $vars);
                    }
                }

                continue;
            }
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
                continue;
            }
            if ($node instanceof Stmt\Expression || $node instanceof Expr) {
                $expr = $node instanceof Stmt\Expression ? $node->expr : $node;
                $this->standalone($file, $expr, $id, $vars, $depth + 1);
                $this->invalidate($expr, $vars, $id, $depth + 1);
                if ($expr instanceof Expr\Assign && $expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                    $vars[$expr->var->name] = ['node' => $expr->expr, 'vars' => $vars];
                }

                continue;
            }
            if ($node instanceof Stmt\Unset_) {
                foreach ($node->vars as $target) {
                    $this->invalidateTarget($target, $vars);
                }

                continue;
            }
            if ($node instanceof Stmt\Foreach_) {
                foreach ([$node->keyVar, $node->valueVar] as $target) {
                    if ($target !== null) {
                        $this->invalidateTarget($target, $vars);
                    }
                }
            }
            $branch = $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Catch_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\Case_ || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_;
            foreach ($node->getSubNodeNames() as $key) {
                $value = $node->$key;
                $children = array_values(array_filter(is_array($value) ? $value : [$value], fn ($child) => $child instanceof Node));
                $changed = $this->returns($file, $children, $id, $depth + 1, $vars, $extractRules, $recordReturns);
                if ($branch) {
                    foreach ($changed as $var => $binding) {
                        if (($vars[$var] ?? null) !== $binding) {
                            $vars[$var] = null;
                        }
                    }
                } else {
                    $vars = $changed;
                }
            }
        }

        return $vars;
    }

    /** @param array<string, mixed> $vars */
    private function value(FileContext $file, Node $node, string $id, int $depth, array $vars = []): void
    {
        if (! $this->budget($node, $id, $depth)) {
            return;
        }
        if ($node instanceof Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item !== null) {
                    $this->value($file, $item->value, $id, $depth + 1, $vars);
                }
            }

            return;
        }
        if ($node instanceof Expr\Ternary) {
            $this->value($file, $node->if ?? $node->cond, $id, $depth + 1, $vars);
            $this->value($file, $node->else, $id, $depth + 1, $vars);

            return;
        }
        if ($node instanceof Expr\BinaryOp\Coalesce) {
            $this->value($file, $node->left, $id, $depth + 1, $vars);
            $this->value($file, $node->right, $id, $depth + 1, $vars);

            return;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $binding = $vars[$node->name] ?? null;
            if (is_array($binding) && ($binding['node'] ?? null) instanceof Expr && is_array($binding['vars'] ?? null)) {
                $this->value($file, $binding['node'], $id, $depth + 1, $binding['vars']);

                return;
            }
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            $this->relations[] = new CatalogRelation($id, 'php:'.$file->resolvedName($node->class), 'returned-rule-candidate', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional');

            return;
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Stmt\Class_) {
            $name = '(anonymous) '.$file->path.':'.$node->class->getStartFilePos();
            $this->relations[] = new CatalogRelation($id, 'php:'.$name, 'returned-rule-candidate', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional');

            return;
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Illuminate\Validation\Rule'
            && in_array(strtolower($node->name->toString()), ['when', 'unless', 'foreach'], true)) {
            $this->frameworkRules($file, $node, $id, $depth + 1, $vars);

            return;
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Illuminate\\Validation\\Rule' && CatalogRuleFactories::type(strtolower($node->name->toString())) !== null) {
            $this->standardRule($file, $node, $id, $depth + 1, $vars);

            return;
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $this->fluentRule($file, $node, $id, $depth + 1, $vars)) {
            return;
        }
        $receiver = null;
        $method = '__invoke';
        $factoryCall = ['creator' => $this->owners[$id] ?? '', 'form' => 'function', 'binding' => null];
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $binding = strtolower($node->class->toString());
            $factoryCall['form'] = 'static';
            $factoryCall['binding'] = in_array($binding, ['self', 'static', 'parent'], true) ? $binding : null;
            $receiver = match ($factoryCall['binding']) {
                'self', 'static' => $this->owners[$id] ?? null,
                'parent' => isset($this->owners[$id]) ? '@parent:'.$this->owners[$id] : null,
                default => $file->resolvedName($node->class),
            };
            $method = $node->name->toString();
        } elseif (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $receiver = $this->receiverType($file, $node->var, $id, $vars, $depth + 1);
            $method = $node->name->toString();
            $factoryCall['form'] = 'instance';
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && ! $node->isFirstClassCallable()) {
            $resolved = $file->resolvedName($node->name);
            $namespaced = $node->name->getAttribute('namespacedName');
            $names = array_values(array_unique($namespaced instanceof Node\Name ? [$namespaced->toString(), $resolved] : [$resolved]));
            $receiver = '@function:'.json_encode($names, JSON_THROW_ON_ERROR);
        }
        if ($receiver !== null) {
            $descriptor = '@return:'.json_encode([$receiver, $method, $factoryCall['form'] !== 'instance' && $factoryCall['binding'] !== 'static'], JSON_THROW_ON_ERROR);
            $this->relations[] = new CatalogRelation($id, 'rule-factory:'.hash('xxh128', $descriptor), 'returned-rule-factory', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional', ['return_receiver' => $descriptor, 'factory_call' => $factoryCall]);

            return;
        }
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $name = '(closure) '.$file->path.':'.$node->getStartFilePos();
            $target = CatalogElement::identity($file->path, 'closure', $name, max(0, $node->getStartFilePos()));
            $this->relations[] = new CatalogRelation($id, $target, 'returned-rule-callback', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional');

            return;
        }
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Expr\ConstFetch) {
            return;
        }
        $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Returned validation rules require source inspection; dynamic values are not evaluated.', max(1, $node->getStartLine()), $id);
    }

    /** @param array<string, mixed> $vars */
    private function fluentRule(FileContext $file, Expr $node, string $owner, int $depth, array $vars): bool
    {
        $modifiers = [];
        $current = $node;
        while ($current instanceof Expr\MethodCall || $current instanceof Expr\NullsafeMethodCall || $current instanceof Expr\Variable && is_string($current->name)) {
            if (! $this->budget($current, $owner, ++$depth)) {
                return true;
            }
            if ($current instanceof Expr\Variable) {
                $binding = $vars[$current->name] ?? null;
                if (! is_array($binding) || ! ($binding['node'] ?? null) instanceof Expr || ! is_array($binding['vars'] ?? null)) {
                    return false;
                }
                $current = $binding['node'];
                $vars = $binding['vars'];

                continue;
            }
            if ($current->isFirstClassCallable() || ! $current->name instanceof Node\Identifier) {
                return false;
            }
            $modifiers[] = ['node' => $current, 'vars' => $vars];
            $current = $current->var;
        }
        if (! $current instanceof Expr\StaticCall || ! $current->class instanceof Node\Name || ! $current->name instanceof Node\Identifier || $current->isFirstClassCallable()
            || $file->resolvedName($current->class) !== 'Illuminate\\Validation\\Rule') {
            return false;
        }
        $factory = strtolower($current->name->toString());
        if (CatalogRuleFactories::type($factory) === null) {
            return false;
        }
        $outer = $modifiers[0] ?? null;
        if ($outer !== null && in_array(strtolower($outer['node']->name->toString()), ['when', 'unless'], true)
            && in_array($factory, CatalogRuleFactories::CONDITIONABLE, true)) {
            $required = CatalogRuleFactories::requiredArgument($factory);
            $hasRequired = $required === null;
            foreach ($current->getArgs() as $position => $arg) {
                $hasRequired = $hasRequired || $arg->name?->toString() === $required || $arg->name === null && $position === 0;
                if ($arg->unpack) {
                    $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable receiver factory arguments are unpacked.', max(1, $current->getStartLine()), $owner);

                    return true;
                }
            }
            if (! $hasRequired) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable receiver factory is missing its required argument.', max(1, $current->getStartLine()), $owner);

                return true;
            }
            foreach (array_slice($modifiers, 1) as $modifier) {
                if (! in_array(strtolower($modifier['node']->name->toString()), CatalogRuleFactories::modifiers($factory), true)) {
                    $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable receiver does not have a source-verified rule-preserving chain.', max(1, $modifier['node']->getStartLine()), $owner);

                    return true;
                }
                foreach ($modifier['node']->getArgs() as $arg) {
                    if ($arg->unpack) {
                        $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable receiver modifier arguments are unpacked.', max(1, $modifier['node']->getStartLine()), $owner);

                        return true;
                    }
                }
            }
            $this->conditionableRule($file, $outer['node'], $owner, $depth + 1, $outer['vars'], $factory);

            return true;
        }
        $names = [];
        foreach (array_reverse($modifiers) as $modifier) {
            $name = strtolower($modifier['node']->name->toString());
            if (! in_array($name, CatalogRuleFactories::modifiers($factory), true)) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Standard rule chain method does not have a source-verified rule-preserving contract.', max(1, $modifier['node']->getStartLine()), $owner);

                return true;
            }
            $names[] = $name;
        }
        $start = count($this->relations);
        $id = $this->standardRule($file, $current, $owner, $depth + 1, $vars);
        if ($id === null) {
            return true;
        }
        $row = $this->relations[$start];
        $this->relations[$start] = new CatalogRelation($row->from, $row->to, $row->kind, $row->line, $row->endLine, $row->resolution, ['fluent_methods' => $names]);
        foreach (array_reverse($modifiers) as $modifier) {
            $name = strtolower($modifier['node']->name->toString());
            if (in_array($factory, ['unique', 'exists'], true) && in_array($name, ['where', 'using'], true)) {
                $this->queryCallback($file, $modifier['node'], $id, $modifier['vars'], $depth + 1, $name);
            }
            if (in_array($factory, ['file', 'imagefile', 'email'], true) && $name === 'rules' || $factory === 'imagefile' && $name === 'dimensions') {
                $this->nestedRuleModifier($file, $modifier['node'], $id, $modifier['vars'], $depth + 1, $factory, $name);
            }
        }

        return true;
    }

    /** Conditionable returns the selected callback result, falling back to the receiver only for null.
     * @param  array<string, mixed>  $vars
     */
    private function conditionableRule(FileContext $file, Expr\MethodCall|Expr\NullsafeMethodCall $node, string $owner, int $depth, array $vars, string $factory): void
    {
        $args = [];
        $names = ['value', 'callback', 'default'];
        foreach ($node->getArgs() as $position => $arg) {
            $key = $arg->name?->toString() ?? ($names[$position] ?? null);
            if ($arg->unpack || $key === null || ! in_array($key, $names, true) || isset($args[$key])) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable rule arguments are unresolved.', max(1, $node->getStartLine()), $owner);

                return;
            }
            $args[$key] = $arg->value;
        }
        if (! isset($args['callback']) && ! isset($args['default'])) {
            $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable with zero or one argument returns a higher-order proxy; a validation rule is not inferred.', max(1, $node->getStartLine()), $owner);

            return;
        }
        $method = strtolower($node->name->toString());
        $known = null;
        $value = $args['value'] ?? null;
        $valueVars = $vars;
        if ($value !== null) {
            [$value, $valueVars] = $this->boundValue($value, $vars, $owner, $depth + 1);
        }
        if ($value === null) {
            $known = false;
        } elseif ($value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true)) {
            $known = strtolower($value->name->toString()) === 'true';
        } elseif ($value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\Float_ || $value instanceof Node\Scalar\String_) {
            $known = (bool) $value->value;
        }
        if ($known !== null && $method === 'unless') {
            $known = ! $known;
        }
        $start = count($this->relations);
        if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
            $this->builderCallback($file, $value, $owner, $factory, $method, 'condition');
        } elseif ($value instanceof Expr\CallLike && $value->isFirstClassCallable()) {
            $this->builderCallable($file, $value, $owner, $valueVars, $depth + 1, 'condition');
        }
        foreach (['callback', 'default'] as $branch) {
            if ($known !== null && ($branch === 'callback') !== $known) {
                continue;
            }
            $callback = $args[$branch] ?? null;
            $callbackVars = $vars;
            if ($callback !== null) {
                [$callback, $callbackVars] = $this->boundValue($callback, $vars, $owner, $depth + 1);
            }
            if ($callback === null || $callback instanceof Expr\ConstFetch && strtolower($callback->name->toString()) === 'null') {
                if ($branch === 'callback') {
                    $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Selected Conditionable callback is null; successful rule construction is not inferred.', max(1, $node->getStartLine()), $owner);

                    continue;
                }
                $this->value($file, $node->var, $owner, $depth + 1, $vars);

                continue;
            }
            if ($callback instanceof Expr\CallLike && $callback->isFirstClassCallable()) {
                $descriptor = $this->builderCallable($file, $callback, $owner, $callbackVars, $depth + 1, $branch);
                if ($descriptor !== null) {
                    $fallbackStart = count($this->relations);
                    $this->value($file, $node->var, $owner, $depth + 1, $vars);
                    for ($i = $fallbackStart; $i < count($this->relations); $i++) {
                        $row = $this->relations[$i];
                        $this->relations[$i] = new CatalogRelation($row->from, $row->to, $row->kind, $row->line, $row->endLine, $row->resolution,
                            [...$row->metadata, 'conditionable_fallback' => $descriptor]);
                    }
                }

                continue;
            }
            if (! $callback instanceof Expr\Closure && ! $callback instanceof Expr\ArrowFunction) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditionable callback requires source callable resolution; its rule result is not inferred.', max(1, $callback->getStartLine()), $owner);

                continue;
            }
            $this->builderCallback($file, $callback, $owner, $factory, $method, $branch);
            $bindings = $callback instanceof Expr\ArrowFunction ? $callbackVars : [];
            if ($callback instanceof Expr\Closure) {
                foreach ($callback->uses as $use) {
                    if (! $use->byRef && is_string($use->var->name) && isset($callbackVars[$use->var->name])) {
                        $bindings[$use->var->name] = $callbackVars[$use->var->name];
                    }
                }
            }
            $param = $callback->params[0] ?? null;
            if ($param !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $bindings[$param->var->name] = ['node' => $node->var, 'vars' => $vars];
            }
            if ($callback instanceof Expr\ArrowFunction) {
                $returns = [$callback->expr];
                $this->value($file, $callback->expr, $owner, $depth + 1, $bindings);
            } else {
                $this->returns($file, $callback->stmts, $owner, $depth + 1, $bindings);
                $returns = $this->callbackReturns($callback->stmts, $owner, $depth + 1);
                $last = $callback->stmts[array_key_last($callback->stmts)] ?? null;
                if (! $last instanceof Stmt\Return_ && ! ($last instanceof Stmt\Expression && $last->expr instanceof Expr\Throw_)) {
                    $this->value($file, $node->var, $owner, $depth + 1, $vars);
                }
            }
            foreach ($returns as $return) {
                if ($return === null || $return instanceof Expr\ConstFetch && strtolower($return->name->toString()) === 'null') {
                    $this->value($file, $node->var, $owner, $depth + 1, $vars);
                }
            }
        }
        for ($i = $start; $i < count($this->relations); $i++) {
            $row = $this->relations[$i];
            $this->relations[$i] = new CatalogRelation($row->from, $row->to, $row->kind, $row->line, $row->endLine, $row->resolution,
                [...$row->metadata, 'conditionable_contexts' => [['factory' => $factory, 'method' => $method], ...($row->metadata['conditionable_contexts'] ?? [])]]);
        }
    }

    /** @param array<string, mixed> $vars
     * @return array<string, mixed>|null
     */
    private function builderCallable(FileContext $file, Expr\CallLike $callback, string $owner, array $vars, int $depth, string $branch): ?array
    {
        $start = count($this->relations);
        $this->conditionCallable($file, $callback, $owner, $vars, $depth + 1);
        $row = $this->relations[$start] ?? null;
        if ($row === null) {
            return null;
        }
        $metadata = [...$row->metadata, 'builder_branch' => $branch];
        $this->relations[$start] = new CatalogRelation($row->from, $row->to, 'returned-rule-builder-callable', $row->line, $row->endLine, $row->resolution, $metadata);
        if ($branch !== 'condition') {
            $descriptor = '@return:'.json_encode([$metadata['callback_receiver'], $metadata['callback_method'], $metadata['callback_exact']], JSON_THROW_ON_ERROR);
            $this->relations[] = new CatalogRelation($owner, 'rule-factory:'.hash('xxh128', $descriptor), 'returned-rule-factory', $row->line, $row->endLine, 'conditional',
                ['return_receiver' => $descriptor, 'factory_call' => $metadata['factory_call'], 'conditionable_supplier' => $row->metadata]);
        }

        return $row->metadata;
    }

    /** Preserve the bindings captured when a local value was assigned.
     * @param  array<string, mixed>  $vars
     * @return array{Expr, array<string, mixed>}
     */
    private function boundValue(Expr $value, array $vars, string $owner, int $depth): array
    {
        while ($value instanceof Expr\Variable && is_string($value->name)) {
            if (! $this->budget($value, $owner, ++$depth)) {
                break;
            }
            $binding = $vars[$value->name] ?? null;
            if (! is_array($binding) || ! ($binding['node'] ?? null) instanceof Expr || ! is_array($binding['vars'] ?? null)) {
                break;
            }
            $value = $binding['node'];
            $vars = $binding['vars'];
        }

        return [$value, $vars];
    }

    /** @param list<Node> $nodes
     * @return list<Expr|null>
     */
    private function callbackReturns(array $nodes, string $owner, int $depth): array
    {
        $returns = [];
        foreach ($nodes as $node) {
            if (! $this->budget($node, $owner, $depth)) {
                break;
            }
            if ($node instanceof Stmt\Return_) {
                $returns[] = $node->expr;
            } elseif (! $node instanceof Expr\Closure && ! $node instanceof Expr\ArrowFunction && ! $node instanceof Stmt\Function_ && ! $node instanceof Stmt\ClassLike) {
                foreach ($node->getSubNodeNames() as $key) {
                    $value = $node->$key;
                    $children = array_values(array_filter(is_array($value) ? $value : [$value], fn ($item) => $item instanceof Node));
                    $returns = [...$returns, ...$this->callbackReturns($children, $owner, $depth + 1)];
                }
            }
        }

        return $returns;
    }

    private function builderCallback(FileContext $file, Expr\Closure|Expr\ArrowFunction $callback, string $owner, string $factory, string $method, string $branch): void
    {
        $name = '(closure) '.$file->path.':'.$callback->getStartFilePos();
        $target = CatalogElement::identity($file->path, 'closure', $name, max(0, $callback->getStartFilePos()));
        $this->relations[] = new CatalogRelation($owner, $target, 'returned-rule-builder-callback', max(1, $callback->getStartLine()), max(1, $callback->getEndLine()), 'conditional',
            ['builder_branch' => $branch]);
    }

    /** Nested constraints are consumed by the outer rule's validator, not by construction.
     * @param  array<string, mixed>  $vars
     */
    private function nestedRuleModifier(FileContext $file, Expr\MethodCall|Expr\NullsafeMethodCall $node, string $owner, array $vars, int $depth, string $factory, string $method): void
    {
        $found = false;
        foreach ($node->getArgs() as $position => $arg) {
            if ($arg->name?->toString() !== ($method === 'dimensions' ? 'dimensions' : 'rules') && ! ($arg->name === null && $position === 0)) {
                continue;
            }
            $found = true;
            if ($arg->unpack) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Nested rule modifier arguments are unpacked; nested rules are unresolved.', max(1, $arg->getStartLine()), $owner);

                continue;
            }
            $start = count($this->relations);
            $this->value($file, $arg->value, $owner, $depth + 1, $vars);
            for ($i = $start; $i < count($this->relations); $i++) {
                $row = $this->relations[$i];
                $this->relations[$i] = new CatalogRelation($row->from, $row->to, $row->kind, $row->line, $row->endLine, $row->resolution,
                    [...$row->metadata, 'deferred_rule_contexts' => [['factory' => $factory, 'modifier' => $method], ...($row->metadata['deferred_rule_contexts'] ?? [])]]);
            }
        }
        if (! $found) {
            $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Nested rule modifier is missing its required argument.', max(1, $node->getStartLine()), $owner);
        }
    }

    /** @param array<string, mixed> $vars */
    private function queryCallback(FileContext $file, Expr\MethodCall|Expr\NullsafeMethodCall $node, string $owner, array $vars, int $depth, string $method): void
    {
        foreach ($node->getArgs() as $position => $arg) {
            if ($arg->name?->toString() !== ($method === 'where' ? 'column' : 'callback') && ! ($arg->name === null && $position === 0)) {
                continue;
            }
            $value = $arg->value;
            while ($value instanceof Expr\Variable && is_string($value->name)) {
                if (! $this->budget($value, $owner, ++$depth)) {
                    return;
                }
                $binding = $vars[$value->name] ?? null;
                if (! is_array($binding) || ! ($binding['node'] ?? null) instanceof Expr || ! is_array($binding['vars'] ?? null)) {
                    break;
                }
                $value = $binding['node'];
                $vars = $binding['vars'];
            }
            if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
                $name = '(closure) '.$file->path.':'.$value->getStartFilePos();
                $target = CatalogElement::identity($file->path, 'closure', $name, max(0, $value->getStartFilePos()));
                $this->relations[] = new CatalogRelation($owner, $target, 'returned-rule-query-callback', max(1, $value->getStartLine()), max(1, $value->getEndLine()), 'conditional');
            } elseif ($value instanceof Expr\CallLike && $value->isFirstClassCallable()) {
                $start = count($this->relations);
                $this->conditionCallable($file, $value, $owner, $vars, $depth + 1);
                if (isset($this->relations[$start])) {
                    $row = $this->relations[$start];
                    $this->relations[$start] = new CatalogRelation($row->from, $row->to, 'returned-rule-query-callable', $row->line, $row->endLine, $row->resolution, $row->metadata);
                }
            } elseif ($method === 'using' || ! $value instanceof Node\Scalar\String_) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Rule database callback is dynamic or unresolved.', max(1, $value->getStartLine()), $owner);
            }
        }
    }

    /** @param array<string, mixed> $vars */
    private function standardRule(FileContext $file, Expr\StaticCall $node, string $owner, int $depth, array $vars): ?string
    {
        $method = strtolower($node->name instanceof Node\Identifier ? $node->name->toString() : '');
        $type = CatalogRuleFactories::type($method);
        if ($type === null) {
            return null;
        }
        $required = CatalogRuleFactories::requiredArgument($method);
        $hasRequired = $required === null;
        foreach ($node->getArgs() as $position => $arg) {
            $hasRequired = $hasRequired || $arg->name?->toString() === $required || $arg->name === null && $position === 0;
            if ($arg->unpack) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Standard rule factory arguments are unpacked; the factory outcome is unresolved.', max(1, $node->getStartLine()), $owner);

                return null;
            }
        }
        if (! $hasRequired) {
            $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Standard rule factory is missing its required argument; a successful rule construction is not inferred.', max(1, $node->getStartLine()), $owner);

            return null;
        }
        $name = '(Rule::'.$method.') '.$file->path.':'.$node->getStartFilePos();
        $id = CatalogElement::identity($file->path, 'framework-validation-rule', $name, max(0, $node->getStartFilePos()));
        $this->elements[] = new CatalogElement($id, $name, 'framework-validation-rule', max(1, $node->getStartLine()), max(1, $node->getEndLine()), max(0, $node->getStartFilePos()), $owner,
            metadata: ['factory_method' => $method, 'rule_type' => $type, 'execution_proven' => false]);
        $this->relations[] = new CatalogRelation($owner, $id, 'returned-framework-rule', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional');
        $this->owners[$id] = $this->owners[$owner] ?? '';
        if (in_array($method, CatalogRuleFactories::CONDITIONS, true)) {
            $this->frameworkRules($file, $node, $id, $depth + 1, $vars);
        }
        if ($method === 'anyof') {
            foreach ($node->getArgs() as $position => $arg) {
                if ($arg->name?->toString() === 'rules' || $arg->name === null && $position === 0) {
                    $start = count($this->relations);
                    $this->value($file, $arg->value, $id, $depth + 1, $vars);
                    for ($i = $start; $i < count($this->relations); $i++) {
                        $row = $this->relations[$i];
                        $this->relations[$i] = new CatalogRelation($row->from, $row->to, $row->kind, $row->line, $row->endLine, $row->resolution,
                            [...$row->metadata, 'framework_rule_contexts' => [['method' => 'anyof', 'branch' => 'rules'], ...($row->metadata['framework_rule_contexts'] ?? [])]]);
                    }
                }
            }
        }
        if ($method === 'enum') {
            foreach ($node->getArgs() as $position => $arg) {
                if ($arg->name?->toString() === 'type' || $arg->name === null && $position === 0) {
                    if ($arg->value instanceof Expr\ClassConstFetch && $arg->value->class instanceof Node\Name && $arg->value->name instanceof Node\Identifier && strtolower($arg->value->name->toString()) === 'class') {
                        $this->relations[] = new CatalogRelation($id, 'php:'.$file->resolvedName($arg->value->class), 'type-reference', max(1, $arg->getStartLine()), max(1, $arg->getEndLine()));
                    } else {
                        $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Enum rule type is not a source class selector.', max(1, $arg->getStartLine()), $id);
                    }
                }
            }
        }

        return $id;
    }

    /** @param array<string, mixed> $vars */
    private function frameworkRules(FileContext $file, Expr\StaticCall $node, string $owner, int $depth, array $vars): void
    {
        $method = strtolower($node->name instanceof Node\Identifier ? $node->name->toString() : '');
        $conditionOnly = in_array($method, CatalogRuleFactories::CONDITIONS, true);
        $names = $method === 'foreach' || $conditionOnly ? ['callback'] : ['condition', 'rules', 'defaultRules'];
        $args = [];
        foreach ($node->getArgs() as $position => $arg) {
            $key = $arg->name?->toString() ?? ($names[$position] ?? null);
            if ($arg->unpack || $key === null || ! in_array($key, $names, true) || isset($args[$key])) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditional rule factory arguments are unresolved; framework rules are not inferred.', max(1, $node->getStartLine()), $owner);

                return;
            }
            $args[$key] = $arg->value;
        }
        if (! isset($args[$names[0]]) || $method !== 'foreach' && ! $conditionOnly && ! isset($args['rules'])) {
            $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditional rule factory is missing required arguments.', max(1, $node->getStartLine()), $owner);

            return;
        }
        foreach ($args as $branch => $value) {
            $branchVars = $vars;
            $bindingDepth = $depth;
            while ($value instanceof Expr\Variable && is_string($value->name)) {
                if (! $this->budget($value, $owner, ++$bindingDepth)) {
                    continue 2;
                }
                $binding = $branchVars[$value->name] ?? null;
                if (! is_array($binding) || ! ($binding['node'] ?? null) instanceof Expr || ! is_array($binding['vars'] ?? null)) {
                    break;
                }
                $value = $binding['node'];
                $branchVars = $binding['vars'];
            }
            $start = count($this->relations);
            if (($branch === 'condition' || $conditionOnly) && $value instanceof Expr\CallLike && $value->isFirstClassCallable()) {
                $this->conditionCallable($file, $value, $owner, $branchVars, $depth + 1);
            } elseif ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
                $name = '(closure) '.$file->path.':'.$value->getStartFilePos();
                $target = CatalogElement::identity($file->path, 'closure', $name, max(0, $value->getStartFilePos()));
                $this->relations[] = new CatalogRelation($owner, $target, $branch === 'condition' || $conditionOnly ? 'returned-rule-condition' : 'returned-rule-expander', max(1, $value->getStartLine()), max(1, $value->getEndLine()), 'conditional');
                $this->owners[$target] = $this->owners[$owner] ?? '';
                if ($branch !== 'condition' && ! $conditionOnly) {
                    if ($value instanceof Expr\ArrowFunction) {
                        $this->value($file, $value->expr, $target, $depth + 1, $branchVars);
                    } else {
                        $this->returns($file, $value->stmts, $target, $depth + 1, $branchVars);
                    }
                }
            } elseif ($branch !== 'condition' && $method !== 'foreach' && ! $conditionOnly) {
                $this->value($file, $value, $owner, $depth + 1, $branchVars);
            } elseif ($method === 'foreach' || ! $value instanceof Expr\ConstFetch) {
                $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'Conditional rule callback or condition requires source inspection.', max(1, $value->getStartLine()), $owner);
            }
            for ($i = $start; $i < count($this->relations); $i++) {
                $row = $this->relations[$i];
                $this->relations[$i] = new CatalogRelation($row->from, $row->to, $row->kind, $row->line, $row->endLine, $row->resolution,
                    [...$row->metadata, 'framework_rule_contexts' => [['method' => $method, 'branch' => $branch], ...($row->metadata['framework_rule_contexts'] ?? [])]]);
            }
        }
    }

    /** @param array<string, mixed> $vars */
    private function conditionCallable(FileContext $file, Expr\CallLike $node, string $owner, array $vars, int $depth): void
    {
        $receiver = null;
        $method = '__invoke';
        $exact = true;
        $call = ['creator' => $this->owners[$owner] ?? '', 'form' => 'function', 'binding' => null];
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $resolved = $file->resolvedName($node->name);
            $namespaced = $node->name->getAttribute('namespacedName');
            $names = array_values(array_unique($namespaced instanceof Node\Name ? [$namespaced->toString(), $resolved] : [$resolved]));
            $receiver = '@function:'.json_encode($names, JSON_THROW_ON_ERROR);
        } elseif ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $method = $node->name->toString();
            $call['form'] = 'static';
            $binding = strtolower($node->class->toString());
            $call['binding'] = in_array($binding, ['self', 'static', 'parent'], true) ? $binding : null;
            $receiver = match ($call['binding']) {
                'self', 'static' => $this->owners[$owner] ?? null,
                'parent' => isset($this->owners[$owner]) ? '@parent:'.$this->owners[$owner] : null,
                default => $file->resolvedName($node->class),
            };
            $exact = $call['binding'] !== 'static';
        } elseif ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $receiver = $this->receiverType($file, $node->var, $owner, $vars, $depth + 1);
            $method = $node->name->toString();
            $call['form'] = 'instance';
            $exact = $node->var instanceof Expr\New_;
        }
        if ($receiver === null) {
            $this->diagnostics[] = new CatalogDiagnostic('validation_rules_dynamic', 'First-class rule condition has an unresolved source receiver.', max(1, $node->getStartLine()), $owner);

            return;
        }
        $this->relations[] = new CatalogRelation($owner, 'rule-callable:'.hash('xxh128', serialize([$receiver, $method, $exact, $call])), 'returned-rule-condition-callable', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional',
            ['callback_receiver' => $receiver, 'callback_method' => $method, 'callback_exact' => $exact, 'factory_call' => $call]);
    }

    /** @param array<string, mixed> $vars */
    private function standalone(FileContext $file, Node $node, string $owner, array $vars, int $depth): void
    {
        if (! $this->budget($node, $owner, $depth) || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike) {
            return;
        }
        $operation = null;
        $helpers = [];
        $receiverType = null;
        $rulesPosition = 1;
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Illuminate\\Support\\Facades\\Validator' && in_array(strtolower($node->name->toString()), ['make', 'validate'], true)) {
            $operation = strtolower($node->name->toString());
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && ! $node->isFirstClassCallable() && $file->resolvedName($node->name) === 'validator') {
            $operation = 'helper';
            $namespaced = $node->name->getAttribute('namespacedName');
            $helpers = $namespaced instanceof Node\Name ? [$namespaced->toString(), 'validator'] : ['validator'];
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable() && in_array(strtolower($node->name->toString()), ['make', 'validate', 'validatewithbag'], true)) {
            $receiverType = $this->receiverType($file, $node->var, $owner, $vars, $depth + 1);
            if ($receiverType !== null) {
                $method = strtolower($node->name->toString());
                $operation = $method === 'make' ? 'factory-make' : ($method === 'validatewithbag' ? 'request-validatewithbag' : 'method-validate');
                $rulesPosition = $method === 'validate' ? 0 : 1;
            }
        }
        if ($operation !== null && $node instanceof Expr\CallLike) {
            $rules = null;
            foreach ($node->getArgs() as $position => $arg) {
                if ($arg->name?->toString() === 'rules' || $arg->name === null && $position === $rulesPosition) {
                    $rules = $arg->unpack ? null : $arg->value;
                }
            }
            if ($rules !== null) {
                $name = '(validation) '.$file->path.':'.$node->getStartFilePos();
                $id = CatalogElement::identity($file->path, 'validation-site', $name, max(0, $node->getStartFilePos()));
                $this->elements[] = new CatalogElement($id, $name, 'validation-site', max(1, $node->getStartLine()), max(1, $node->getEndLine()), max(0, $node->getStartFilePos()), $owner,
                    metadata: ['operation' => $operation, 'helper_names' => $helpers, 'receiver_type' => $receiverType]);
                $this->relations[] = new CatalogRelation($owner, $id, 'validation-site-registration', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional');
                $this->sites[$node->getStartFilePos()] = $id;
                $this->owners[$id] = $this->owners[$owner] ?? '';
                $this->value($file, $rules, $id, $depth + 1, $vars);
                if ($operation === 'method-validate') {
                    foreach ($node->getArgs() as $position => $arg) {
                        if (($arg->name?->toString() === 'rules' || $arg->name === null && $position === 1) && ! $arg->unpack) {
                            $factoryName = $name.':factory';
                            $factoryId = CatalogElement::identity($file->path, 'validation-site', $factoryName, max(0, $node->getStartFilePos()));
                            $this->elements[] = new CatalogElement($factoryId, $factoryName, 'validation-site', max(1, $node->getStartLine()), max(1, $node->getEndLine()), max(0, $node->getStartFilePos()), $owner,
                                metadata: ['operation' => 'factory-validate', 'helper_names' => [], 'receiver_type' => $receiverType]);
                            $this->relations[] = new CatalogRelation($owner, $factoryId, 'validation-site-registration', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional');
                            $this->owners[$factoryId] = $this->owners[$owner] ?? '';
                            $this->value($file, $arg->value, $factoryId, $depth + 1, $vars);
                        }
                    }
                }
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->standalone($file, $child, $owner, $vars, $depth + 1);
                }
            }
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && in_array(strtolower($node->name->toString()), ['passes', 'fails', 'validate', 'validatewithbag', 'validated', 'safe'], true)) {
            $origin = $this->validatorOrigin($node->var, $owner, $vars, $depth + 1);
            if ($origin !== null) {
                $this->relations[] = new CatalogRelation($owner, $origin, 'validator-instance-candidate', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional',
                    ['validator_method' => strtolower($node->name->toString())]);
            }
        }
    }

    /** @param array<string, mixed> $vars */
    private function validatorOrigin(Node $node, string $owner, array $vars, int $depth): ?string
    {
        if (! $this->budget($node, $owner, $depth)) {
            return null;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $binding = $vars[$node->name] ?? null;
            if (is_array($binding) && ($binding['node'] ?? null) instanceof Expr && is_array($binding['vars'] ?? null)) {
                return $this->validatorOrigin($binding['node'], $owner, $binding['vars'], $depth + 1);
            }

            return null;
        }

        return $node instanceof Expr\CallLike ? ($this->sites[$node->getStartFilePos()] ?? null) : null;
    }

    /** @return list<string> */
    private function parameterTypes(FileContext $file, Node $type, string $owner, int $depth): array
    {
        if (! $this->budget($type, $owner, $depth)) {
            return [];
        }
        if ($type instanceof Node\Name) {
            $class = $this->owners[$owner] ?? '';

            return [match (strtolower($type->toString())) {
                'self', 'static' => $class,
                'parent' => '@parent:'.$class,
                default => $file->resolvedName($type),
            }];
        }
        if ($type instanceof Node\NullableType) {
            return $this->parameterTypes($file, $type->type, $owner, $depth + 1);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $types = [];
            foreach ($type->types as $part) {
                array_push($types, ...$this->parameterTypes($file, $part, $owner, $depth + 1));
            }

            return array_values(array_unique($types));
        }

        return [];
    }

    /** @param array<string, mixed> $vars */
    private function receiverType(FileContext $file, Expr $node, string $owner, array $vars, int $depth): ?string
    {
        if (! $this->budget($node, $owner, $depth)) {
            return null;
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            return $file->resolvedName($node->class);
        }
        if (($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) && $node->name instanceof Node\Identifier) {
            $type = $this->receiverType($file, $node->var, $owner, $vars, $depth + 1);

            return $type === null ? null : '@property:'.$type.'#'.$node->name->toString();
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            if ($node->name === 'this') {
                return $this->owners[$owner] ?? null;
            }
            $binding = $vars[$node->name] ?? null;
            if (is_array($binding) && is_string($binding['type'] ?? null)) {
                return $binding['type'];
            }
            if (is_array($binding) && ($binding['node'] ?? null) instanceof Expr && is_array($binding['vars'] ?? null)) {
                return $this->receiverType($file, $binding['node'], $owner, $binding['vars'], $depth + 1);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $vars */
    private function invalidate(Node $node, array &$vars, string $id, int $depth): void
    {
        if (! $this->budget($node, $id, $depth)) {
            $vars = [];

            return;
        }
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike) {
            return;
        }
        if ($node instanceof Expr\AssignRef && ($this->validatorOrigin($node->var, $id, $vars, $depth + 1) !== null
            || $this->validatorOrigin($node->expr, $id, $vars, $depth + 1) !== null)) {
            $vars = array_fill_keys(array_keys($vars), null);
            $this->diagnostics[] = new CatalogDiagnostic('validator_instance_mutated', 'A validator participates in a reference assignment; later rules require source inspection.', max(1, $node->getStartLine()), $id);
        }
        if ($node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef || $node instanceof Expr\Assign && $node->var instanceof Expr\ArrayDimFetch) {
            $this->invalidateTarget($node->var, $vars);
            if ($node instanceof Expr\AssignRef) {
                $this->invalidateTarget($node->expr, $vars);
            }
        }
        if ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
            $this->invalidateTarget($node->var, $vars);
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && ! $node->isFirstClassCallable()
            && (! $node->name instanceof Node\Identifier || ! in_array(strtolower($node->name->toString()), ['passes', 'fails', 'validate', 'validatewithbag', 'validated', 'safe'], true))
            && $this->validatorOrigin($node->var, $id, $vars, $depth + 1) !== null) {
            // Keep invalidated keys so enclosing branches invalidate their snapshots too.
            $vars = array_fill_keys(array_keys($vars), null);
            $this->diagnostics[] = new CatalogDiagnostic('validator_instance_mutated', 'A validator method may change its rules or instance; later validation requires source inspection.', max(1, $node->getStartLine()), $id);
        }
        if ($node instanceof Expr\CallLike && ! $node->isFirstClassCallable()) {
            foreach ($node->getArgs() as $arg) {
                if ($this->validatorOrigin($arg->value, $id, $vars, $depth + 1) !== null) {
                    $vars = array_fill_keys(array_keys($vars), null);
                    $this->diagnostics[] = new CatalogDiagnostic('validator_instance_mutated', 'A validator escapes to another call; its later rules require source inspection.', max(1, $node->getStartLine()), $id);
                }
                $this->invalidateTarget($arg->value, $vars);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->invalidate($child, $vars, $id, $depth + 1);
                }
            }
        }
    }

    /** @param array<string, mixed> $vars */
    private function invalidateTarget(Expr $target, array &$vars): void
    {
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }
        if ($target instanceof Expr\Variable && is_string($target->name)) {
            $vars[$target->name] = null;
        }
    }

    private function budget(Node $node, string $id, int $depth): bool
    {
        $structural = ++$this->visits > 10000 || $depth >= 32;
        $memory = ImpactExtractor::sourceLimit(0) !== null;
        if ($structural || $memory) {
            $reason = $memory ? 'memory' : 'structure';
            if (count(array_filter($this->diagnostics, fn ($row) => $row->code === 'catalog_limit' && $row->limitReason === $reason)) === 0) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', $memory
                    ? 'Validation extraction reached its memory budget.'
                    : 'Validation extraction reached its traversal or depth budget.', max(1, $node->getStartLine()), $id, $reason);
            }

            return false;
        }

        return true;
    }
}
