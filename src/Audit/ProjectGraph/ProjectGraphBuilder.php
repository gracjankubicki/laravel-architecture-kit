<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class ProjectGraphBuilder
{
    /** @var array<int, ProjectSymbol> */
    private array $symbols = [];

    /** @var array<int, DependencyEdge> */
    private array $edges = [];

    public function __construct(private readonly RoleClassifier $roles = new RoleClassifier) {}

    /**
     * @param  array<int, FileContext>  $files
     */
    public function build(array $files): ProjectGraphSnapshot
    {
        $this->symbols = [];
        $this->edges = [];

        foreach ($files as $file) {
            $this->add($file);
        }

        return $this->finish();
    }

    public function add(FileContext $file): void
    {
        $nodes = null;

        try {
            $nodes = $file->ast();

            if ($nodes === null) {
                return;
            }

            foreach ($nodes as $node) {
                $this->visit($file, $node, null, $this->symbols, $this->edges, []);
            }
        } finally {
            unset($nodes);
            $file->releaseAst();
        }
    }

    public function finish(): ProjectGraphSnapshot
    {
        $symbols = $this->symbols;
        $edges = $this->edges;

        usort($symbols, fn (ProjectSymbol $left, ProjectSymbol $right): int => [$left->name, $left->path, $left->line] <=> [$right->name, $right->path, $right->line]);

        $unique = [];

        foreach ($edges as $edge) {
            if (strcasecmp($edge->from, $edge->to) === 0) {
                continue;
            }

            $key = strtolower(implode('|', [$edge->from, $edge->to, $edge->path, (string) $edge->line, $edge->kind]));
            $unique[$key] = $edge;
        }

        $edges = array_values($unique);
        usort($edges, fn (DependencyEdge $left, DependencyEdge $right): int => [$left->from, $left->to, $left->path, $left->line, $left->kind] <=> [$right->from, $right->to, $right->path, $right->line, $right->kind]);

        return new ProjectGraphSnapshot($symbols, $edges);
    }

    /**
     * @param  array<int, ProjectSymbol>  $symbols
     * @param  array<int, DependencyEdge>  $edges
     * @param  array<int, true>  $eloquentRelationNodes
     */
    private function visit(
        FileContext $file,
        Node $node,
        ?string $source,
        array &$symbols,
        array &$edges,
        array $eloquentRelationNodes,
    ): void {
        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $source = $this->symbolName($node);

            if ($source !== null) {
                $kind = match (true) {
                    $node instanceof Stmt\Interface_ => 'interface',
                    $node instanceof Stmt\Trait_ => 'trait',
                    $node instanceof Stmt\Enum_ => 'enum',
                    default => 'class',
                };
                $hasMethods = ! $node instanceof Stmt\Interface_ || $node->getMethods() !== [];
                $symbols[] = new ProjectSymbol(
                    name: $source,
                    path: $file->path,
                    line: $node->getStartLine(),
                    namespace: $this->namespaceOf($source),
                    kind: $kind,
                    role: $this->roles->classify($file->path, $node->name->toString(), $kind, $hasMethods),
                );
            }
        }

        if ($source !== null) {
            $this->collectEdges($file, $node, $source, $edges, $eloquentRelationNodes);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            if ($child instanceof Node) {
                $this->visit($file, $child, $source, $symbols, $edges, $eloquentRelationNodes);

                continue;
            }

            if (! is_array($child)) {
                continue;
            }

            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->visit($file, $item, $source, $symbols, $edges, $eloquentRelationNodes);
                }
            }
        }
    }

    /**
     * @param  array<int, DependencyEdge>  $edges
     * @param  array<int, true>  $eloquentRelationNodes
     */
    private function collectEdges(
        FileContext $file,
        Node $node,
        string $source,
        array &$edges,
        array &$eloquentRelationNodes,
    ): void {
        if ($node instanceof Stmt\Class_) {
            $this->addName($file, $edges, $source, $node->extends, $node->getStartLine(), 'extends', true);

            foreach ($node->implements as $name) {
                $this->addName($file, $edges, $source, $name, $name->getStartLine(), 'implements', true);
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $name) {
                $this->addName($file, $edges, $source, $name, $name->getStartLine(), 'extends', true);
            }
        } elseif ($node instanceof Stmt\Enum_) {
            foreach ($node->implements as $name) {
                $this->addName($file, $edges, $source, $name, $name->getStartLine(), 'implements', true);
            }
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $name) {
                $this->addName($file, $edges, $source, $name, $name->getStartLine(), 'trait', true);
            }
        } elseif ($node instanceof Node\Param) {
            $this->addType($file, $edges, $source, $node->type, $node->getStartLine(), 'parameter');
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->addType($file, $edges, $source, $node->returnType, $node->getStartLine(), 'return');
        } elseif ($node instanceof Stmt\Property || $node instanceof Stmt\ClassConst) {
            $this->addType($file, $edges, $source, $node->type, $node->getStartLine(), 'property');
        } elseif ($node instanceof Expr\New_) {
            $this->addName($file, $edges, $source, $node->class instanceof Name ? $node->class : null, $node->getStartLine(), 'new', true);
        } elseif ($node instanceof Expr\StaticCall || $node instanceof Expr\StaticPropertyFetch) {
            $this->addName($file, $edges, $source, $node->class instanceof Name ? $node->class : null, $node->getStartLine(), 'static', true);
        } elseif ($node instanceof Expr\Instanceof_) {
            $this->addName($file, $edges, $source, $node->class instanceof Name ? $node->class : null, $node->getStartLine(), 'instanceof', true);
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as $name) {
                $this->addName($file, $edges, $source, $name, $name->getStartLine(), 'catch', true);
            }
        } elseif ($node instanceof Node\Attribute) {
            $this->addName($file, $edges, $source, $node->name, $node->getStartLine(), 'attribute', true);
        } elseif ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && $this->isEloquentRelation($node->name->toString())) {
            foreach ($node->args as $argument) {
                if (! $argument->value instanceof Expr\ClassConstFetch || ! $argument->value->class instanceof Name) {
                    continue;
                }

                $eloquentRelationNodes[spl_object_id($argument->value)] = true;
                $this->addName($file, $edges, $source, $argument->value->class, $argument->value->getStartLine(), 'eloquent-relation', false);
            }
        } elseif ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name) {
            $constant = strtolower($node->name instanceof Node\Identifier ? $node->name->toString() : '');

            if ($constant === 'class') {
                if (! isset($eloquentRelationNodes[spl_object_id($node)])) {
                    $this->addName($file, $edges, $source, $node->class, $node->getStartLine(), 'class-reference', false);
                }
            } else {
                $this->addName($file, $edges, $source, $node->class, $node->getStartLine(), 'class-constant', true);
            }
        }
    }

    /**
     * @param  array<int, DependencyEdge>  $edges
     */
    private function addType(FileContext $file, array &$edges, string $source, Node|string|null $type, int $line, string $kind): void
    {
        if ($type instanceof Name) {
            $this->addName($file, $edges, $source, $type, $line, $kind, true);

            return;
        }

        if ($type instanceof Node\NullableType) {
            $this->addType($file, $edges, $source, $type->type, $line, $kind);

            return;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $inner) {
                $this->addType($file, $edges, $source, $inner, $line, $kind);
            }
        }
    }

    /**
     * @param  array<int, DependencyEdge>  $edges
     */
    private function addName(FileContext $file, array &$edges, string $source, ?Name $name, int $line, string $kind, bool $strong): void
    {
        if ($name === null || in_array(strtolower($name->toString()), ['self', 'static', 'parent'], true)) {
            return;
        }

        $edges[] = new DependencyEdge(
            from: $source,
            to: ltrim($file->resolvedName($name), '\\'),
            path: $file->path,
            line: max(1, $line),
            kind: $kind,
            strong: $strong,
        );
    }

    private function symbolName(Stmt\ClassLike $node): ?string
    {
        return isset($node->namespacedName)
            ? ltrim($node->namespacedName->toString(), '\\')
            : null;
    }

    private function namespaceOf(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? '' : substr($name, 0, $position);
    }

    private function isEloquentRelation(string $method): bool
    {
        return in_array($method, [
            'belongsTo',
            'belongsToMany',
            'hasMany',
            'hasManyThrough',
            'hasOne',
            'hasOneThrough',
            'morphMany',
            'morphOne',
            'morphTo',
            'morphToMany',
            'morphedByMany',
        ], true);
    }
}
