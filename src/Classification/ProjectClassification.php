<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Classification;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/** One query-local classifier shared by file guidance and file audit rules. */
final class ProjectClassification
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $files = [];

    public readonly RoleClassifier $roles;

    public function __construct(private readonly Filesystem $filesystem, private readonly string $base)
    {
        $this->roles = RoleClassifier::forProject($filesystem, $base);
    }

    public function prime(FileContext $file): void
    {
        $rows = [];
        $this->declarations($file->ast() ?? [], $file->path, $rows);
        if ($rows === []) {
            $rows[] = ['name' => '(file) '.$file->path, ...$this->roles->describe($file->path, '', 'file'), 'declared_kind' => false];
        }
        $this->files[$file->path] = $rows;
    }

    /** @return list<array<string, mixed>> */
    public function file(string $path): array
    {
        if (isset($this->files[$path])) {
            return $this->files[$path];
        }
        if (! DiscoverySettings::safe($this->base, $path)) {
            throw new InvalidArgumentException('Unsafe classification source path.');
        }
        if ($this->filesystem->isFile($this->base.'/'.$path)) {
            if (($reason = ImpactExtractor::sourceLimit($this->filesystem->size($this->base.'/'.$path))) !== null) {
                throw new InvalidArgumentException($reason);
            }
            $file = new FileContext($path, $this->filesystem->get($this->base.'/'.$path));
            $this->prime($file);
            $file->releaseAst();
        } else {
            // A future file has no declared namespace. Path declarations still apply.
            $mapping = $this->roles->mappings->roleMapping($path, '');
            $this->files[$path] = [['name' => null, ...$this->roles->describe($path, '', 'class'), 'declared_kind' => isset($mapping['kind'])]];
        }

        return $this->files[$path];
    }

    public function kindMatches(string $path, string $kind, bool $default): bool
    {
        if ($this->roles->mappings->roles === []) {
            return $default;
        }
        foreach ($this->file($path) as $row) {
            if ($row['declared_kind'] ? $row['application_kind'] === $kind : $default) {
                return true;
            }
        }

        return false;
    }

    /** Isolate declarations when namespace mappings differ inside one file.
     * @return list<FileContext>
     */
    public function views(FileContext $file): array
    {
        if ($this->roles->mappings->roles === [] || count($this->file($file->path)) < 2 || ! in_array(true, array_column($this->file($file->path), 'declared_kind'), true)) {
            return [$file];
        }
        $nodes = [];
        $this->classNodes($file->ast() ?? [], $nodes);

        return array_map(static fn (array $nodes): FileContext => $file->withAst($nodes), $nodes);
    }

    /** @param list<Node> $nodes
     * @param  list<list<Node>>  $classes
     * @param  list<Stmt>  $imports
     */
    private function classNodes(array $nodes, array &$classes, ?Stmt\Namespace_ $namespace = null, array $imports = []): void
    {
        $imports = [...$imports, ...array_values(array_filter($nodes, static fn ($node) => $node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse))];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $this->classNodes($node->stmts, $classes, $node);

                continue;
            }
            if ($node instanceof Stmt\ClassLike && $node->name !== null) {
                $statements = [...$imports, $node];
                if ($namespace !== null) {
                    $view = clone $namespace;
                    $view->stmts = $statements;
                    $statements = [$view];
                }
                $classes[] = $statements;

                continue;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $children = $node->$field;
                if ($children instanceof Node) {
                    $this->classNodes([$children], $classes, $namespace, $imports);
                } elseif (is_array($children)) {
                    $this->classNodes(array_values(array_filter($children, static fn ($child) => $child instanceof Node)), $classes, $namespace, $imports);
                }
            }
        }
    }

    /** @param list<Node> $nodes
     * @param  list<array<string, mixed>>  $rows
     */
    private function declarations(array $nodes, string $path, array &$rows): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\ClassLike && $node->name !== null) {
                $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                $kind = match (true) {
                    $node instanceof Stmt\Interface_ => 'interface',
                    $node instanceof Stmt\Trait_ => 'trait',
                    $node instanceof Stmt\Enum_ => 'enum',
                    default => 'class',
                };
                $mapping = RoleClassifier::isTestPath($path) ? [] : $this->roles->mappings->roleMapping($path, $name);
                $rows[] = ['name' => $name, ...$this->roles->describe($path, $name, $kind, $node->getMethods() !== []), 'declared_kind' => isset($mapping['kind'])];

            }
            foreach ($node->getSubNodeNames() as $field) {
                $children = $node->$field;
                if ($children instanceof Node) {
                    $this->declarations([$children], $path, $rows);
                } elseif (is_array($children)) {
                    $this->declarations(array_values(array_filter($children, static fn ($child) => $child instanceof Node)), $path, $rows);
                }
            }
        }
    }
}
