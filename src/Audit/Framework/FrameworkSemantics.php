<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use PhpParser\Node;

/** Shared, bounded descriptions of framework calls. It never creates findings or executes application code. */
final readonly class FrameworkSemantics
{
    private GateSemantics $gate;

    private ResourceSemantics $resources;

    private InertiaSemantics $inertia;

    private FortifySemantics $fortify;

    public function __construct(private SourceIndex $sources, private FrameworkContext $context = new FrameworkContext)
    {
        $this->gate = new GateSemantics($sources, $context);
        $this->resources = new ResourceSemantics($sources);
        $this->inertia = new InertiaSemantics($sources, $context);
        $this->fortify = new FortifySemantics($sources, $context);
    }

    public function context(): FrameworkContext
    {
        return $this->context;
    }

    public function entrypoint(string $class, string $method): FrameworkCallResult
    {
        return $this->fortify->entrypoint($class, $method);
    }

    /**
     * @param  array<int|string, FrameworkValue|null>  $arguments
     */
    public function describe(
        ?FrameworkValue $receiver,
        ?string $function,
        ?string $method,
        array $arguments,
        SourceClass $source,
        Node $call,
    ): FrameworkCallResult {
        if ($function !== null) {
            return $this->function($function, $arguments, $source, $call);
        }

        $type = $receiver?->type;
        $method = strtolower((string) $method);
        if ($type === null || $method === '') {
            return FrameworkCallResult::unhandled();
        }

        foreach ([$this->gate, $this->resources, $this->inertia] as $semantics) {
            $result = $semantics->describe($receiver, $method, $arguments);
            if ($result->handled) {
                return $semantics === $this->inertia && $method === 'render'
                    ? $this->materialize($result, [...$arguments, ...$this->context->inertiaShares])
                    : $result;
            }
        }

        if ($this->sources->isA($type, 'Illuminate\Http\Request')) {
            if (in_array($method, ['input', 'query', 'cookie', 'hascookie', 'boolean', 'integer', 'float', 'date', 'enum', 'enums', 'string', 'collect', 'validated', 'all', 'only', 'except', 'route'], true)) {
                return FrameworkCallResult::value(FrameworkValue::scalar());
            }
            if ($method === 'safe') {
                return FrameworkCallResult::value(FrameworkValue::type('Illuminate\Support\ValidatedInput'));
            }
            if ($method === 'session') {
                return FrameworkCallResult::value(FrameworkValue::type('Illuminate\Session\Store'));
            }
            if ($method === 'user') {
                return FrameworkCallResult::value(FrameworkValue::type('@user'));
            }
        }

        if ($type === 'Illuminate\Session\Store' || $type === 'Illuminate\Support\Facades\Session') {
            if (in_array($method, ['get', 'has', 'exists', 'missing', 'all', 'only', 'except', 'previousurl'], true)) {
                return FrameworkCallResult::value(FrameworkValue::scalar());
            }
            if (in_array($method, ['put', 'forget', 'flash', 'now', 'reflash', 'keep', 'pull', 'increment', 'decrement', 'flush', 'invalidate', 'regenerate', 'migrate'], true)) {
                return FrameworkCallResult::effect('write', $type.'::'.$method.'()', FrameworkValue::scalar());
            }
        }

        if ($type === 'Illuminate\Support\ValidatedInput' && in_array($method, ['all', 'only', 'except', 'merge', 'collect'], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }

        if ($type === 'Laravel\Fortify\Features' && in_array($method, [
            'enabled',
            'optionenabled',
            'hasprofilefeatures',
            'canupdateprofileinformation',
            'hassecurityfeatures',
            'canupdatepasswords',
            'canmanagetwofactorauthentication',
            'canmanagepasskeys',
            'registration',
            'resetpasswords',
            'emailverification',
            'updateprofileinformation',
            'updatepasswords',
            'twofactorauthentication',
            'passkeys',
        ], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }

        if ($type === '@response' && in_array($method, ['json', 'make'], true)) {
            return $this->materialize(
                FrameworkCallResult::value(FrameworkValue::type('@response')),
                $arguments,
            );
        }
        if ($type === '@response' && $method === 'nocontent') {
            return FrameworkCallResult::value(FrameworkValue::type('@response'));
        }

        if ($type === '@collection' && in_array($method, ['count', 'isempty', 'isnotempty', 'toarray', 'all', 'values', 'keys', 'pluck', 'first'], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }

        return FrameworkCallResult::unhandled();
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function function(string $function, array $arguments, SourceClass $source, Node $call): FrameworkCallResult
    {
        $function = strtolower(ltrim($function, '\\'));
        if ($function === 'response') {
            return $this->materialize(
                FrameworkCallResult::value(FrameworkValue::type('@response')),
                $arguments,
            );
        }
        if ($function === 'view') {
            return FrameworkCallResult::value(FrameworkValue::type('@response'));
        }
        if ($function === 'route') {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if (in_array($function, ['to_route', 'redirect'], true)) {
            return FrameworkCallResult::value(FrameworkValue::type('@response'));
        }
        if ($function === 'session') {
            if (($arguments[0] ?? null)?->type === '@array') {
                return FrameworkCallResult::effect('write', 'session(array)', FrameworkValue::type('Illuminate\Session\Store'));
            }

            return FrameworkCallResult::value(FrameworkValue::type('Illuminate\Session\Store'));
        }
        if ($function === 'inertia') {
            return $this->materialize(
                $this->inertia->describe(FrameworkValue::type('@inertia'), 'render', $arguments),
                [...$arguments, ...$this->context->inertiaShares],
            );
        }

        return FrameworkCallResult::unhandled();
    }

    /** @param array<int|string, FrameworkValue|null> $values */
    private function materialize(FrameworkCallResult $base, array $values): FrameworkCallResult
    {
        $targets = $base->targets;
        $callbacks = $base->callbacks;
        $incomplete = $base->incomplete;
        $walk = function (?FrameworkValue $value) use (&$targets, &$callbacks, &$incomplete, &$walk): void {
            if ($value === null) {
                return;
            }
            if ($value->resourceClass !== null) {
                $resource = $this->resources->describe($value, 'resolve', []);
                array_push($targets, ...$resource->targets);
                array_push($callbacks, ...$resource->callbacks);
                $incomplete ??= $resource->incomplete;
            } elseif ($value->type !== null && $this->sources->inScope($value->type)) {
                foreach (['toArray', 'toResponse'] as $method) {
                    if ($this->sources->method($value->type, $method) !== null) {
                        $targets[] = ['class' => $value->type, 'method' => $method];
                        break;
                    }
                }
            }
            foreach ($value->items as $item) {
                $walk($item);
            }
        };
        foreach ($values as $value) {
            $walk($value);
        }

        return new FrameworkCallResult(true, $base->value, $targets, $callbacks, $base->effects, $incomplete);
    }
}
