<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Identifies source calls without resolving or invoking a response factory. */
final class CatalogSerializationInvocation
{
    /** @return array{value: Expr|null, valid: bool, functions: list<string>, types: list<string>, form: string, receiver?: string, method?: string}|null */
    public static function extract(FileContext $file, Node $node, bool $returned = false, ?string $receiver = null): ?array
    {
        if ($node instanceof Stmt\Return_ && $node->expr !== null || $returned && $node instanceof Expr) {
            return ['value' => $node instanceof Stmt\Return_ ? $node->expr : $node, 'valid' => true, 'functions' => [],
                'types' => ['Illuminate\\Routing\\Router', 'Illuminate\\Http\\Response', 'Illuminate\\Http\\JsonResponse'], 'form' => 'http_return'];
        }
        $functions = $types = $parameters = [];
        $form = 'http_json';
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && ! $node->isFirstClassCallable()) {
            $functions = self::functions($file, $node->name);
            if (in_array('json_encode', array_map('strtolower', $functions), true)) {
                $parameters = ['value', 'flags', 'depth'];
                $form = 'json_encode';
            } elseif (in_array('response', array_map('strtolower', $functions), true)) {
                $parameters = ['content', 'status', 'headers'];
                $types = ['Illuminate\\Http\\Response', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Contracts\\Routing\\ResponseFactory'];
            }
        } elseif ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $node->var instanceof Expr\FuncCall && $node->var->name instanceof Node\Name && ! $node->var->isFirstClassCallable() && $node->var->args === []) {
            $functions = self::functions($file, $node->var->name);
            if (in_array('response', array_map('strtolower', $functions), true)) {
                $method = strtolower($node->name->toString());
                $parameters = $method === 'json' ? ['data', 'status', 'headers', 'options'] : ($method === 'make' ? ['content', 'status', 'headers'] : []);
                $types = [$method === 'json' ? 'Illuminate\\Http\\JsonResponse' : 'Illuminate\\Http\\Response', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Contracts\\Routing\\ResponseFactory'];
            }
        } elseif ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Illuminate\\Support\\Facades\\Response') {
            $method = strtolower($node->name->toString());
            $parameters = $method === 'json' ? ['data', 'status', 'headers', 'options'] : ($method === 'make' ? ['content', 'status', 'headers'] : []);
            $types = [$method === 'json' ? 'Illuminate\\Http\\JsonResponse' : 'Illuminate\\Http\\Response', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Support\\Facades\\Response', 'Illuminate\\Contracts\\Routing\\ResponseFactory'];
        } elseif ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            $type = $file->resolvedName($node->class);
            if ($type === 'Illuminate\\Http\\JsonResponse') {
                $parameters = ['data', 'status', 'headers', 'options', 'json'];
                $types = [$type];
            } elseif ($type === 'Illuminate\\Http\\Response') {
                $parameters = ['content', 'status', 'headers'];
                $types = [$type];
            }
        }
        $instance = false;
        if ($parameters === [] && $receiver !== null && ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $method = strtolower($node->name->toString());
            $parameters = match ($method) {
                'json' => ['data', 'status', 'headers', 'options'],
                'make' => ['content', 'status', 'headers'],
                'setdata' => ['data'],
                'setcontent' => ['content'],
                default => [],
            };
            $types = [in_array($method, ['json', 'setdata'], true) ? 'Illuminate\\Http\\JsonResponse' : 'Illuminate\\Http\\Response'];
            $form = 'http_instance';
            $instance = true;
            if (str_starts_with($receiver, '@response-factory:')) {
                $decoded = json_decode(substr($receiver, 18), true, 8);
                $functions = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
            }
        }
        if ($parameters === [] || ! $node instanceof Expr\CallLike) {
            return null;
        }
        $valid = count($node->getArgs()) >= 1 && count($node->getArgs()) <= count($parameters);
        $seen = [];
        $value = null;
        foreach ($node->getArgs() as $position => $arg) {
            $parameter = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
            $valid = $valid && ! $arg->unpack && in_array($parameter, $parameters, true) && ! isset($seen[$parameter]);
            $seen[$parameter] = true;
            if ($parameter === $parameters[0]) {
                $value = $arg->value;
            }
            if ($parameter === 'json') {
                // Pre-encoded JSON bypasses JsonResponse::setData.
                $valid = $valid && $arg->value instanceof Expr\ConstFetch && strtolower($arg->value->name->toString()) === 'false';
            }
        }

        $result = ['value' => $value, 'valid' => $valid, 'functions' => $functions, 'types' => $types, 'form' => $form];
        if ($instance && $receiver !== null) {
            $result['receiver'] = $receiver;
            $result['method'] = $method;
        }

        return $result;
    }

    /** @return list<string> */
    private static function functions(FileContext $file, Node\Name $name): array
    {
        $namespace = $name->getAttribute('namespacedName');

        return array_values(array_unique($namespace instanceof Node\Name ? [$namespace->toString(), $file->resolvedName($name)] : [$file->resolvedName($name)]));
    }
}
