<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final class TestInvocationExtractor
{
    /** @var array<string, array<string, Stmt\ClassMethod>> */
    private array $helpers = [];

    /** @var array<string, true> */
    private array $activeHelpers = [];

    public const SYMBOLIC = "\x00id\x00";

    /** @return list<TestInvocation> */
    public function extract(FileContext $file): array
    {
        $this->helpers = $this->activeHelpers = [];
        $result = [];
        // Factory configuration can live in a provider; keep it beside file facts so
        // a warm cache does not reparse every provider to discover naming overrides.
        if (str_contains($file->contents, 'guessFactoryNamesUsing') || str_contains($file->contents, 'useNamespace')) {
            foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Expr\StaticCall::class) as $call) {
                if ($call->class instanceof Node\Name && $file->resolvedName($call->class) === 'Illuminate\Database\Eloquent\Factories\Factory'
                    && $call->name instanceof Node\Identifier && in_array($call->name->toString(), ['guessFactoryNamesUsing', 'useNamespace'], true)) {
                    $argument = $call->getArgs()[0]->value ?? null;
                    $result[] = new TestInvocation($file->path, $call->getStartLine(), 'factory-config', uri: $call->name->toString() === 'useNamespace' && $argument instanceof Node\Scalar\String_ ? $argument->value : null);
                }
            }
        }
        if (! RoleClassifier::isTestPath($file->path)) {
            return $result;
        }
        $variables = [];
        $this->walk($file->ast() ?? [], $file, null, false, $variables, $result);

        return $result;
    }

    /** @param list<Node> $nodes
     * @param  array<string, string|null>  $variables
     * @param  list<TestInvocation>  $result
     */
    private function walk(array $nodes, FileContext $file, ?string $context, bool $active, array &$variables, array &$result): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_) {
                $className = isset($node->namespacedName) ? $node->namespacedName->toString() : '';
                foreach ($node->getMethods() as $helper) {
                    $this->helpers[$className][strtolower($helper->name->toString())] = $helper;
                }
                $local = [];
                $this->walk($node->stmts, $file, isset($node->namespacedName) ? $node->namespacedName->toString() : null, false, $local, $result);

                continue;
            }
            if ($node instanceof Stmt\ClassMethod) {
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
            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Function_) {
                continue;
            }
            if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($file->resolvedName($node->name)), ['it', 'test', 'beforeeach', 'beforeall', 'describe'], true)) {
                foreach ($node->getArgs() as $arg) {
                    if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                        $local = [];
                        $this->walk($arg->value instanceof Expr\Closure ? $arg->value->stmts : [$arg->value->expr], $file, '@pest', true, $local, $result);
                    }
                }

                continue;
            }
            if ($active && $node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $variables[$node->var->name] = $this->value($node->expr, $variables);
            }
            if ($active && $node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'factory' && ! $node->isFirstClassCallable()) {
                $result[] = new TestInvocation($file->path, $node->getStartLine(), 'factory', $context, model: $node->class instanceof Node\Name ? $file->resolvedName($node->class) : null, reason: $node->class instanceof Node\Name ? null : 'Dynamic factory receiver.');
            }
            if ($active && ($node instanceof Expr\MethodCall || $node instanceof Expr\FuncCall) && ! $node->isFirstClassCallable()) {
                $method = null;
                if ($node instanceof Expr\MethodCall && ! $node->name instanceof Node\Identifier && $this->testReceiver($node->var)) {
                    $result[] = new TestInvocation($file->path, $node->getStartLine(), 'http', $context, reason: 'Dynamic test method; HTTP dispatch cannot be determined.');
                }
                if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && $this->testReceiver($node->var)) {
                    $method = strtolower($node->name->toString());
                } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
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
                if ($method !== null && in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'getjson', 'postjson', 'putjson', 'patchjson', 'deletejson', 'headjson', 'optionsjson', 'json', 'call'], true)) {
                    $args = $node->getArgs();
                    $generic = in_array($method, ['json', 'call'], true);
                    $verb = $generic ? $this->value($args[0]->value ?? null, $variables) : preg_replace('/json$/', '', $method);
                    $uriNode = $args[$generic ? 1 : 0]->value ?? null;
                    $uri = $this->value($uriNode, $variables);
                    $route = null;
                    $parameters = [];
                    if ($uriNode instanceof Expr\FuncCall && $uriNode->name instanceof Node\Name && $file->resolvedName($uriNode->name) === 'route') {
                        $routeArgs = $uriNode->getArgs();
                        $route = $this->value($routeArgs[0]->value ?? null, $variables);
                        $params = $routeArgs[1]->value ?? null;
                        if ($params instanceof Expr\Array_) {
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
                    $result[] = new TestInvocation($file->path, $node->getStartLine(), 'http', $context, $verb !== null ? strtoupper($verb) : null, $uri, $route, $parameters, reason: $verb === null || ($uri === null && $route === null) ? 'Dynamic HTTP method or address.' : null, dispatch: $method);
                }
            }
            $branch = $variables;
            $changedVariables = [];
            foreach ($node->getSubNodeNames() as $key) {
                if ($node instanceof Stmt\If_) {
                    $branch = $variables;
                }
                $child = $node->$key;
                $children = $child instanceof Node ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($v) => $v instanceof Node)) : []);
                $this->walk($children, $file, $context, $active, $branch, $result);
                foreach ($branch as $name => $value) {
                    if (($variables[$name] ?? null) !== $value) {
                        $changedVariables[$name] = true;
                    }
                }
            }
            // Assignments inside a branch cannot establish a value after that branch.
            if ($node instanceof Stmt\If_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Switch_) {
                foreach ($changedVariables as $key => $_) {
                    $variables[$key] = null;
                }
            } else {
                $variables = $branch;
            }
        }
    }

    private function testReceiver(Expr $node): bool
    {
        if ($node instanceof Expr\Variable) {
            return $node->name === 'this';
        }

        return $node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier
            && in_array(strtolower($node->name->toString()), ['actingas', 'be', 'withheaders', 'withheader', 'withservervariables', 'withsession', 'withoutmiddleware', 'withmiddleware', 'withoutexceptionhandling', 'withcookie', 'withcookies'], true)
            && $this->testReceiver($node->var);
    }

    /** @param array<string, string|null> $variables */
    private function value(?Node $node, array $variables): ?string
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
            return (string) $node->value;
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            return $variables[$node->name] ?? null;
        }
        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Node\Identifier && $node->name->toString() === 'id') {
            return self::SYMBOLIC;
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            $left = $this->value($node->left, $variables);
            $right = $this->value($node->right, $variables);

            return $left !== null && $right !== null ? $left.$right : null;
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            $result = '';
            foreach ($node->parts as $part) {
                $value = $part instanceof Node\InterpolatedStringPart ? $part->value : $this->value($part, $variables);
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
