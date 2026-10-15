<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;

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

    public function resolveAlias(string $name): string
    {
        return $this->sources->inScope($name) ? $name : ($this->context->aliases[$name] ?? $name);
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
        if ($type === 'Illuminate\\Support\\Facades\\App' && in_array($method, ['runninginconsole', 'runningunittests', 'environment', 'isproduction', 'islocal'], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if (in_array($type, ['Illuminate\\Support\\Facades\\Auth', '@auth'], true) && $method === 'user') {
            return $this->user($receiver?->literal === null ? [] : ['guard' => FrameworkValue::scalar($receiver->literal)]);
        }
        if ($type === 'Illuminate\\Support\\Str' && in_array($method, ['slug', 'uuid', 'ordereduuid', 'lower', 'upper', 'title', 'length', 'contains', 'startswith', 'endswith'], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
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
            if (in_array($method, ['input', 'query', 'cookie', 'hascookie', 'boolean', 'integer', 'float', 'date', 'enum', 'enums', 'string', 'collect', 'validated', 'all', 'only', 'except', 'route', 'get', 'has', 'filled', 'bearertoken'], true)) {
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

        if ($type === '@response' && in_array($method, ['json', 'make', 'back', 'route', 'to'], true)) {
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
        if ($type === '@response' && in_array($method, ['with', 'witherrors', 'withinput'], true)) {
            return FrameworkCallResult::effect('write', 'RedirectResponse::'.$method.'() flashes session data.', FrameworkValue::type('@response'));
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

        $enum = $this->sources->get($type)?->node;
        if (Node::is($enum, 'statement.enum')) {
            if ($method === 'cases') {
                return FrameworkCallResult::value(new FrameworkValue(type: '@array', element: FrameworkValue::type($type)));
            }
            if ($enum->scalarType !== null && in_array($method, ['from', 'tryfrom'], true)) {
                $value = FrameworkValue::type($type);

                return FrameworkCallResult::value($method === 'tryfrom' ? $value->nullable() : $value);
            }
        }

        return FrameworkCallResult::unhandled();
    }

    /** @param array<int|string, FrameworkValue|null> $arguments */
    private function function(string $function, array $arguments, SourceClass $source, Node $call): FrameworkCallResult
    {
        $function = strtolower(ltrim($function, '\\'));
        if (in_array($function, ['abort', 'abort_if', 'abort_unless'], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if ($function === 'auth') {
            $guard = $this->argument($arguments, 'guard', 0);
            $provided = array_key_exists('guard', $arguments) || array_key_exists(0, $arguments);
            $literalNull = false;
            if (Node::is($call, 'expression.func-call')) {
                foreach ($call->getArgs() as $position => $arg) {
                    if (($arg->name?->toString() === 'guard' || ($position === 0 && $arg->name === null))
                        && Node::is($arg->value, 'expression.const-fetch') && strtolower($arg->value->name->toString()) === 'null') {
                        $literalNull = true;
                    }
                }
            }
            if ($provided && ! $literalNull && $guard?->literal === null) {
                return new FrameworkCallResult(true, FrameworkValue::unknown(), incomplete: 'Dynamic authentication guard cannot be resolved.', incompleteReason: 'unresolved_receiver');
            }

            return FrameworkCallResult::value(new FrameworkValue(type: '@auth', literal: $guard?->literal));
        }
        if (in_array($function, ['now', 'today'], true)) {
            return FrameworkCallResult::value(FrameworkValue::type('Illuminate\\Support\\Carbon'));
        }
        if (in_array($function, ['hash', 'hash_equals', 'preg_match'], true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }
        if ($function === 'json_decode') {
            // Payload-dependent shapes are not concrete application class types.
            return FrameworkCallResult::value(FrameworkValue::unknown());
        }
        if (in_array($function, ['array_map', 'array_filter', 'array_reduce', 'preg_replace_callback'], true)) {
            $position = in_array($function, ['array_filter', 'array_reduce', 'preg_replace_callback'], true) ? 1 : 0;
            $callback = $this->argument($arguments, 'callback', $position);
            if ($callback?->callback === null) {
                $callbackNode = null;
                if (Node::is($call, 'expression.func-call')) {
                    foreach ($call->getArgs() as $index => $argument) {
                        if ($argument->name?->toString() === 'callback' || $index === $position && $argument->name === null) {
                            $callbackNode = $argument->value;
                        }
                    }
                }
                if (in_array($function, ['array_map', 'array_filter'], true)
                    && ($function === 'array_filter' && $callbackNode === null || Node::is($callbackNode, 'expression.const-fetch') && strtolower($callbackNode->name->toString()) === 'null')) {
                    return FrameworkCallResult::value(FrameworkValue::array([]));
                }

                return new FrameworkCallResult(true, FrameworkValue::unknown(), incomplete: $function.' callback is dynamic or unavailable.');
            }
            $array = $this->argument($arguments, 'array', $function === 'array_map' ? 1 : 0);
            $element = $array->element ?? FrameworkValue::merge(array_values($array->items ?? []));
            $callback = $callback->withParameters($function === 'array_reduce'
                ? [0 => $arguments['initial'] ?? $arguments[2] ?? FrameworkValue::unknown(), 1 => $element]
                : [0 => $function === 'preg_replace_callback' ? FrameworkValue::array([]) : $element]);

            return FrameworkCallResult::callbacks([$callback], FrameworkValue::unknown());
        }
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
        if (Node::is($source->node, 'statement.class')) {
            foreach ($source->node->implements as $interface) {
                if ($this->isSerializableInterface($source->file->resolvedName($interface), $depth + 1)) {
                    return true;
                }
            }
            if ($source->node->extends !== null) {
                return $this->isSerializable($source->file->resolvedName($source->node->extends), $depth + 1);
            }
        }
        if (Node::is($source->node, 'statement.interface')) {
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
        if (Node::is($source?->node, 'statement.interface')) {
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
            : ($receiver->element ?? ($this->sources->isA($type, 'Illuminate\\Database\\Eloquent\\Model') ? FrameworkValue::type($type) : FrameworkValue::unknown()));

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
            'query', 'newquery', 'newmodelquery', 'where', 'wherekey', 'wherekeynot', 'orwhere', 'wherenull', 'wherenotnull', 'wherein', 'wherenotin', 'wherebetween', 'wheredate', 'whereyear', 'wheremonth', 'wherehas', 'orwherehas', 'has', 'doesnthave', 'wheredoesnthave', 'with', 'without', 'withcount', 'withsum', 'withavg', 'orderby', 'orderbydesc', 'latest', 'oldest', 'limit', 'take', 'skip', 'offset', 'select', 'addselect', 'distinct', 'groupby', 'having', 'join', 'leftjoin', 'rightjoin', 'withoutglobalscopes', 'withoutglobalscope', 'withtrashed', 'onlytrashed', 'usewritepdo', 'lockforupdate', 'sharedlock' => FrameworkCallResult::value((str_starts_with($type, '@query:') || $this->sources->isA($type, 'Illuminate\\Database\\Eloquent\\Builder')) ? $receiver : FrameworkValue::type('@query:'.($model->type ?? '@unknown'))),
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
            incompleteReason: 'unresolved_receiver',
        );
    }

    private function date(?FrameworkValue $receiver, string $method): FrameworkCallResult
    {
        if (in_array($method, ['now', 'today', 'parse', 'instance', 'create', 'createfromformat'], true)) {
            return FrameworkCallResult::value(FrameworkValue::type($receiver?->type === 'Illuminate\\Support\\Facades\\Date' ? 'Carbon\\CarbonInterface' : ($receiver->type ?? 'Carbon\\CarbonInterface')));
        }
        if (in_array($method, ['toimmutable', 'tomutable'], true)) {
            return FrameworkCallResult::value(FrameworkValue::type($method === 'toimmutable' ? 'Carbon\\CarbonImmutable' : 'Carbon\\Carbon'));
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
        $incompleteReason = $base->incompleteReason;
        $walk = function (?FrameworkValue $value) use (&$targets, &$callbacks, &$incomplete, &$incompleteReason, &$walk): void {
            if ($value === null) {
                return;
            }
            if ($value->resourceClass !== null) {
                $resource = $this->resources->describe($value, 'resolve', []);
                array_push($targets, ...$resource->targets);
                array_push($callbacks, ...$resource->callbacks);
                if ($incomplete === null && $resource->incomplete !== null) {
                    $incomplete = $resource->incomplete;
                    $incompleteReason = $resource->incompleteReason;
                }
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

        return new FrameworkCallResult(true, $base->value, $targets, $callbacks, $base->effects, $incomplete, $incompleteReason);
    }
}
