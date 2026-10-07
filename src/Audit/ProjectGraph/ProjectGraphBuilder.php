<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocationExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\AuthConfigCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\BladeCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\ContainerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\DataCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\DataOperationCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\ExecutionCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\HttpCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCallCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\ResourceCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\TestCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\ValidationCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ImpactFacts;
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

    /** @var list<TestInvocation> */
    private array $testInvocations = [];

    /** @var list<ImpactFacts> */
    private array $impactFacts = [];

    /** @var list<CatalogFacts> */
    private array $catalogFacts = [];

    public function __construct(private readonly RoleClassifier $roles = new RoleClassifier, private readonly bool $impact = false, private readonly bool $preserveOccurrences = false, private readonly bool $catalog = false, private readonly ?string $basePath = null) {}

    /**
     * @param  array<int, FileContext>  $files
     */
    public function build(array $files): ProjectGraphSnapshot
    {
        $this->symbols = [];
        $this->edges = [];
        $this->testInvocations = [];
        $this->impactFacts = [];
        $this->catalogFacts = [];

        foreach ($files as $file) {
            $this->add($file);
        }

        return $this->finish();
    }

    public function add(FileContext $file): void
    {
        $this->addEntry($this->collect($file));
    }

    /**
     * Add a contribution that is already known, without parsing anything.
     *
     * This is what makes a partial rebuild possible: entries restored from the previous
     * run go in beside the ones just parsed, and `finish()` sorts and deduplicates the
     * whole set exactly as it would after a full build.
     */
    public function addEntry(FileGraphEntry $entry): void
    {
        array_push($this->symbols, ...$entry->symbols);
        array_push($this->edges, ...$entry->edges);
        array_push($this->testInvocations, ...$entry->testInvocations);
        if ($entry->impact !== null) {
            $this->impactFacts[] = $entry->impact;
        }
        if ($entry->catalog !== null) {
            $this->catalogFacts[] = $entry->catalog;
        }
    }

    /**
     * Parse one file and return what it contributes, without recording it.
     */
    public function collect(FileContext $file): FileGraphEntry
    {
        if ($this->catalog && in_array($file->path, ComposerCatalogExtractor::FILES, true)) {
            return new FileGraphEntry([], [], impact: $this->impact ? new ImpactFacts($file->path, [], [], []) : null,
                catalog: (new ComposerCatalogExtractor)->extract($file));
        }
        if (($this->impact || $this->catalog) && ($reason = ImpactExtractor::sourceLimit(strlen($file->contents))) !== null) {
            return new FileGraphEntry([], [], impact: $this->impact ? new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => $reason]]) : null,
                catalog: $this->catalog ? (new PhpCatalogExtractor)->extract($file) : null);
        }
        $nodes = null;
        $catalogFile = $file;
        $symbols = [];
        $edges = [];

        try {
            $catalogFile = $this->catalog && str_ends_with($file->path, '.blade.php') ? BladeCatalogExtractor::phpSource($file) : $file;
            $nodes = $catalogFile->ast();

            if ($nodes === null) {
                $partial = $this->catalog ? (new PhpCatalogExtractor)->extract($catalogFile) : null;
                if ($partial !== null) {
                    $blade = (new BladeCatalogExtractor)->extract($file);
                    $partial = new CatalogFacts($file->path, [...$partial->elements, ...$blade->elements], $blade->relations,
                        [...$partial->diagnostics, ...$blade->diagnostics]);
                }

                return new FileGraphEntry([], [], impact: $this->impact ? new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => 'Unparseable source.']]) : null,
                    catalog: $partial);
            }

            $testExtractor = new TestInvocationExtractor;
            $invocations = $testExtractor->extract($catalogFile);
            $impactFacts = $this->impact ? (new ImpactExtractor)->extract($catalogFile) : null;
            $catalogFacts = $this->catalog ? (new PhpCatalogExtractor)->extract($catalogFile) : null;
            if ($catalogFacts !== null) {
                $catalogFacts = (new DataCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $dataOperations = (new DataOperationCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $resources = (new ResourceCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $calls = (new PhpCallCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $container = (new ContainerCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $http = (new HttpCatalogExtractor($this->basePath ?? HttpCatalogExtractor::SOURCE_ROOT))->extract($catalogFile, $catalogFacts, $calls);
                $execution = (new ExecutionCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $blade = (new BladeCatalogExtractor)->extract($file);
                $auth = (new AuthConfigCatalogExtractor)->extract($catalogFile);
                $tests = (new TestCatalogExtractor)->extract($catalogFile, $catalogFacts, $invocations, $testExtractor->sourceOffsets());
                $validation = (new ValidationCatalogExtractor)->extract($catalogFile, $catalogFacts);
                $catalogFacts = new CatalogFacts($file->path, [...$catalogFacts->elements, ...$tests->elements, ...$dataOperations->elements, ...$resources->elements, ...$blade->elements, ...$auth->elements, ...$validation->elements], [...$catalogFacts->relations, ...$tests->relations, ...$resources->relations, ...$blade->relations, ...$calls->relations, ...$container->relations, ...$http->relations, ...$execution->relations, ...$auth->relations, ...$validation->relations], [...$catalogFacts->diagnostics, ...$tests->diagnostics, ...$dataOperations->diagnostics, ...$resources->diagnostics, ...$blade->diagnostics, ...$calls->diagnostics, ...$container->diagnostics, ...$http->diagnostics, ...$execution->diagnostics, ...$auth->diagnostics, ...$validation->diagnostics]);
            }
            $source = $this->fileSymbol($catalogFile, $nodes, $symbols);

            foreach ($nodes as $node) {
                $this->visit($catalogFile, $node, $source, $symbols, $edges, []);
            }
        } finally {
            unset($nodes);
            $file->releaseAst();
            if ($catalogFile !== $file) {
                $catalogFile->releaseAst();
            }
        }

        return new FileGraphEntry($symbols, $this->distinct($edges), $invocations, $impactFacts, $catalogFacts);
    }

    /**
     * Drop self-references and repeated edges within one file.
     *
     * This used to happen once over the whole project, but the key includes the path, so
     * two files could never collide: the work was always per file. Doing it here means a
     * restored contribution arrives clean and a cached run does not repeat it for every
     * file it did not touch.
     *
     * @param  array<int, DependencyEdge>  $edges
     * @return array<int, DependencyEdge>
     */
    private function distinct(array $edges): array
    {
        $unique = [];

        foreach ($edges as $edge) {
            if (strcasecmp($edge->from, $edge->to) === 0) {
                continue;
            }

            if ($this->preserveOccurrences) {
                $unique[] = $edge;
            } else {
                $unique[strtolower(implode('|', [$edge->from, $edge->to, $edge->path, (string) $edge->line, $edge->kind]))] = $edge;
            }
        }

        return array_values($unique);
    }

    /**
     * A stand-in symbol for a file that declares no class of its own.
     *
     * Pest writes tests as top-level `it(...)` calls and a route file is a script, so
     * without this their dependencies are invisible and every class they exercise looks
     * unused. Application files keep the previous behaviour: introducing symbols there
     * would change what the layer and cycle rules see.
     *
     * @param  array<int, Node>  $nodes
     * @param  array<int, ProjectSymbol>  $symbols
     */
    private function fileSymbol(FileContext $file, array $nodes, array &$symbols): ?string
    {
        if (str_starts_with($file->path, 'app/') || $this->declaresClassLike($nodes)) {
            return null;
        }

        $name = '(file) '.$file->path;

        $symbols[] = new ProjectSymbol(
            name: $name,
            path: $file->path,
            line: 1,
            namespace: '',
            kind: 'file',
            // Never a layer of its own. Classifying by path would make
            // `routes/Actions/billing.php` an `application` symbol whose dependencies
            // then produce layer findings, which is not what a stand-in is for. Both
            // roles used here are permissive sources in LayerPolicy.
            role: RoleClassifier::isTestPath($file->path) ? RoleClassifier::TEST : 'unknown',
            hasMethods: false,
        );

        return $name;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function declaresClassLike(array $nodes): bool
    {
        return PhpAst::containsAny(
            $nodes,
            static fn (Node $node): bool => $node instanceof Stmt\ClassLike && $node->name !== null,
        );
    }

    public function finish(): ProjectGraphSnapshot
    {
        $symbols = $this->symbols;
        $edges = $this->edges;

        return new ProjectGraphSnapshot(
            $this->sorted($symbols, static fn (ProjectSymbol $symbol): string => self::key($symbol->name, $symbol->path, $symbol->line)),
            $this->sorted($edges, static fn (DependencyEdge $edge): string => self::key($edge->from, $edge->to, $edge->path, $edge->line, $edge->kind)),
            $this->testInvocations,
            $this->impactFacts,
            $this->catalogFacts,
        );
    }

    /**
     * Order by a precomputed key rather than by comparing arrays in a callback.
     *
     * On a large application this is the difference between 0.61s and 0.09s for the
     * edges alone, which only became worth doing once a cached run stopped paying for
     * the parse that used to hide it. The order is unchanged: numbers are zero-padded so
     * they still compare numerically, and the separator sorts below every character that
     * can appear in a name.
     *
     * @template T of ProjectSymbol|DependencyEdge
     *
     * @param  array<int, T>  $items
     * @param  callable(T): string  $key
     * @return array<int, T>
     */
    private function sorted(array $items, callable $key): array
    {
        $keys = [];

        foreach ($items as $index => $item) {
            $keys[$index] = $key($item);
        }

        asort($keys, SORT_STRING);

        $sorted = [];

        foreach (array_keys($keys) as $index) {
            $sorted[] = $items[$index];
        }

        return $sorted;
    }

    private static function key(string|int ...$parts): string
    {
        $key = [];

        foreach ($parts as $part) {
            $key[] = is_int($part) ? str_pad((string) $part, 10, '0', STR_PAD_LEFT) : $part;
        }

        return implode("\0", $key);
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
                    role: $this->roles->describe($file->path, $source, $kind, $hasMethods)['role'],
                    hasMethods: $node->getMethods() !== [],
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
