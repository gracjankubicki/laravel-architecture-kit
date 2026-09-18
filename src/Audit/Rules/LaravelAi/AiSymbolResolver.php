<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

final class AiSymbolResolver
{
    private const MAX_DEPTH = 12;

    private const MAX_FILE_BYTES = 102_400;

    private const MAX_ANALYSIS_BYTES = 1_048_576;

    /** @var array<string, AiRelation> */
    private array $agentCache = [];

    /** @var array<string, AiRelation> */
    private array $toolCache = [];

    /** @var array<string, array<int, string>>|null */
    private ?array $psr4 = null;

    private int $analysedBytes = 0;

    public function __construct(
        private readonly Filesystem $files = new Filesystem,
        private readonly ?string $basePath = null,
    ) {}

    public function isAgent(string $class, FileContext $current): bool
    {
        return $this->resolveAgent($class, $current)->isAgent();
    }

    public function resolveAgent(string $class, FileContext $current): AiRelation
    {
        return $this->resolvesTo(
            ltrim($class, '\\'),
            $current,
            'Laravel\\Ai\\Contracts\\Agent',
            'Laravel\\Ai\\Promptable',
            $this->agentCache,
        );
    }

    public function isTool(string $class, FileContext $current): bool
    {
        return $this->resolvesTo(
            ltrim($class, '\\'),
            $current,
            'Laravel\\Ai\\Contracts\\Tool',
            null,
            $this->toolCache,
        )->isAgent();
    }

    /**
     * @param  array<string, AiRelation>  $cache
     * @param  array<string, true>  $visiting
     */
    private function resolvesTo(
        string $class,
        FileContext $current,
        string $contract,
        ?string $trait,
        array &$cache,
        int $depth = 0,
        array $visiting = [],
    ): AiRelation {
        if ($class === $contract || ($trait !== null && $class === $trait)) {
            return AiRelation::agent();
        }

        if ($depth >= self::MAX_DEPTH) {
            return AiRelation::incomplete(sprintf('The relationship for %s exceeds the analysis depth limit.', $class));
        }

        if (isset($visiting[$class])) {
            return AiRelation::incomplete(sprintf('The relationship for %s contains a cycle.', $class));
        }

        if (array_key_exists($class, $cache)) {
            return $cache[$class];
        }

        $visiting[$class] = true;
        $context = $this->contextFor($class, $current);

        if (! $context->isAgent()) {
            return $cache[$class] = $context;
        }

        $source = $context->context;

        if ($source === null) {
            return $cache[$class] = AiRelation::incomplete(sprintf('The source context for %s is unavailable.', $class));
        }
        $classLike = $this->classLike($source, $class);

        if ($classLike === null) {
            return $cache[$class] = AiRelation::incomplete(sprintf('The source for %s does not contain a readable declaration.', $class));
        }

        $incomplete = null;

        foreach ($this->relatedTypes($source, $classLike) as $related) {
            if ($related === $contract || ($trait !== null && $related === $trait)) {
                return $cache[$class] = AiRelation::agent();
            }

            $resolution = $this->resolvesTo($related, $source, $contract, $trait, $cache, $depth + 1, $visiting);

            if ($resolution->isAgent()) {
                return $cache[$class] = $resolution;
            }

            if ($resolution->isIncomplete()) {
                $incomplete ??= $resolution;
            }
        }

        return $cache[$class] = $incomplete ?? AiRelation::other();
    }

    private function contextFor(string $class, FileContext $current): AiRelation
    {
        if ($this->classLike($current, $class) !== null) {
            return AiRelation::agent($current);
        }

        $paths = $this->sourcePaths($class);

        if ($paths === []) {
            return AiRelation::other();
        }

        $failure = null;

        foreach ($paths as $path) {
            if (! $this->files->isFile($path)) {
                $failure ??= sprintf('The mapped source for %s is unavailable.', $class);

                continue;
            }

            $size = $this->files->size($path);

            if ($size > self::MAX_FILE_BYTES) {
                $failure ??= sprintf('The source for %s exceeds the per-file analysis limit.', $class);

                continue;
            }

            if ($this->analysedBytes + $size > self::MAX_ANALYSIS_BYTES) {
                $failure ??= sprintf('Resolving %s exceeds the total source analysis budget.', $class);

                continue;
            }

            $this->analysedBytes += $size;
            $context = new FileContext($this->relativePath($path), $this->files->get($path));

            if ($context->ast() === null) {
                $failure ??= sprintf('The source for %s could not be parsed.', $class);

                continue;
            }

            if ($this->classLike($context, $class) !== null) {
                return AiRelation::agent($context);
            }

            $failure ??= sprintf('The mapped source for %s does not declare that symbol.', $class);
        }

        return AiRelation::incomplete($failure);
    }

