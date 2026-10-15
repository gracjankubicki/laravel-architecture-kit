<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Filesystem\Filesystem;

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

    /** @var \WeakMap<FileContext, array<string, Node>> */
    private \WeakMap $declarations;

    public function __construct(
        private readonly Filesystem $files = new Filesystem,
        private readonly ?string $basePath = null,
        private readonly ?AiRelationshipCache $relationships = null,
    ) {
        $this->declarations = new \WeakMap;
    }

    public function isAgent(string $class, FileContext $current): bool
    {
        return $this->resolveAgent($class, $current)->isAgent();
    }

    public function resolveAgent(string $class, FileContext $current): AiRelation
    {
        return $this->sharedRelation(
            ltrim($class, '\\'),
            $current,
            'Laravel\\Ai\\Contracts\\Agent',
            'Laravel\\Ai\\Promptable',
            $this->agentCache,
        );
    }

    public function isTool(string $class, FileContext $current): bool
    {
        return $this->sharedRelation(
            ltrim($class, '\\'),
            $current,
            'Laravel\\Ai\\Contracts\\Tool',
            null,
            $this->toolCache,
        )->isAgent();
    }

    /** @param array<string, AiRelation> $cache */
    private function sharedRelation(string $class, FileContext $current, string $contract, ?string $trait, array &$cache): AiRelation
    {
        // A declaration in the current file takes precedence over Composer mapping.
        // Only root queries are reused; a nested lookup keeps its depth/cycle budget.
        $location = $this->classLike($current, $class) === null ? '@mapped' : $current->path;
        $key = implode("\0", [$class, $contract, $trait ?? '', $location]);
        $known = $this->relationships?->get($key);
        if ($known !== null) {
            return $known === 'agent' ? AiRelation::agent() : AiRelation::other();
        }
        $result = $this->resolvesTo($class, $current, $contract, $trait, $cache);
        if (! $result->isIncomplete()) {
            $this->relationships?->put($key, $result->state);
        }

        return $result;
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

            if ($context->syntax() === null) {
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

    private function classLike(FileContext $context, string $class): ?Node
    {
        if (isset($this->declarations[$context])) {
            return $this->declarations[$context][$class] ?? null;
        }
        $nodes = $context->syntax();

        if ($nodes === null) {
            return null;
        }

        $declarations = [];
        foreach ((new NodeFinder)->findOfKind($nodes, 'statement.class-like') as $node) {
            if ($node->name instanceof Node) {
                $name = $node->namespacedName ?? null;
                $resolved = Node::is($name, 'name') ? $name->toString() : $node->name->toString();
                $declarations[$resolved] ??= $node;
            }
        }
        $this->declarations[$context] = $declarations;

        return $declarations[$class] ?? null;
    }

    /** @return array<int, string> */
    private function relatedTypes(FileContext $context, Node $class): array
    {
        $types = [];

        if (Node::is($class, 'statement.class') && Node::is($class->extends, 'name')) {
            $types[] = $context->resolvedName($class->extends);
        }

        $implements = Node::is($class, 'statement.class') ? $class->implements : [];

        if (Node::is($class, 'statement.interface')) {
            $implements = $class->extends;
        }

        foreach ($implements as $type) {
            $types[] = $context->resolvedName($type);
        }

        foreach ($class->stmts as $statement) {
            if (! Node::is($statement, 'statement.trait-use')) {
                continue;
            }

            foreach ($statement->traits as $type) {
                $types[] = $context->resolvedName($type);
            }
        }

        if (Node::is($class, 'statement.trait')) {
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
