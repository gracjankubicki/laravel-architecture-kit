<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Fortify;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Bounded source lookup for Fortify contracts. It never autoloads application code. */
final class FortifySourceResolver
{
    /** @var array<string, array{name: string, file: FileContext, node: Node\Stmt\ClassLike}|null> */
    private array $classes = [];

    private int $sourceBytes = 0;

    /** @var array<string, string> */
    private array $unavailable = [];

    /** @var array<string, string>|null */
    private ?array $psr4 = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * @param  array<string, string>  $contracts
     */
    public function fileMatches(FileContext $file, array $contracts): bool
    {
        foreach ($this->classesIn($file) as $source) {
            foreach ($contracts as $contract => $method) {
                if (
                    $this->implementsContract($source, $contract)
                    && $this->methodStatus($source, $method) === 'public'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{status: 'valid'|'source_unavailable'|'contract_mismatch'|'method_missing'|'method_not_public', reason?: string}
     */
    public function check(string $class, string $contract, string $method): array
    {
        $source = $this->source($class);

        if ($source === null) {
            return [
                'status' => 'source_unavailable',
                'reason' => $this->unavailable[strtolower(ltrim($class, '\\'))] ?? 'Application source is unavailable for '.$class.'.',
            ];
        }

        if (! $this->implementsContract($source, $contract)) {
            return ['status' => 'contract_mismatch'];
        }

        $methodStatus = $this->methodStatus($source, $method);

        return match ($methodStatus) {
            'public' => ['status' => 'valid'],
            'non_public' => ['status' => 'method_not_public'],
            default => ['status' => 'method_missing'],
        };
    }

    /**
     * @param  array{name: string, file: FileContext, node: Node\Stmt\ClassLike}  $source
     */
    private function implementsContract(array $source, string $contract, int $depth = 0): bool
    {
        if ($depth >= 12 || strcasecmp($source['name'], $contract) === 0) {
            return strcasecmp($source['name'], $contract) === 0;
        }

        $interfaces = match (true) {
            $source['node'] instanceof Node\Stmt\Class_ => $source['node']->implements,
            $source['node'] instanceof Node\Stmt\Enum_ => $source['node']->implements,
            $source['node'] instanceof Node\Stmt\Interface_ => $source['node']->extends,
            default => [],
        };

        foreach ($interfaces as $interface) {
            $name = $source['file']->resolvedName($interface);

            if (strcasecmp($name, $contract) === 0) {
                return true;
            }

            $parent = $this->source($name);
            if ($parent !== null && $this->implementsContract($parent, $contract, $depth + 1)) {
                return true;
            }
        }

        if ($source['node'] instanceof Node\Stmt\Class_ && $source['node']->extends !== null) {
            $parent = $this->source($source['file']->resolvedName($source['node']->extends));

            return $parent !== null && $this->implementsContract($parent, $contract, $depth + 1);
        }

        return false;
    }

    /**
     * @param  array{name: string, file: FileContext, node: Node\Stmt\ClassLike}  $source
     * @return 'public'|'non_public'|'missing'
     */
    private function methodStatus(array $source, string $method, int $depth = 0): string
    {
        if ($depth >= 12) {
            return 'missing';
        }

        $classMethod = $source['node']->getMethod($method);
        if ($classMethod !== null) {
            return $classMethod->isPublic() ? 'public' : 'non_public';
        }

        $traitUses = $source['node'] instanceof Node\Stmt\Class_ || $source['node'] instanceof Node\Stmt\Trait_
            ? $source['node']->getTraitUses()
            : [];

        foreach ($traitUses as $traitUse) {
            if ($traitUse->adaptations !== []) {
                continue;
            }

            foreach ($traitUse->traits as $trait) {
                $traitSource = $this->source($source['file']->resolvedName($trait));
                if ($traitSource === null) {
                    continue;
                }

                $status = $this->methodStatus($traitSource, $method, $depth + 1);
                if ($status !== 'missing') {
                    return $status;
                }
            }
        }

        if ($source['node'] instanceof Node\Stmt\Class_ && $source['node']->extends !== null) {
            $parent = $this->source($source['file']->resolvedName($source['node']->extends));
            if ($parent !== null) {
                return $this->methodStatus($parent, $method, $depth + 1);
            }
        }

        return 'missing';
    }

    /**
     * @return array{name: string, file: FileContext, node: Node\Stmt\ClassLike}|null
     */
    private function source(string $class): ?array
    {
        $class = ltrim($class, '\\');
        $key = strtolower($class);

        if (array_key_exists($key, $this->classes)) {
            return $this->classes[$key];
        }

        $this->classes[$key] = null;
        $path = $this->pathFor($class);

        if ($path === null || ! $this->files->isFile($path)) {
            $this->unavailable[$key] = 'Application source is unavailable for '.$class.'.';

            return null;
        }

        $size = $this->files->size($path);
        if ($size > 100_000 || $this->sourceBytes + $size > 1_000_000) {
            $this->unavailable[$key] = 'Source budget exceeded for '.$class.'.';

            return null;
        }

        $this->sourceBytes += $size;
        $relative = ltrim(substr($path, strlen(rtrim($this->basePath, '/'))), '/');
        $file = new FileContext($relative, $this->files->get($path));

        foreach ($this->classesIn($file) as $source) {
            $this->classes[strtolower($source['name'])] = $source;
        }

        return $this->classes[$key];
    }

    /**
     * @return list<array{name: string, file: FileContext, node: Node\Stmt\ClassLike}>
     */
    private function classesIn(FileContext $file): array
    {
        $classes = [];

        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Node\Stmt\ClassLike::class) as $node) {
            if (! isset($node->namespacedName)) {
                continue;
            }

            $classes[] = [
                'name' => $node->namespacedName->toString(),
                'file' => $file,
                'node' => $node,
            ];
        }

        return $classes;
    }

    private function pathFor(string $class): ?string
    {
        foreach ($this->psr4() as $prefix => $directory) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            return $this->basePath.'/'.trim($directory, '/').'/'.$relative;
        }

        return null;
    }

    /** @return array<string, string> */
    private function psr4(): array
    {
        if ($this->psr4 !== null) {
            return $this->psr4;
        }

        $this->psr4 = ['App\\' => 'app'];
        $path = $this->basePath.'/composer.json';
        if (! $this->files->isFile($path)) {
            return $this->psr4;
        }

        $composer = json_decode($this->files->get($path), true);
        if (! is_array($composer)) {
            return $this->psr4;
        }

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (($composer[$section]['psr-4'] ?? []) as $prefix => $directories) {
                $directory = is_array($directories) ? ($directories[0] ?? null) : $directories;
                if (is_string($prefix) && is_string($directory)) {
                    $this->psr4[$prefix] = $directory;
                }
            }
        }

        uksort($this->psr4, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $this->psr4;
    }
}
