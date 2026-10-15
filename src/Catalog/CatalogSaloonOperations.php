<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Argument names and types only; request bodies and retry payloads are never retained. */
final class CatalogSaloonOperations
{
    public static function poolMemberArray(Node $array): bool
    {
        $items = ImpactExtractor::callableArrayItems($array);

        return count($array->items) <= 128 && ! (Node::is(($items[1] ?? null)?->value, 'scalar.string'));
    }

    public const POOL_SETTERS = ['setrequests' => ['requests', null], 'withresponsehandler' => ['callable', 'response'],
        'withexceptionhandler' => ['callable', 'exception'], 'setconcurrency' => ['concurrency', 'concurrency']];

    /** A source shape candidate; cross-file ancestry is checked during composition. */
    public static function poolConstructor(FileContext $file, Node $node): bool
    {
        if (! Node::is($node->class, 'name')) {
            return false;
        }
        if (strcasecmp($file->resolvedName($node->class), 'Saloon\Http\Pool') === 0) {
            return true;
        }

        return count($node->args) <= 5 && array_filter($node->getArgs(), fn ($arg, $position) => ($arg->name?->toString() ?? ($position === 1 ? 'requests' : '')) === 'requests'
                && (Node::is($arg->value, 'expression.array') || Node::is($arg->value, 'expression.variable') || Node::is($arg->value, 'expression.closure') || Node::is($arg->value, 'expression.arrow-function')), ARRAY_FILTER_USE_BOTH) !== [];
    }

    public const PARAMETERS = [
        'send' => ['request', 'mockClient', 'handleRetry'],
        'sendasync' => ['request', 'mockClient'],
        'sendandretry' => ['request', 'tries', 'interval', 'handleRetry', 'throw', 'mockClient', 'useExponentialBackoff'],
        'creatependingrequest' => ['request', 'mockClient'],
    ];

    /** @param list<Node> $arguments
     * @return array{valid: bool, request: ?int}
     */
    public static function arguments(string $method, array $arguments): array
    {
        $parameters = self::PARAMETERS[$method];
        $seen = [];
        $request = null;
        $named = false;
        $valid = count($arguments) <= count($parameters);
        foreach ($arguments as $position => $argument) {
            $name = $argument->name?->toString() ?? ($parameters[$position] ?? 'unknown');
            $valid = $valid && ! $argument->unpack && ! $argument->byRef && in_array($name, $parameters, true)
                && ! isset($seen[$name]) && (! $named || $argument->name !== null);
            $named = $named || $argument->name !== null;
            $seen[$name] = true;
            if ($name === 'request') {
                $request = $position;
            }
        }
        foreach ($method === 'sendandretry' ? ['request', 'tries', 'interval'] : ['request'] as $required) {
            $valid = $valid && isset($seen[$required]);
        }

        return ['valid' => $valid, 'request' => $request];
    }

    /** @param list<Node> $arguments */
    public static function requestSideArguments(string $method, array $arguments): bool
    {
        if (! in_array($method, ['send', 'sendasync', 'creatependingrequest'], true) || count($arguments) > 1) {
            return false;
        }
        foreach ($arguments as $argument) {
            if ($argument->unpack || $argument->byRef || $argument->name !== null && $argument->name->toString() !== 'mockClient') {
                return false;
            }
            if (Node::is($argument->value, 'scalar') || Node::is($argument->value, 'expression.array')
                || Node::is($argument->value, 'expression.const-fetch') && strtolower($argument->value->name->toString()) !== 'null') {
                return false;
            }
        }

        return true;
    }
}
