<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

/** Identifies source calls without resolving or invoking a response factory. */
final class CatalogSerializationInvocation
{
    /** @return array{value: Node|null, valid: bool, functions: list<string>, types: list<string>, form: string, receiver?: string, method?: string}|null */
    public static function extract(FileContext $file, Node $node, bool $returned = false, ?string $receiver = null): ?array
    {
        if (Node::is($node, 'statement.return') && $node->expr !== null || $returned && Node::is($node, 'expression')) {
            return ['value' => Node::is($node, 'statement.return') ? $node->expr : $node, 'valid' => true, 'functions' => [],
                'types' => ['Illuminate\\Routing\\Router', 'Illuminate\\Http\\Response', 'Illuminate\\Http\\JsonResponse'], 'form' => 'http_return'];
        }
        $functions = $types = $parameters = [];
        $form = 'http_json';
        if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && ! $node->isFirstClassCallable()) {
            $functions = self::functions($file, $node->name);
            if (in_array('json_encode', array_map('strtolower', $functions), true)) {
                $parameters = ['value', 'flags', 'depth'];
                $form = 'json_encode';
            } elseif (in_array('response', array_map('strtolower', $functions), true)) {
                $parameters = ['content', 'status', 'headers'];
                $types = ['Illuminate\\Http\\Response', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Contracts\\Routing\\ResponseFactory'];
            }
        } elseif (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()
            && Node::is($node->var, 'expression.func-call') && Node::is($node->var->name, 'name') && ! $node->var->isFirstClassCallable() && $node->var->args === []) {
            $functions = self::functions($file, $node->var->name);
            if (in_array('response', array_map('strtolower', $functions), true)) {
                $method = strtolower($node->name->toString());
                $parameters = $method === 'json' ? ['data', 'status', 'headers', 'options'] : ($method === 'make' ? ['content', 'status', 'headers'] : []);
                $types = [$method === 'json' ? 'Illuminate\\Http\\JsonResponse' : 'Illuminate\\Http\\Response', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Contracts\\Routing\\ResponseFactory'];
            }
        } elseif (Node::is($node, 'expression.static-call') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Illuminate\\Support\\Facades\\Response') {
            $method = strtolower($node->name->toString());
            $parameters = $method === 'json' ? ['data', 'status', 'headers', 'options'] : ($method === 'make' ? ['content', 'status', 'headers'] : []);
            $types = [$method === 'json' ? 'Illuminate\\Http\\JsonResponse' : 'Illuminate\\Http\\Response', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Support\\Facades\\Response', 'Illuminate\\Contracts\\Routing\\ResponseFactory'];
        } elseif (Node::is($node, 'expression.new') && Node::is($node->class, 'name')) {
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
        if ($parameters === [] && $receiver !== null && (Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call'))
            && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()) {
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
        if ($parameters === [] || ! Node::is($node, 'expression.call-like')) {
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
                $valid = $valid && Node::is($arg->value, 'expression.const-fetch') && strtolower($arg->value->name->toString()) === 'false';
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
    private static function functions(FileContext $file, Node $name): array
    {
        $namespace = $name->getAttribute('namespacedName');

        return array_values(array_unique(Node::is($namespace, 'name') ? [$namespace->toString(), $file->resolvedName($name)] : [$file->resolvedName($name)]));
    }
}
