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

    public function isKnownExternal(string $type): bool
    {
        return $this->isDateType($type)
            || str_starts_with($type, 'Illuminate\\')
            || in_array($type, ['RuntimeException', 'InvalidArgumentException', 'LogicException'], true);
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
                return $this->user($arguments);
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
        if ($type === '@response' && $method === 'header') {
            return FrameworkCallResult::value(FrameworkValue::type('@response'));
        }

        if ($type === '@collection') {
            return $this->collection($receiver, $method, $arguments);
        }

        if (str_starts_with($type, '@query:') || $this->sources->isA($type, 'Illuminate\\Database\\Eloquent\\Model') || $this->sources->isA($type, 'Illuminate\\Database\\Eloquent\\Builder') || $this->sources->isA($type, 'Illuminate\\Database\\Query\\Builder')) {
            return $this->query($receiver, $method, $arguments);
        }

        if ($this->isDateType($type)) {
            return $this->date($receiver, $method);
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
        if ($function === 'filter_var') {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if (in_array($function, ['throw_if', 'throw_unless'], true)) {
            $exception = $this->argument($arguments, 'exception', 1);
            $target = $exception?->literal;

            return new FrameworkCallResult(
                handled: true,
                value: null,
                targets: $target !== null && $this->sources->inScope($target)
                    ? [['class' => $target, 'method' => '__construct', 'arguments' => array_slice($arguments, 2)]]
                    : [],
            );
        }
        if (in_array($function, ['serialize', 'unserialize'], true)) {
            return $this->serialization($function, $arguments);
        }

        return FrameworkCallResult::unhandled();
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function serialization(string $function, array $arguments): FrameworkCallResult
    {
        $value = $arguments[0] ?? null;
        if ($function === 'serialize') {
            return $this->serializeValue($value);
        }

        return $this->unserializeValue($value);
    }

    private function serializeValue(?FrameworkValue $value): FrameworkCallResult
    {
        if ($value === null) {
            return FrameworkCallResult::value(FrameworkValue::scalar()->nullable());
        }
        if ($value->isUnknown()) {
            return new FrameworkCallResult(
                handled: true,
                value: FrameworkValue::scalar(),
                incomplete: 'Serialization input type is unresolved.',
            );
        }
        if (in_array($value->type, ['@array', '@scalar', '@callback', '@callback-result'], true)) {
            $targets = $this->serializationTargets($value, $this->serializeHooks($value->type));

            return new FrameworkCallResult(true, FrameworkValue::scalar(), targets: $targets);
        }
        if ($value->type === null || ! $this->sources->inScope($value->type)) {
            return new FrameworkCallResult(
                handled: true,
                value: FrameworkValue::scalar(),
                incomplete: 'Serialization hooks are unavailable for '.$value->type.'.',
            );
        }

        return new FrameworkCallResult(
            handled: true,
            value: FrameworkValue::scalar(),
            targets: $this->serializationTargets($value, $this->serializeHooks($value->type)),
        );
    }

    private function unserializeValue(?FrameworkValue $value): FrameworkCallResult
    {
        $literal = $value?->literal;
        if (! is_string($literal)) {
            return new FrameworkCallResult(
                handled: true,
                value: FrameworkValue::unknown(),
                incomplete: 'Serialized payload type is unresolved.',
            );
        }
        if ($literal === 'N;') {
            return FrameworkCallResult::value(FrameworkValue::scalar()->nullable());
        }
        if (preg_match('/^i:(?:0|-?[1-9][0-9]*);$/', $literal) === 1
            || preg_match('/^b:[01];$/', $literal) === 1
            || preg_match('/^d:(?:-?(?:[0-9]+(?:\\.[0-9]*)?|\\.[0-9]+)(?:[eE][+-]?[0-9]+)?);$/', $literal) === 1) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if ($this->isSerializedString($literal)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if ($literal === 'a:0:{}') {
            return FrameworkCallResult::value(FrameworkValue::array([]));
        }
        if (str_starts_with($literal, 'a:') || str_starts_with($literal, 'C:')) {
            return new FrameworkCallResult(
                handled: true,
                value: FrameworkValue::unknown(),
                incomplete: 'Serialized payload uses a format whose result type or hooks cannot be verified safely.',
            );
        }
        if (preg_match('/^O:([0-9]+):"([^"]+)":0:\\{\\}$/', $literal, $matches) !== 1
            || (int) $matches[1] !== strlen($matches[2])) {
            return new FrameworkCallResult(
                handled: true,
                value: FrameworkValue::unknown(),
                incomplete: 'Serialized object payload format is unresolved.',
            );
        }
        $class = $matches[2];
        if (! $this->sources->inScope($class)) {
            return new FrameworkCallResult(
                handled: true,
                value: FrameworkValue::type($class),
                incomplete: 'Serialized payload class '.$class.' is unavailable.',
            );
        }

        return new FrameworkCallResult(
            handled: true,
            value: FrameworkValue::type($class),
            targets: $this->serializationTargets(FrameworkValue::type($class), $this->unserializeHooks($class)),
        );
    }

    private function isSerializedString(string $literal): bool
    {
        if (preg_match('/^s:([0-9]+):"/', $literal, $matches) !== 1) {
            return false;
        }
        $start = strlen($matches[0]);
        $length = (int) $matches[1];

        return strlen($literal) === $start + $length + 2
            && substr($literal, $start + $length) === '";';
    }

    /** @return list<string> */
    private function serializeHooks(?string $class): array
    {
        return $this->isSerializable($class)
            ? ['__serialize', 'serialize', '__sleep']
            : ['__serialize', '__sleep'];
    }

    /** @return list<string> */
    private function unserializeHooks(string $class): array
    {
        return $this->isSerializable($class)
            ? ['__unserialize', 'unserialize', '__wakeup']
            : ['__unserialize', '__wakeup'];
    }

    private function isSerializable(?string $class, int $depth = 0): bool
    {
        if ($class === null || $depth >= 12 || ($source = $this->sources->get($class)) === null) {
            return false;
        }
        if ($source->node instanceof Node\Stmt\Class_) {
            foreach ($source->node->implements as $interface) {
                if ($this->isSerializableInterface($source->file->resolvedName($interface), $depth + 1)) {
                    return true;
                }
            }
            if ($source->node->extends !== null) {
                return $this->isSerializable($source->file->resolvedName($source->node->extends), $depth + 1);
            }
        }
        if ($source->node instanceof Node\Stmt\Interface_) {
            foreach ($source->node->extends as $interface) {
                if ($this->isSerializableInterface($source->file->resolvedName($interface), $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isSerializableInterface(string $interface, int $depth): bool
    {
        if ($depth >= 12) {
            return false;
        }
        if (strcasecmp(ltrim($interface, '\\'), 'Serializable') === 0) {
            return true;
        }
        $source = $this->sources->get($interface);
        if ($source?->node instanceof Node\Stmt\Interface_) {
            foreach ($source->node->extends as $parent) {
                if ($this->isSerializableInterface($source->file->resolvedName($parent), $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $methods
     * @return list<array{class: string, method: string}>
     */
    private function serializationTargets(FrameworkValue $value, array $methods): array
    {
        $targets = [];
        foreach ($value->items as $item) {
            if ($item === null) {
                continue;
            }
            array_push($targets, ...$this->serializationTargets($item, $methods));
        }
        $class = $value->type;
        if ($class === null || ! $this->sources->inScope($class)) {
            return $targets;
        }
        foreach ($methods as $method) {
            if ($this->sources->method($class, $method) !== null) {
                $targets[] = ['class' => $class, 'method' => $method];
                break;
            }
        }

        return $targets;
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function collection(?FrameworkValue $receiver, string $method, array $arguments): FrameworkCallResult
    {
        $element = $receiver?->element;

        return match ($method) {
            'count', 'isempty', 'isnotempty', 'implode' => FrameworkCallResult::value(FrameworkValue::scalar()),
            'toarray' => FrameworkCallResult::value(FrameworkValue::array([])),
            'all' => FrameworkCallResult::value($receiver ?? FrameworkValue::collection()),
            'values' => FrameworkCallResult::value($receiver ?? FrameworkValue::collection()),
            'keys' => FrameworkCallResult::value(FrameworkValue::collection(FrameworkValue::scalar())),
            'pluck' => FrameworkCallResult::value(FrameworkValue::collection(FrameworkValue::scalar())),
            'first' => FrameworkCallResult::value($element?->nullable() ?? FrameworkValue::unknown()),
            'firstwhere' => FrameworkCallResult::value($element?->nullable() ?? FrameworkValue::unknown()),
            'map' => $this->map($receiver, $arguments),
            'each' => $this->each($receiver, $arguments),
            default => FrameworkCallResult::unhandled(),
        };
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function query(?FrameworkValue $receiver, string $method, array $arguments): FrameworkCallResult
    {
        $type = $receiver->type ?? '';
        $model = str_starts_with($type, '@query:')
            ? FrameworkValue::type(substr($type, 7))
            : ($this->sources->isA($type, 'Illuminate\\Database\\Eloquent\\Model') ? FrameworkValue::type($type) : FrameworkValue::unknown());

        if (in_array($method, ['when', 'unless', 'tap', 'each'], true)) {
            $callbacks = [];
            foreach ($arguments as $index => $argument) {
                if ($argument?->callback === null) {
                    continue;
                }
                if ($method === 'when' || $method === 'unless') {
                    $parameters = $index === 0
                        ? [0 => $receiver, 1 => $arguments[0] ?? null, 'query' => $receiver, 'value' => $arguments[0] ?? null]
                        : [0 => $receiver, 1 => $arguments[0] ?? null, 'query' => $receiver, 'default' => $arguments[0] ?? null];
                } elseif ($method === 'tap') {
                    $parameters = [0 => $receiver, 'query' => $receiver];
                } else {
                    $parameters = [0 => $model, 1 => FrameworkValue::scalar(), 'item' => $model, 'key' => FrameworkValue::scalar()];
                }
                $callbacks[] = $argument->withParameters($parameters);
            }

            return new FrameworkCallResult(true, $receiver, callbacks: $callbacks);
        }

        return match ($method) {
            'query', 'newquery', 'newmodelquery', 'where', 'orwhere', 'wherenull', 'wherenotnull', 'wherein', 'wherenotin', 'wherebetween', 'wheredate', 'whereyear', 'wheremonth', 'wherehas', 'orwherehas', 'has', 'doesnthave', 'wheredoesnthave', 'with', 'without', 'withcount', 'withsum', 'withavg', 'orderby', 'orderbydesc', 'latest', 'oldest', 'limit', 'take', 'skip', 'offset', 'select', 'addselect', 'distinct', 'groupby', 'having', 'join', 'leftjoin', 'rightjoin', 'withoutglobalscopes', 'withoutglobalscope', 'withtrashed', 'onlytrashed', 'usewritepdo', 'lockforupdate', 'sharedlock' => FrameworkCallResult::value(str_starts_with($type, '@query:') ? $receiver : FrameworkValue::type('@query:'.($model->type ?? '@unknown'))),
            'get', 'all', 'cursor', 'lazy', 'pluck' => FrameworkCallResult::value(
                FrameworkValue::collection($method === 'pluck' ? FrameworkValue::scalar() : $model),
            ),
            'first', 'find', 'solevalue', 'value' => FrameworkCallResult::value($model->nullable()),
            'firstorfail', 'findorfail' => FrameworkCallResult::value($model),
            'count', 'exists', 'doesntexist', 'sum', 'avg', 'min', 'max', 'tosql', 'torawsql' => FrameworkCallResult::value(FrameworkValue::scalar()),
            default => FrameworkCallResult::unhandled(),
        };
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function map(?FrameworkValue $receiver, array $arguments): FrameworkCallResult
    {
        $callback = $this->argument($arguments, 'callback', 0);
        if ($callback?->callback === null) {
            return new FrameworkCallResult(true, FrameworkValue::collection(), incomplete: 'Collection map callback is dynamic.');
        }
        $element = $receiver?->element;
        $callback = $callback->withParameters([
            0 => $element,
            1 => FrameworkValue::scalar(),
            'item' => $element,
            'key' => FrameworkValue::scalar(),
        ]);

        return FrameworkCallResult::callbacks(
            [$callback],
            FrameworkValue::collection(FrameworkValue::callbackResult($callback)),
        );
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function each(?FrameworkValue $receiver, array $arguments): FrameworkCallResult
    {
        $callback = $this->argument($arguments, 'callback', 0);
        if ($callback?->callback === null) {
            return new FrameworkCallResult(true, $receiver, incomplete: 'Collection each callback is dynamic.');
        }
        $element = $receiver?->element;
        $callback = $callback->withParameters([
            0 => $element,
            1 => FrameworkValue::scalar(),
            'item' => $element,
            'key' => FrameworkValue::scalar(),
        ]);

        return new FrameworkCallResult(true, $receiver, callbacks: [$callback]);
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function user(array $arguments): FrameworkCallResult
    {
        $requested = $this->argument($arguments, 'guard', 0)?->literal;
        $guards = $requested !== null ? [$requested] : ($this->context->routeAuthGuards ?: [$this->context->defaultAuthGuard]);
        $models = [];
        $incomplete = null;
        foreach ($guards as $guard) {
            if (! is_string($guard) || ! isset($this->context->authGuards[$guard])) {
                $incomplete = 'Authentication guard for Request::user() is unavailable.';

                continue;
            }
            $definition = $this->context->authGuards[$guard];
            if ($definition['custom'] || $definition['model'] === null) {
                $incomplete = 'Authentication guard '.$guard.' uses a custom user provider or resolver.';

                continue;
            }
            $models[] = FrameworkValue::type($definition['model'])->nullable();
        }
        $value = FrameworkValue::union($models);

        return new FrameworkCallResult(
            handled: true,
            value: $value ?? FrameworkValue::unknown(),
            incomplete: $incomplete,
        );
    }

    private function date(?FrameworkValue $receiver, string $method): FrameworkCallResult
    {
        if (($receiver === null || $receiver->type === 'Illuminate\\Support\\Facades\\Date') && in_array($method, ['parse', 'instance', 'create', 'createfromformat'], true)) {
            return FrameworkCallResult::value(FrameworkValue::type('Carbon\\CarbonInterface'));
        }
        if (in_array($method, ['copy', 'startofweek', 'endofweek', 'addday', 'subday', 'format', 'todatestring', 'todatetimestring'], true)) {
            return FrameworkCallResult::value(in_array($method, ['format', 'todatestring', 'todatetimestring'], true)
                ? FrameworkValue::scalar()
                : FrameworkValue::type($receiver->type ?? 'Carbon\\CarbonInterface'));
        }

        return FrameworkCallResult::unhandled();
    }

    private function isDateType(string $type): bool
    {
        return in_array($type, ['Carbon\\Carbon', 'Carbon\\CarbonImmutable', 'Carbon\\CarbonInterface', 'Illuminate\\Support\\Carbon', 'Illuminate\\Support\\Facades\\Date'], true)
            || $this->sources->isA($type, 'Carbon\\CarbonInterface');
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function argument(array $arguments, string $name, int $index): ?FrameworkValue
    {
        return $arguments[$name] ?? $arguments[$index] ?? null;
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
