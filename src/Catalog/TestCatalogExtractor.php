<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Test declarations and sanitized requests reuse the builder's existing invocation collection. */
final class TestCatalogExtractor
{
    public const PHPUNIT_HOOKS = ['Before', 'BeforeClass', 'After', 'AfterClass', 'PreCondition', 'PostCondition'];

    /** @param list<TestInvocation> $invocations
     * @param  list<int>  $sourceOffsets
     */
    public function extract(FileContext $file, CatalogFacts $php, array $invocations, array $sourceOffsets = []): CatalogFacts
    {
        if (! RoleClassifier::isTestPath($file->path)) {
            return new CatalogFacts($file->path);
        }
        $elements = $relations = $diagnostics = [];
        $byOffset = [];
        foreach ($php->elements as $element) {
            $byOffset[$element->offset] = $element;
        }
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassMethod::class) as $method) {
            $element = $byOffset[$method->getStartFilePos()] ?? null;
            if ($element === null) {
                continue;
            }
            $hooks = $providers = [];
            $test = str_starts_with(strtolower($method->name->toString()), 'test') || str_contains($method->getDocComment()?->getText() ?? '', '@test');
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $test = $test || $file->resolvedName($attribute->name) === 'PHPUnit\\Framework\\Attributes\\Test';
                    $name = $file->resolvedName($attribute->name);
                    if (in_array($name, ['PHPUnit\\Framework\\Attributes\\DataProvider', 'PHPUnit\\Framework\\Attributes\\DataProviderExternal'], true)) {
                        $provider = $this->provider($file, $attribute);
                        if ($provider === null || count($providers) >= 128) {
                            $diagnostics[] = new CatalogDiagnostic('test_analysis', 'PHPUnit data provider selector is dynamic, invalid or exceeds its source budget.', $attribute->getStartLine(), $element->id);
                        } else {
                            $providers[] = $provider;
                        }
                    }

                    foreach (self::PHPUNIT_HOOKS as $hook) {
                        if ($name !== 'PHPUnit\\Framework\\Attributes\\'.$hook) {
                            continue;
                        }
                        $priority = 0;
                        if (count($attribute->args) > 1 || isset($attribute->args[0]) && ($attribute->args[0]->unpack || $attribute->args[0]->byRef
                            || $attribute->args[0]->name !== null && $attribute->args[0]->name->toString() !== 'priority')) {
                            $diagnostics[] = new CatalogDiagnostic('test_analysis', 'PHPUnit hook attribute has incompatible arguments.', $attribute->getStartLine(), $element->id);

                            continue;
                        }
                        if (isset($attribute->args[0])) {
                            $value = $attribute->args[0]->value;
                            $priority = $value instanceof Node\Scalar\Int_ ? $value->value : ($value instanceof Expr\UnaryMinus && $value->expr instanceof Node\Scalar\Int_ ? -$value->expr->value : null);
                        }
                        if (in_array($hook, array_column($hooks, 'api'), true)) {
                            $diagnostics[] = new CatalogDiagnostic('test_analysis', 'Repeated PHPUnit hook attributes require inspection.', $attribute->getStartLine(), $element->id);

                            continue;
                        }
                        $hooks[] = ['api' => $hook, 'priority' => $priority];
                    }

                }
            }
            if ($test && $method->isPublic() && ! $method->isAbstract() || $hooks !== [] || $providers !== []) {
                $elements[] = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine, $element->offset, $element->parent, $element->roles,
                    [...$element->metadata, ...($test && $method->isPublic() && ! $method->isAbstract() ? ['test_declaration_candidate' => true] : []), 'phpunit_hooks' => $hooks, 'phpunit_providers' => $providers]);
            }
        }
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name || $call->isFirstClassCallable()) {
                continue;
            }
            $function = strtolower($file->resolvedName($call->name));
            if (! in_array($function, ['it', 'test', 'beforeeach', 'beforeall', 'aftereach', 'afterall', 'dataset'], true)) {
                continue;
            }
            $callback = null;
            foreach ($call->getArgs() as $arg) {
                if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                    $callback = $byOffset[$arg->value->getStartFilePos()] ?? null;
                }
            }
            $kind = $function === 'dataset' ? 'dataset' : (str_starts_with($function, 'before') || str_starts_with($function, 'after') ? 'test-hook' : 'pest-test');
            $label = $kind === 'test-hook' ? $function : CatalogSelector::literal($call->getArgs()[0]->value ?? null);
            if ($label === null || $label === '' || $kind !== 'dataset' && $callback === null) {
                $diagnostics[] = new CatalogDiagnostic('test_analysis', 'Test declaration is dynamic or has no source body.', max(1, $call->getStartLine()));

                continue;
            }
            $scope = $this->owner($php, $call->getStartLine(), $call->getEndLine(), $call->getStartFilePos());
            $id = CatalogElement::identity($file->path, $kind, $label, max(0, $call->getStartFilePos()));
            $elements[] = new CatalogElement($id, $label, $kind, max(1, $call->getStartLine()), max(1, $call->getEndLine()), max(0, $call->getStartFilePos()), metadata: ['test_api' => $function, 'test_executed' => false, 'registration_scope' => $scope]);
            if ($callback !== null && $kind !== 'dataset') {
                $relations[] = new CatalogRelation($id, $callback->id, 'test-body', max(1, $call->getStartLine()), max(1, $call->getEndLine()), 'conditional', ['execution_proven' => false, 'test_executed' => false]);
            }
        }
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || strtolower($call->name->toString()) !== 'with' || $call->isFirstClassCallable()) {
                continue;
            }
            $root = $call->var;
            while ($root instanceof Expr\MethodCall) {
                $root = $root->var;
            }
            if (! $root instanceof Expr\FuncCall) {
                continue;
            }
            foreach ($elements as $position => $element) {
                if ($element->kind !== 'pest-test' || $element->offset !== $root->getStartFilePos()) {
                    continue;
                }
                $datasets = $element->metadata['datasets'] ?? [];
                foreach ($call->getArgs() as $argument) {
                    $name = ! $argument->unpack ? CatalogSelector::literal($argument->value) : null;
                    if ($name !== null && $name !== '' && count($datasets) < 128) {
                        $datasets[] = $name;
                    } else {
                        $diagnostics[] = new CatalogDiagnostic('test_analysis', 'Inline/dynamic dataset values are omitted; dataset coverage is partial.', max(1, $call->getStartLine()), $element->id);
                    }
                }
                $elements[$position] = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine, $element->offset, $element->parent, $element->roles, [...$element->metadata, 'datasets' => array_values(array_unique($datasets))]);
            }
        }
        foreach ($invocations as $ordinal => $invocation) {
            if (! in_array($invocation->kind, ['http', 'factory'], true)) {
                continue;
            }
            $data = $invocation->toArray();
            if ($data['uri'] !== null) {
                $data['uri'] = explode('?', explode('#', $data['uri'], 2)[0], 2)[0];
                $data['uri'] = preg_replace('~\A(https?://)[^/]*@~i', '$1', $data['uri']);
                if (strlen($data['uri'] ?? '') > 500) {
                    $data['uri'] = null;
                    $data['reason'] = 'HTTP selector exceeds catalog budget.';
                }
            }
            foreach ($data['parameters'] as &$value) {
                if ($value !== null && strlen($value) > 256) {
                    $value = null;
                }
            }
            unset($value);
            $owner = $this->owner($php, $invocation->line, $invocation->line, $sourceOffsets[$ordinal] ?? null);
            $id = CatalogElement::identity($file->path, 'test-invocation', $invocation->kind.'#'.$ordinal, $invocation->line);
            $elements[] = new CatalogElement($id, $invocation->kind, 'test-invocation', $invocation->line, $invocation->line, 0, $owner, metadata: ['test_invocation' => $data, 'execution_proven' => false]);
        }

        $hooks = (new PestHookCatalogExtractor)->extract($file, $php);

        return new CatalogFacts($file->path, [...$elements, ...$hooks->elements], [...$relations, ...$hooks->relations], [...$diagnostics, ...$hooks->diagnostics]);
    }

    /** @return array{api: string, class: ?string, method: string, validate_argument_count: ?bool}|null */
    private function provider(FileContext $file, Node\Attribute $attribute): ?array
    {
        $external = str_ends_with($file->resolvedName($attribute->name), 'External');
        $names = $external ? ['className', 'methodName', 'validateArgumentCount'] : ['methodName', 'validateArgumentCount'];
        $args = [];
        $named = false;
        foreach ($attribute->args as $position => $argument) {
            $name = $argument->name?->toString() ?? ($names[$position] ?? '');
            if ($argument->unpack || $argument->byRef || ! in_array($name, $names, true) || isset($args[$name]) || $named && $argument->name === null) {
                return null;
            }
            $named = $named || $argument->name !== null;
            $args[$name] = $argument->value;
        }
        $method = CatalogSelector::literal($args['methodName'] ?? null);
        if ($method === null || strlen($method) > 500 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $method) !== 1) {
            return null;
        }
        $class = null;
        if ($external) {
            $value = $args['className'] ?? null;
            if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                && ! in_array(strtolower($value->class->toString()), ['self', 'static', 'parent'], true)) {
                $class = $file->resolvedName($value->class);
            } else {
                $class = CatalogSelector::literal($value);
            }
            if ($class === null || strlen($class) > 1000 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', ltrim($class, '\\')) !== 1) {
                return null;
            }
            $class = ltrim($class, '\\');
        }
        $flag = $args['validateArgumentCount'] ?? null;
        $validate = $flag === null ? true : ($flag instanceof Expr\ConstFetch && in_array(strtolower($flag->name->toString()), ['true', 'false'], true) ? strtolower($flag->name->toString()) === 'true' : null);

        return ['api' => $external ? 'DataProviderExternal' : 'DataProvider', 'class' => $class, 'method' => $method, 'validate_argument_count' => $validate];
    }

    private function owner(CatalogFacts $php, int $line, int $endLine, ?int $offset = null): ?string
    {
        $owner = null;
        $size = PHP_INT_MAX;
        foreach ($php->elements as $element) {
            if (! in_array($element->kind, ['method', 'closure'], true) || $element->line > $line || $element->endLine < $endLine
                || $offset !== null && ($element->offset > $offset || ($element->metadata['end_offset'] ?? 0) < $offset)) {
                continue;
            }
            $span = ($element->metadata['end_offset'] ?? 0) - $element->offset;
            if ($span < $size) {
                $owner = $element->id;
                $size = $span;
            }
        }

        return $owner;
    }
}
