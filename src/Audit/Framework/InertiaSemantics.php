<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;

final readonly class InertiaSemantics
{
    public function __construct(private SourceIndex $sources, private FrameworkContext $context) {}

    /** @param array<int|string, FrameworkValue|null> $arguments */
    public function describe(FrameworkValue $receiver, string $method, array $arguments): FrameworkCallResult
    {
        if (! in_array($receiver->type, ['Inertia\Inertia', 'Inertia\ResponseFactory', '@inertia'], true)) {
            return FrameworkCallResult::unhandled();
        }
        $method = strtolower($method);
        if ($method === 'share') {
            return FrameworkCallResult::value(FrameworkValue::type('@inertia'));
        }
        if (in_array($method, ['lazy', 'defer', 'optional', 'always', 'merge', 'deepmerge', 'once'], true)) {
            [$targets, $callbacks, $incomplete] = $this->executables($arguments);

            return new FrameworkCallResult(true, FrameworkValue::scalar(), $targets, $callbacks, incomplete: $incomplete);
        }
        if ($method !== 'render') {
            return FrameworkCallResult::unhandled();
        }

        [$targets, $callbacks, $incomplete] = $this->executables([...$arguments, ...$this->context->inertiaShares]);
        if ($this->context->status === FrameworkContext::UNAVAILABLE) {
            $incomplete ??= $this->context->unavailable ?? 'Inertia shared-prop context is unavailable.';
        }

        return new FrameworkCallResult(true, FrameworkValue::type('Inertia\Response'), $targets, $callbacks, incomplete: $incomplete);
    }

    /** @param array<int|string, FrameworkValue|null> $values
     * @return array{list<array{class: string, method: string}>, list<FrameworkValue>, ?string}
     */
    private function executables(array $values): array
    {
        $targets = [];
        $callbacks = [];
        $incomplete = null;
        $walk = function (?FrameworkValue $value) use (&$targets, &$callbacks, &$incomplete, &$walk): void {
            if ($value === null) {
                $incomplete ??= 'Inertia prop or callback is dynamic.';

                return;
            }
            if ($value->isUnknown()) {
                $incomplete ??= 'Inertia prop or callback is dynamic.';
                if ($value->type !== '@array') {
                    return;
                }
            }
            if ($value->callback !== null) {
                $callbacks[] = $value;

                return;
            }
            if ($value->type === '@array' && isset($value->items[0], $value->items[1])) {
                $class = $value->items[0]->literal;
                $method = $value->items[1]->literal;
                if ($class !== null && $method !== null && $this->sources->method($class, $method) !== null) {
                    $targets[] = ['class' => $class, 'method' => $method];

                    return;
                }
                if ($class !== null && $method !== null && $this->sources->inScope($class)) {
                    $incomplete ??= 'Inertia callable '.$class.'::'.$method.' is unavailable.';

                    return;
                }
            }
            foreach ($value->items as $item) {
                $walk($item);
            }
        };
        foreach ($values as $value) {
            $walk($value);
        }

        return [$targets, $callbacks, $incomplete];
    }
}