    private function classLike(FileContext $context, string $class): ?ClassLike
    {
        $nodes = $context->ast();

        if ($nodes === null) {
            return null;
        }

        foreach ($nodes as $node) {
            $match = $this->findClassLike($node, $class);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function findClassLike(Node $node, string $class): ?ClassLike
    {
        if ($node instanceof ClassLike && $node->name !== null) {
            $name = $node->namespacedName ?? null;
            $resolved = $name instanceof Node\Name ? $name->toString() : $node->name->toString();

            if ($resolved === $class) {
                return $node;
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $match = $this->findClassLike($value, $class);

                if ($match !== null) {
                    return $match;
                }
            }

            if (is_array($value)) {
                foreach ($value as $child) {
                    if (! $child instanceof Node) {
                        continue;
                    }

                    $match = $this->findClassLike($child, $class);

                    if ($match !== null) {
                        return $match;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function relatedTypes(FileContext $context, ClassLike $class): array
    {
        $types = [];

        if ($class instanceof Class_ && $class->extends instanceof Node\Name) {
            $types[] = $context->resolvedName($class->extends);
        }

        $implements = $class instanceof Class_ ? $class->implements : [];

        if ($class instanceof Interface_) {
            $implements = $class->extends;
        }

        foreach ($implements as $type) {
            $types[] = $context->resolvedName($type);
        }

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->traits as $type) {
                $types[] = $context->resolvedName($type);
            }
        }

        if ($class instanceof Trait_) {
            return array_values(array_unique($types));
        }

        return array_values(array_unique($types));
    }

    /** @return array<int, string> */
    private function sourcePaths(string $class): array
    {
        if ($this->basePath === null) {
            return [];
        }

        foreach ($this->psr4() as $prefix => $directories) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            return array_map(
                fn (string $directory): string => $this->basePath.'/'.trim($directory, '/').'/'.$relative,
                $directories,
            );
        }

        return [];
    }

    /** @return array<string, array<int, string>> */
    private function psr4(): array
    {
        if ($this->psr4 !== null) {
            return $this->psr4;
        }

        if ($this->basePath === null || ! $this->files->isFile($this->basePath.'/composer.json')) {
            return $this->psr4 = [];
        }

        $composer = json_decode($this->files->get($this->basePath.'/composer.json'), true);
        $mappings = is_array($composer) ? ($composer['autoload']['psr-4'] ?? []) : [];
        $this->psr4 = [];

        foreach (is_array($mappings) ? $mappings : [] as $prefix => $directories) {
            if (! is_string($prefix)) {
                continue;
            }

            $directories = is_string($directories) ? [$directories] : $directories;

            if (! is_array($directories)) {
                continue;
            }

            $directories = array_values(array_filter($directories, is_string(...)));

            if ($directories !== []) {
                $this->psr4[$prefix] = $directories;
            }
        }

        uksort($this->psr4, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $this->psr4;
    }

    private function relativePath(string $path): string
    {
        if ($this->basePath !== null && str_starts_with($path, $this->basePath.'/')) {
            return substr($path, strlen($this->basePath) + 1);
        }

        return $path;
    }
}

final readonly class AiRelation
{
    private function __construct(
        public string $state,
        public ?FileContext $context = null,
        public ?string $reason = null,
    ) {}

    public static function agent(?FileContext $context = null): self
    {
        return new self('agent', $context);
    }

    public static function other(): self
    {
        return new self('other');
    }

    public static function incomplete(string $reason): self
    {
        return new self('incomplete', reason: $reason);
    }

    public function isAgent(): bool
    {
        return $this->state === 'agent';
    }

    public function isIncomplete(): bool
    {
        return $this->state === 'incomplete';
    }
}
