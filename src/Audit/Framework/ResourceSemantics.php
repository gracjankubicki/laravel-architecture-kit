<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;

final readonly class ResourceSemantics
{
    public function __construct(private SourceIndex $sources) {}

    /** @param array<int|string, FrameworkValue|null> $arguments */
    public function describe(FrameworkValue $receiver, string $method, array $arguments): FrameworkCallResult
    {
        $class = $receiver->resourceClass ?? $receiver->type;
        if ($class === null || (! $this->sources->isA($class, 'Illuminate\Http\Resources\Json\JsonResource') && ! str_starts_with($class, '@resource:'))) {
            return FrameworkCallResult::unhandled();
        }
        $class = str_starts_with($class, '@resource:') ? substr($class, 10) : $class;
        $method = strtolower($method);

        if ($method === 'make') {
            return FrameworkCallResult::value(FrameworkValue::resource($class));
        }
        if ($method === 'collection') {
            return FrameworkCallResult::value(FrameworkValue::resource($class, true));
        }
        if (in_array($method, ['when', 'mergewhen', 'whenloaded', 'whenhas', 'whenappended', 'whenaggregated', 'whenpivotloaded', 'whenpivotloadedas'], true)) {
            return FrameworkCallResult::callbacks($this->callbacks($arguments), FrameworkValue::scalar());
        }
        if (! in_array($method, ['resolve', 'toarray', 'toresponse', 'response', '@return'], true)) {
            return FrameworkCallResult::unhandled();
        }

        $targets = [];
        $candidates = match ($method) {
            'resolve' => ['resolve', 'toArray', 'toAttributes'],
            'toarray' => ['toArray', 'toAttributes'],
            'toresponse', 'response', '@return' => ['toResponse', 'resolve', 'toArray', 'toAttributes'],
        };
        foreach ($candidates as $target) {
            if ($this->sources->method($class, $target) !== null) {
                $targets[] = ['class' => $class, 'method' => $target];
                break;
            }
        }
        $collected = $this->collectedResource($class);
        if ($collected !== null) {
            foreach (['toArray', 'toAttributes'] as $target) {
                if ($this->sources->method($collected, $target) !== null) {
                    $targets[] = ['class' => $collected, 'method' => $target];
                    break;
                }
            }
        }
        if ($targets === [] && $this->sources->inScope($class)) {
            return new FrameworkCallResult(true, FrameworkValue::scalar(), incomplete: 'Resource transformation is unavailable for '.$class.'.');
        }

        return new FrameworkCallResult(true, FrameworkValue::scalar(), $targets);
    }

    private function collectedResource(string $class): ?string
    {
        if (! $this->sources->isA($class, 'Illuminate\Http\Resources\Json\ResourceCollection')) {
            return null;
        }
        $source = $this->sources->get($class);
        foreach ($source?->node->getProperties() ?? [] as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'collects') {
                    continue;
                }
                $value = $prop->default;
                if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class') {
                    return $source->file->resolvedName($value->class);
                }
            }
        }

        return null;
    }

    /** @param array<int|string, FrameworkValue|null> $arguments
     * @return list<FrameworkValue>
     */
    private function callbacks(array $arguments): array
    {
        return array_values(array_filter($arguments, fn (?FrameworkValue $value): bool => $value?->callback instanceof Expr\Closure || $value?->callback instanceof Expr\ArrowFunction));
    }
}
