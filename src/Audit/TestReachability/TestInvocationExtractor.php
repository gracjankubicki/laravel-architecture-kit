<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

final class TestInvocationExtractor
{
    /** @var array<string, array<string, Node>> */
    private array $helpers = [];

    /** @var array<string, true> */
    private array $activeHelpers = [];

    /** @var list<int> Exact positions for the most recent extraction; not serialized. */
    private array $sourceOffsets = [];

    /** @return list<int> */
    public function sourceOffsets(): array
    {
        return $this->sourceOffsets;
    }

    public const SYMBOLIC = "\x00id\x00";

    /** @return list<TestInvocation> */
    public function extract(FileContext $file): array
    {
        $this->helpers = $this->activeHelpers = [];
        $this->sourceOffsets = [];
        $result = [];
        // Factory configuration can live in a provider; keep it beside file facts so
        // a warm cache does not reparse every provider to discover naming overrides.
        if (str_contains($file->contents, 'guessFactoryNamesUsing') || str_contains($file->contents, 'useNamespace')) {
            foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'expression.static-call') as $call) {
                if (Node::is($call->class, 'name') && $file->resolvedName($call->class) === 'Illuminate\Database\Eloquent\Factories\Factory'
                    && Node::is($call->name, 'identifier') && in_array($call->name->toString(), ['guessFactoryNamesUsing', 'useNamespace'], true)) {
                    $argument = $call->getArgs()[0]->value ?? null;
                    $this->sourceOffsets[] = max(0, $call->getStartFilePos());
                    $result[] = new TestInvocation($file->path, $call->getStartLine(), 'factory-config', uri: $call->name->toString() === 'useNamespace' && Node::is($argument, 'scalar.string') ? $argument->value : null);
                }
            }
        }
        if (! RoleClassifier::isTestPath($file->path)) {
            return $result;
        }
        $variables = [];
        $this->walk($file->syntax() ?? [], $file, null, false, $variables, $result);

        return $result;
    }

    /** @param list<Node> $nodes
     * @param  array<string, string|null>  $variables
     * @param  list<TestInvocation>  $result
     */
    private function walk(array $nodes, FileContext $file, ?string $context, bool $active, array &$variables, array &$result): void
    {
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.class')) {
                $className = isset($node->namespacedName) ? $node->namespacedName->toString() : '';
                foreach ($node->getMethods() as $helper) {
                    $this->helpers[$className][strtolower($helper->name->toString())] = $helper;
                }
                $local = [];
                $this->walk($node->stmts, $file, isset($node->namespacedName) ? $node->namespacedName->toString() : null, false, $local, $result);

                continue;
            }
            if (Node::is($node, 'statement.class-method')) {
                $test = str_starts_with(strtolower($node->name->toString()), 'test') || in_array(strtolower($node->name->toString()), ['setup', 'setupbeforeclass'], true)
                    || str_contains($node->getDocComment()?->getText() ?? '', '@test');
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        $test = $test || $file->resolvedName($attribute->name) === 'PHPUnit\\Framework\\Attributes\\Test';
                    }
                }
                if ($test) {
                    $local = [];
                    $this->walk($node->stmts ?? [], $file, $context, true, $local, $result);
                }

                continue;
            }
            if (Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function') || Node::is($node, 'statement.function')) {
                continue;
            }
            if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && in_array(strtolower($file->resolvedName($node->name)), ['it', 'test', 'beforeeach', 'beforeall', 'describe'], true)) {
                foreach ($node->getArgs() as $arg) {
                    if (Node::is($arg->value, 'expression.closure') || Node::is($arg->value, 'expression.arrow-function')) {
                        $local = [];
                        $this->walk(Node::is($arg->value, 'expression.closure') ? $arg->value->stmts : [$arg->value->expr], $file, '@pest', true, $local, $result);
                    }
                }

                continue;
            }
            if ($active && Node::is($node, 'expression.assign') && Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
                $variables[$node->var->name] = $this->value($node->expr, $variables);
            }
            if ($active && Node::is($node, 'expression.static-call') && Node::is($node->class, 'name')
                && $file->resolvedName($node->class) === 'Illuminate\\Support\\Facades\\Artisan'
                && Node::is($node->name, 'identifier') && strtolower($node->name->toString()) === 'call' && ! $node->isFirstClassCallable()) {
                $command = $this->value($node->getArgs()[0]->value ?? null, $variables);
                $this->sourceOffsets[] = max(0, $node->getStartFilePos());
                $result[] = new TestInvocation($file->path, $node->getStartLine(), 'artisan', $context,
                    reason: $command === null ? 'Dynamic Artisan command selector.' : null, dispatch: 'call', command: $command);
            }
            if ($active && Node::is($node, 'expression.static-call') && Node::is($node->name, 'identifier') && strtolower($node->name->toString()) === 'factory' && ! $node->isFirstClassCallable()) {
                $this->sourceOffsets[] = max(0, $node->getStartFilePos());
                $result[] = new TestInvocation($file->path, $node->getStartLine(), 'factory', $context, model: Node::is($node->class, 'name') ? $file->resolvedName($node->class) : null, reason: Node::is($node->class, 'name') ? null : 'Dynamic factory receiver.');
            }
            if ($active && (Node::is($node, 'expression.method-call') || Node::is($node, 'expression.func-call')) && ! $node->isFirstClassCallable()) {
                $method = null;
                if (Node::is($node, 'expression.method-call') && ! Node::is($node->name, 'identifier') && $this->testReceiver($node->var)) {
                    $this->sourceOffsets[] = max(0, $node->getStartFilePos());
                    $result[] = new TestInvocation($file->path, $node->getStartLine(), 'http', $context, reason: 'Dynamic test method; HTTP dispatch cannot be determined.');
                }
                if (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier') && $this->testReceiver($node->var)) {
                    $method = strtolower($node->name->toString());
                } elseif (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name')) {
                    $name = strtolower($file->resolvedName($node->name));
                    if (str_starts_with($name, 'pest\\laravel\\')) {
                        $method = substr($name, strlen('pest\\laravel\\'));
                    }
                }
                if ($method !== null && $context !== null && isset($this->helpers[$context][$method])) {
                    $key = $context.'::'.$method;
                    if (! isset($this->activeHelpers[$key]) && count($this->activeHelpers) < 12) {
                        $this->activeHelpers[$key] = true;
                        $local = [];
                        $helper = $this->helpers[$context][$method];
                        foreach ($helper->params as $i => $param) {
                            if (is_string($param->var->name)) {
                                $local[$param->var->name] = $this->value($node->getArgs()[$i]->value ?? null, $variables);
                            }
                        }
                        $this->walk($helper->stmts ?? [], $file, $context, true, $local, $result);
                        unset($this->activeHelpers[$key]);
                    }
                    $method = null;
                }
                if ($method === 'artisan') {
                    $command = $this->value($node->getArgs()[0]->value ?? null, $variables);
                    $this->sourceOffsets[] = max(0, $node->getStartFilePos());
                    $result[] = new TestInvocation($file->path, $node->getStartLine(), 'artisan', $context,
                        reason: $command === null ? 'Dynamic Artisan command selector.' : null, dispatch: 'artisan', command: $command);
                }
                if ($method !== null && in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'getjson', 'postjson', 'putjson', 'patchjson', 'deletejson', 'headjson', 'optionsjson', 'json', 'call'], true)) {
                    $args = $node->getArgs();
                    $generic = in_array($method, ['json', 'call'], true);
                    $verb = $generic ? $this->value($args[0]->value ?? null, $variables) : preg_replace('/json$/', '', $method);
                    $uriNode = $args[$generic ? 1 : 0]->value ?? null;
                    $uri = $this->value($uriNode, $variables);
                    $route = null;
                    $parameters = [];
                    if (Node::is($uriNode, 'expression.func-call') && Node::is($uriNode->name, 'name') && $file->resolvedName($uriNode->name) === 'route') {
                        $routeArgs = $uriNode->getArgs();
                        $route = $this->value($routeArgs[0]->value ?? null, $variables);
                        $params = $routeArgs[1]->value ?? null;
                        if (Node::is($params, 'expression.array')) {
                            foreach ($params->items as $i => $item) {
                                if ($item !== null) {
                                    $key = $this->value($item->key, $variables) ?? $i;
                                    $parameters[$key] = $this->value($item->value, $variables);
                                }
                            }
                        } elseif ($params !== null) {
                            $parameters[] = $this->value($params, $variables);
                        }
                    }
                    $this->sourceOffsets[] = max(0, $node->getStartFilePos());
                    $result[] = new TestInvocation($file->path, $node->getStartLine(), 'http', $context, $verb !== null ? strtoupper($verb) : null, $uri, $route, $parameters, reason: $verb === null || ($uri === null && $route === null) ? 'Dynamic HTTP method or address.' : null, dispatch: $method);
                }
            }
            $branch = $variables;
            $changedVariables = [];
            foreach ($node->getSubNodeNames() as $key) {
                if (Node::is($node, 'statement.if')) {
                    $branch = $variables;
                }
                $child = $node->$key;
                $children = Node::is($child, 'node') ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($v) => Node::is($v, 'node'))) : []);
                $this->walk($children, $file, $context, $active, $branch, $result);
                foreach ($branch as $name => $value) {
                    if (($variables[$name] ?? null) !== $value) {
                        $changedVariables[$name] = true;
                    }
                }
            }
            // Assignments inside a branch cannot establish a value after that branch.
            if (Node::is($node, 'statement.if') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while') || Node::is($node, 'statement.switch')) {
                foreach ($changedVariables as $key => $_) {
                    $variables[$key] = null;
                }
            } else {
                $variables = $branch;
            }
        }
    }

    private function testReceiver(Node $node): bool
    {
        if (Node::is($node, 'expression.variable')) {
            return $node->name === 'this';
        }

        return Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier')
            && in_array(strtolower($node->name->toString()), ['actingas', 'be', 'withheaders', 'withheader', 'withservervariables', 'withsession', 'withoutmiddleware', 'withmiddleware', 'withoutexceptionhandling', 'withcookie', 'withcookies'], true)
            && $this->testReceiver($node->var);
    }

    /** @param array<string, string|null> $variables */
    private function value(?Node $node, array $variables): ?string
    {
        if (Node::is($node, 'scalar.string') || Node::is($node, 'scalar.int')) {
            return (string) $node->value;
        }
        if (Node::is($node, 'expression.variable') && is_string($node->name)) {
            return $variables[$node->name] ?? null;
        }
        if (Node::is($node, 'expression.property-fetch') && Node::is($node->name, 'identifier') && $node->name->toString() === 'id') {
            return self::SYMBOLIC;
        }
        if (Node::is($node, 'expression.cast.int')) {
            $value = $this->value($node->expr, $variables);

            return $value !== null && ! str_contains($value, self::SYMBOLIC) ? (string) (int) $value : self::SYMBOLIC;
        }
        if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name')
            && ! $node->isFirstClassCallable()
            && $node->name->getAttribute('namespacedName') === null
            && in_array(strtolower(($node->name->getAttribute('resolvedName') ?? $node->name)->toString()), ['rawurlencode', 'urlencode'], true)
            && count($node->getArgs()) === 1 && ! $node->getArgs()[0]->unpack) {
            $value = $this->value($node->getArgs()[0]->value, $variables);

            return $value !== null && ! str_contains($value, self::SYMBOLIC)
                ? (strtolower(($node->name->getAttribute('resolvedName') ?? $node->name)->toString()) === 'urlencode' ? urlencode($value) : rawurlencode($value)) : self::SYMBOLIC;
        }
        if (Node::is($node, 'expression.binary-op.concat')) {
            $left = $this->value($node->left, $variables);
            $right = $this->value($node->right, $variables);

            return $left !== null && $right !== null ? $left.$right : null;
        }
        if (Node::is($node, 'scalar.interpolated-string')) {
            $result = '';
            foreach ($node->parts as $part) {
                $value = Node::is($part, 'interpolated-string-part') ? $part->value : $this->value($part, $variables);
                if ($value === null) {
                    return null;
                }
                $result .= $value;
            }

            return $result;
        }

        return null;
    }
}
