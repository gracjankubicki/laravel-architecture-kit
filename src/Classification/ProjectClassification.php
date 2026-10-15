<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Classification;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/** One query-local classifier shared by file guidance and file audit rules. */
final class ProjectClassification
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $files = [];

    public readonly RoleClassifier $roles;

    private readonly bool $package;

    private ?AuditScope $scope;

    private readonly ProjectLayout $layout;

    /** @var list<string>|null Audit-local production paths, never vendor or test inputs. */
    private ?array $sourcePaths = null;

    public function __construct(private readonly Filesystem $filesystem, private readonly string $base, ?AuditScope $scope = null)
    {
        $this->roles = RoleClassifier::forProject($filesystem, $base);
        $layout = ProjectLayout::read($base);
        $this->layout = $layout;
        $this->package = $layout->isPackage();
        $this->scope = $scope;
    }

    public function ownsSource(string $path): bool
    {
        $this->scope ??= AuditScope::package($this->layout->sources());

        return $this->scope->covers($path) && ! RoleClassifier::isTestPath($path);
    }

    /** Resolve project namespaces without loading Composer's generated autoloader. */
    public function roleOfName(string $name): string
    {
        $name = ltrim($name, '\\');
        $mapping = $this->roles->mappings->roleMapping('', $name);
        if (isset($mapping['role'])) {
            return $mapping['role'];
        }
        $mappings = $this->layout->composer['autoload']['psr-4'] ?? ($this->package ? [] : ['App\\' => 'app']);
        $candidates = [];
        foreach ($mappings as $prefix => $roots) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            foreach (is_array($roots) ? $roots : [$roots] as $root) {
                $path = ProjectLayout::path($root).'/'.str_replace('\\', '/', substr($name, strlen($prefix))).'.php';
                if ($this->ownsSource($path) && DiscoverySettings::safe($this->base, $path) && $this->filesystem->isFile($this->base.'/'.$path)) {
                    $candidates[strlen($prefix)][] = $path;
                }
            }
        }
        if ($candidates === []) {
            return 'unknown';
        }
        $roles = [];
        foreach ($candidates[max(array_keys($candidates))] as $path) {
            foreach ($this->file($path) as $row) {
                if (strcasecmp($row['name'] ?? '', $name) === 0) {
                    $roles[] = $row['role'];
                }
            }
        }
        $roles = array_values(array_unique($roles));

        return count($roles) === 1 ? $roles[0] : 'unknown';
    }

    /** Shared bounded lookup roots for rules that inspect companion source files.
     * @return list<string>
     */
    public function sourcePaths(): array
    {
        if ($this->sourcePaths !== null) {
            return $this->sourcePaths;
        }
        $this->scope ??= AuditScope::package($this->layout->sources());
        $paths = [];
        foreach ($this->scope->directories as $root) {
            if (! DiscoverySettings::safe($this->base, $root)) {
                continue;
            }
            $absolute = $this->base.'/'.$root;
            $files = $this->filesystem->isFile($absolute) ? [$absolute]
                : ($this->filesystem->isDirectory($absolute) ? $this->filesystem->allFiles($absolute) : []);
            foreach ($files as $file) {
                $path = ProjectPath::relative($this->base, (string) $file);
                if (str_ends_with($path, '.php') && $this->ownsSource($path) && DiscoverySettings::safe($this->base, $path)) {
                    $paths[$path] = true;
                }
            }
        }

        return $this->sourcePaths = array_keys($paths);
    }

    public function prime(FileContext $file): void
    {
        $rows = [];
        $this->declarations($file->syntax() ?? [], $file->path, $rows);
        if ($rows === []) {
            $rows[] = ['name' => '(file) '.$file->path, ...$this->roles->describe($file->path, '', 'file'), 'declared_kind' => false];
        }
        $this->files[$file->path] = $rows;
    }

    /** Restore audit-local rows produced by a worker using the same configuration.
     * @param  list<array<string, mixed>>  $rows
     */
    public function restore(string $path, array $rows): void
    {
        if (! DiscoverySettings::safe($this->base, $path) || ! array_is_list($rows) || $rows === []) {
            throw new InvalidArgumentException('Invalid worker classification source.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['role'] ?? null)
                || ! in_array($row['php_kind'] ?? null, ['class', 'interface', 'trait', 'enum', 'file'], true)
                || ! is_bool($row['declared_kind'] ?? null)
                || (! is_null($row['application_kind'] ?? null) && ! is_string($row['application_kind']))
                || (! is_null($row['module'] ?? null) && ! is_string($row['module']))
                || ! is_array($row['module_parents'] ?? null) || ! array_is_list($row['module_parents'])
                || count(array_filter($row['module_parents'], 'is_string')) !== count($row['module_parents'])
                || ! is_array($row['provenance'] ?? null) || ! is_string($row['provenance']['classification'] ?? null)
                || ! is_string($row['provenance']['module'] ?? null)) {
                throw new InvalidArgumentException('Invalid worker classification row.');
            }
        }
        $this->files[$path] = $rows;
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
        if ($this->roles->mappings->roles === [] && ! $this->package) {
            return $default;
        }
        foreach ($this->file($path) as $row) {
            if (($row['declared_kind'] || $this->package) ? $row['application_kind'] === $kind : $default) {
                return true;
            }
        }

        return false;
    }

    /** Isolate declarations when namespace mappings differ inside one file.
     * @return list<FileContext>
     */
    public function views(FileContext $file, bool $byRole = false): array
    {
        if ($this->roles->mappings->roles === [] || count($this->file($file->path)) < 2 || (! $byRole && ! in_array(true, array_column($this->file($file->path), 'declared_kind'), true))) {
            return [$file];
        }
        $nodes = [];
        $this->classNodes($file->syntax() ?? [], $nodes);

        return array_map(static fn (array $nodes): FileContext => $file->withSyntax($nodes), $nodes);
    }

    /** @param list<Node> $nodes
     * @param  list<list<Node>>  $classes
     * @param  list<Node>  $imports
     */
    private function classNodes(array $nodes, array &$classes, ?Node $namespace = null, array $imports = []): void
    {
        $imports = [...$imports, ...array_values(array_filter($nodes, static fn ($node) => Node::is($node, 'statement.use') || Node::is($node, 'statement.group-use')))];
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.namespace')) {
                $this->classNodes($node->stmts, $classes, $node);

                continue;
            }
            if (Node::is($node, 'statement.class-like') && $node->name !== null) {
                $statements = [...$imports, $node];
                if ($namespace !== null) {
                    $view = $namespace->withFields(['stmts' => $statements]);
                    $statements = [$view];
                }
                $classes[] = $statements;

                continue;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $children = $node->$field;
                if (Node::is($children, 'node')) {
                    $this->classNodes([$children], $classes, $namespace, $imports);
                } elseif (is_array($children)) {
                    $this->classNodes(array_values(array_filter($children, static fn ($child) => Node::is($child, 'node'))), $classes, $namespace, $imports);
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
            if (Node::is($node, 'statement.class-like') && $node->name !== null) {
                $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                $kind = match (true) {
                    Node::is($node, 'statement.interface') => 'interface',
                    Node::is($node, 'statement.trait') => 'trait',
                    Node::is($node, 'statement.enum') => 'enum',
                    default => 'class',
                };
                $mapping = RoleClassifier::isTestPath($path) ? [] : $this->roles->mappings->roleMapping($path, $name);
                $rows[] = ['name' => $name, ...$this->roles->describe($path, $name, $kind, $node->getMethods() !== []), 'declared_kind' => isset($mapping['kind'])];

            }
            foreach ($node->getSubNodeNames() as $field) {
                $children = $node->$field;
                if (Node::is($children, 'node')) {
                    $this->declarations([$children], $path, $rows);
                } elseif (is_array($children)) {
                    $this->declarations(array_values(array_filter($children, static fn ($child) => Node::is($child, 'node'))), $path, $rows);
                }
            }
        }
    }
}
