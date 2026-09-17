<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;

final readonly class GateSemantics
{
    public function __construct(private SourceIndex $sources, private FrameworkContext $context) {}

    /** @param array<int|string, FrameworkValue|null> $arguments */
    public function describe(FrameworkValue $receiver, string $method, array $arguments): FrameworkCallResult
    {
        if (! in_array($receiver->type, ['Illuminate\Support\Facades\Gate', 'Illuminate\Contracts\Auth\Access\Gate', '@gate'], true)) {
            return FrameworkCallResult::unhandled();
        }

        $method = strtolower($method);
        if ($method === 'foruser') {
            return FrameworkCallResult::value(FrameworkValue::type('@gate'));
        }
        if (! in_array($method, ['authorize', 'allows', 'check', 'denies', 'inspect', 'any', 'none'], true)) {
            return FrameworkCallResult::unhandled();
        }

        $ability = $arguments[0]?->literal;
        if ($ability === null) {
            return new FrameworkCallResult(true, FrameworkValue::scalar(), incomplete: 'Gate ability is dynamic or unavailable.');
        }

        $targets = [];
        $callbacks = [];
        foreach ($this->context->gateBefore as $target) {
            $this->append($target, $targets, $callbacks);
        }

        if (isset($this->context->gateAbilities[$ability])) {
            $this->append($this->context->gateAbilities[$ability], $targets, $callbacks);
        } else {
            $subject = $arguments[1] ?? null;
            $model = $this->model($subject);
            if ($model === null) {
                return new FrameworkCallResult(true, FrameworkValue::scalar(), $targets, $callbacks, incomplete: 'Gate policy selection is unavailable for ability '.$ability.'.');
            }
            $policy = $this->context->gatePolicies[$model] ?? $this->attributePolicy($model) ?? $this->conventionalPolicy($model);
            if ($policy === null) {
                return new FrameworkCallResult(true, FrameworkValue::scalar(), $targets, $callbacks, incomplete: 'Gate policy selection is unavailable for '.$model.'.');
            }
            if ($this->sources->method($policy, 'before') !== null) {
                $targets[] = ['class' => $policy, 'method' => 'before'];
            }
            $policyMethod = $this->policyMethod($ability);
            if ($this->sources->method($policy, $policyMethod) === null) {
                return new FrameworkCallResult(true, FrameworkValue::scalar(), $targets, $callbacks, incomplete: 'Policy method '.$policy.'::'.$policyMethod.' is unavailable.');
            }
            $targets[] = ['class' => $policy, 'method' => $policyMethod];
        }

        foreach ($this->context->gateAfter as $target) {
            $this->append($target, $targets, $callbacks);
        }

        return new FrameworkCallResult(true, FrameworkValue::scalar(), $targets, $callbacks);
    }

    /**
     * @param  array{class: string, method: string}|FrameworkValue  $target
     * @param  list<array{class: string, method: string}>  $targets
     * @param  list<FrameworkValue>  $callbacks
     */
    private function append(array|FrameworkValue $target, array &$targets, array &$callbacks): void
    {
        if ($target instanceof FrameworkValue) {
            $callbacks[] = $target;
        } else {
            $targets[] = $target;
        }
    }

    private function model(?FrameworkValue $value): ?string
    {
        if ($value?->literal !== null && $this->sources->isA($value->literal, 'Illuminate\Database\Eloquent\Model')) {
            return $value->literal;
        }
        if ($value?->type !== null && $this->sources->isA($value->type, 'Illuminate\Database\Eloquent\Model')) {
            return $value->type;
        }
        $first = $value?->items[0] ?? null;

        return $first instanceof FrameworkValue ? $this->model($first) : null;
    }

    private function conventionalPolicy(string $model): ?string
    {
        if (str_contains($model, '\\Models\\')) {
            $candidate = str_replace('\\Models\\', '\\Policies\\', $model).'Policy';
        } else {
            $separator = strrpos($model, '\\');
            $candidate = $separator === false
                ? 'Policies\\'.$model.'Policy'
                : substr($model, 0, $separator).'\\Policies\\'.substr($model, $separator + 1).'Policy';
        }

        return is_string($candidate) && $this->sources->get($candidate) !== null ? $candidate : null;
    }

    private function attributePolicy(string $model): ?string
    {
        $source = $this->sources->get($model);
        foreach ($source?->node->attrGroups ?? [] as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $source->file->resolvedName($attribute->name);
                if ($name !== 'Illuminate\Database\Eloquent\Attributes\UsePolicy') {
                    continue;
                }
                $argument = $attribute->args[0]->value ?? null;
                if ($argument instanceof ClassConstFetch && $argument->class instanceof Name) {
                    return $source->file->resolvedName($argument->class);
                }
            }
        }

        return null;
    }

    private function policyMethod(string $ability): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $ability))));
    }
}
