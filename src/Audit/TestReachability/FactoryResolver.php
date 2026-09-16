<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class FactoryResolver
{
    public function __construct(private SourceIndex $sources, private string $appNamespace = 'App\\', private ?string $factoryNamespace = 'Database\\Factories\\') {}

    public function resolve(string $model, string $path, int $line): TestReachabilityResult
    {
        $result = new TestReachabilityResult;
        if (! $this->sources->inScope($model)) {
            $result->incomplete($path, $line, 'Factory receiver source is unavailable in the audit scope: '.$model.'.');

            return $result;
        }
        if (! $this->sources->isA($model, 'Illuminate\\Database\\Eloquent\\Model')) {
            if ($this->sources->unavailableReason($model) !== null) {
                $result->incomplete($path, $line, $this->sources->unavailableReason($model));
            }

            return $result;
        }
        $source = $this->sources->get($model);
        if ($source === null) {
            $result->incomplete($path, $line, 'Model source is unavailable: '.$model);

            return $result;
        }
        $factory = null;
        $override = $this->sources->method($model, 'factory') ?? $this->sources->method($model, 'newFactory');
        if ($override !== null) {
            [$owner, $method] = $override;
            if (count($method->stmts ?? []) === 1 && $method->stmts[0] instanceof Stmt\Return_) {
                $factory = $this->factoryExpression($owner, $method->stmts[0]->expr);
            }
            if ($factory === null) {
                $result->incomplete($path, $line, 'Dynamic factory override for '.$model.'.');

                return $result;
            }
        } else {
            if (! $this->hasFactory($source)) {
                $result->incomplete($path, $line, 'Model factory cannot be resolved without HasFactory or a supported factory override: '.$model.'.');

                return $result;
            }
            $property = $this->factoryProperty($source);
            if ($property === false) {
                $result->incomplete($path, $line, 'Dynamic $factory declaration for '.$model.'.');

                return $result;
            }
            $factory = $property;
            if ($factory === null) {
                foreach ($source->node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if ($source->file->resolvedName($attribute->name) === 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory') {
                            $factory = $this->factoryExpression($source, $attribute->args[0]->value ?? null);
                            if ($factory === null) {
                                $result->incomplete($path, $line, 'Dynamic UseFactory declaration for '.$model.'.');

                                return $result;
                            }
                        }
                    }
                }
            }
            if ($factory === null) {
                if ($this->factoryNamespace === null) {
                    $result->incomplete($path, $line, 'Custom factory naming callback or namespace cannot be resolved statically.');

                    return $result;
                }
                $prefix = str_starts_with($model, $this->appNamespace.'Models\\') ? $this->appNamespace.'Models\\' : $this->appNamespace;
                $relative = str_starts_with($model, $prefix) ? substr($model, strlen($prefix)) : $model;
                $factory = $this->factoryNamespace.$relative.'Factory';
            }
        }
        // The generic is documentation, never a replacement for runtime dispatch.
        foreach ($source->node->getTraitUses() as $use) {
            $doc = $use->getDocComment()?->getText() ?? '';
            if (preg_match('/@use\s+[^\s<]*HasFactory\s*<\s*([^>\s]+)\s*>/', $doc, $match)) {
                $generic = $this->docName($source, $match[1]);
                if (strcasecmp($generic, $factory) !== 0) {
                    $result->incomplete($path, $line, 'HasFactory generic conflicts with runtime factory '.$factory.'.');

                    return $result;
                }
            }
        }
        if ($this->sources->get($factory) === null) {
            // database is not pulled into scope automatically; no finding about its classes.
            $result->incomplete($path, $line, $this->sources->unavailableReason($factory) ?? 'Factory source is unavailable in the audit scope: '.$factory.'.');
        } elseif (! $this->sources->isA($factory, 'Illuminate\\Database\\Eloquent\\Factories\\Factory')) {
            $result->incomplete($path, $line, $this->sources->unavailableReason($factory) ?? 'Resolved class is not a Laravel factory: '.$factory.'.');
        } else {
            $result->reach($factory, [$path.':'.$line, $model.'::factory', $factory]);
        }

        return $result;
    }

    private function hasFactory(SourceClass $source, int $depth = 0): bool
    {
        if ($depth >= 12) {
            return false;
        }
        foreach ($source->node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $name = $source->file->resolvedName($trait);
                if ($name === 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory') {
                    return true;
                }
                $nested = $this->sources->get($name);
                if ($nested !== null && $this->hasFactory($nested, $depth + 1)) {
                    return true;
                }
            }
        }
        $parent = $this->parent($source);

        return $parent !== null && $this->hasFactory($parent, $depth + 1);
    }

    private function factoryProperty(SourceClass $source, int $depth = 0): string|false|null
    {
        if ($depth >= 12) {
            return false;
        }
        foreach ($source->node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'factory') {
                    return $this->factoryExpression($source, $prop->default) ?? false;
                }
            }
        }
        $parent = $this->parent($source);

        return $parent !== null ? $this->factoryProperty($parent, $depth + 1) : null;
    }

    private function parent(SourceClass $source): ?SourceClass
    {
        return $source->node instanceof Stmt\Class_ && $source->node->extends !== null ? $this->sources->get($source->file->resolvedName($source->node->extends)) : null;
    }

    private function factoryExpression(SourceClass $source, ?Expr $expr): ?string
    {
        if ($expr instanceof Expr\New_ || ($expr instanceof Expr\StaticCall && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'new')
            || ($expr instanceof Expr\ClassConstFetch && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class')) {
            return $expr->class instanceof Node\Name ? $source->file->resolvedName($expr->class) : null;
        }

        return $expr instanceof Node\Scalar\String_ ? ltrim($expr->value, '\\') : null;
    }

    private function docName(SourceClass $source, string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        foreach ((new NodeFinder)->findInstanceOf($source->file->ast() ?? [], Stmt\Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $alias = $item->getAlias()->toString();
                if ($name === $alias || str_starts_with($name, $alias.'\\')) {
                    return $item->name->toString().substr($name, strlen($alias));
                }
            }
        }
        $position = strrpos($source->name, '\\');

        return ($position !== false ? substr($source->name, 0, $position + 1) : '').$name;
    }
}
