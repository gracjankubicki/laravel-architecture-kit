<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Enums;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;
use Taqie\ArchitectureKit\Support\PhpAst;

final readonly class EnumsRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private array $enabled,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::Enums, $enabled, true);
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->ruleInEnumConstantLines($nodes) as $line) {
            $findings[] = $this->finding('warn', $file->path, $line, 'Finite request values should use backed enums and Rule::enum().');
        }

        if (str_starts_with($file->path, 'app/Models/')) {
            foreach ($this->modelEnumConstantLines($nodes) as $line) {
                $findings[] = $this->finding('warn', $file->path, $line, 'Finite model type sets should be backed enums with Eloquent casts.');
            }

            array_push($findings, ...$this->missingEnumCastFindings($file->path, $nodes));
        }

        if (
            in_array(Architecture::ApiResources, $this->enabled, true)
            && str_starts_with($file->path, 'app/Http/Resources/')
        ) {
            foreach ($this->rawEnumLikeApiResourceLines($nodes) as $line) {
                $findings[] = $this->finding(
                    'warn',
                    $file->path,
                    $line,
                    'Human-facing API Resources should expose enum-like status/type fields as value + label objects.',
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function missingEnumCastFindings(string $path, array $nodes): array
    {
        $classNode = $this->firstClass($nodes);
        $class = $classNode?->name?->toString();

        if ($class === null) {
            return [];
        }

        $attributes = $this->enumLikeModelAttributes($nodes);
        $findings = [];

        foreach ($attributes as $attribute => $line) {
            $enum = $this->matchingEnumClass($class, $attribute);

            if ($enum === null || $this->modelCastsAttributeToEnum($nodes, $attribute, $enum)) {
                continue;
            }

            $findings[] = $this->finding(
                'warn',
                $path,
                $line,
                "Model attribute '{$attribute}' looks enum-like and {$enum} exists; add an Eloquent enum cast when the column stores that enum.",
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<string, int>
     */
    private function enumLikeModelAttributes(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<string, int>
             */
            public array $attributes = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\Property) {
                    return null;
                }

                foreach ($node->props as $property) {
                    if ($property->name->toString() !== 'fillable' || ! $property->default instanceof Node\Expr\Array_) {
                        continue;
                    }

                    foreach ($property->default->items as $item) {
                        if (! $item instanceof Node\Expr\ArrayItem || ! $item->value instanceof Node\Scalar\String_) {
                            continue;
                        }

                        $attribute = $item->value->value;

                        if ($this->isEnumLikeAttribute($attribute)) {
                            $this->state->attributes[$attribute] = $item->getStartLine();
                        }
                    }
                }

                return null;
            }

            private function isEnumLikeAttribute(string $attribute): bool
            {
                return in_array($attribute, ['status', 'state', 'type', 'category'], true)
                    || str_ends_with($attribute, '_status')
                    || str_ends_with($attribute, '_state')
                    || str_ends_with($attribute, '_type')
                    || str_ends_with($attribute, '_category');
            }
        });

        return $state->attributes;
    }

    private function matchingEnumClass(string $modelClass, string $attribute): ?string
    {
        $suffix = Str::studly((string) Str::afterLast($attribute, '_'));
        $candidates = array_values(array_unique([
            $modelClass.$suffix,
            Str::studly($attribute),
        ]));

        foreach ($candidates as $candidate) {
            if ($this->enumClassExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function enumClassExists(string $class): bool
    {
        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return false;
        }

        foreach ($this->files->allFiles($this->basePath.'/app') as $file) {
            if ($file->getExtension() !== 'php' || $file->getBasename('.php') !== $class) {
                continue;
            }

            $path = $this->relative($file->getPathname());

            if (str_contains($path, '/Enums/') || str_starts_with($path, 'app/Enums/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function modelCastsAttributeToEnum(array $nodes, string $attribute, string $enum): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Node\Expr\ArrayItem
                && $node->key instanceof Node\Scalar\String_
                && $node->key->value === $attribute
                && $node->value instanceof Node\Expr\ClassConstFetch
                && $node->value->class instanceof Name
                && $this->shortTypeName($node->value->class->toString()) === $enum
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function ruleInEnumConstantLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Node\Expr\StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'Rule'
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'in'
                    && PhpAst::contains($node, fn (Node $child): bool => $this->isEnumSetClassConstFetch($child))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isEnumSetClassConstFetch(Node $node): bool
            {
                return $node instanceof Node\Expr\ClassConstFetch
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['TYPES', 'STATUSES'], true);
            }
        });

        return array_values(array_unique($state->lines));
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function modelEnumConstantLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassConst || ! $node->isPublic()) {
                    return null;
                }

                foreach ($node->consts as $const) {
                    $name = $const->name->toString();

                    if ($name === 'TYPES' || $name === 'STATUSES' || str_starts_with($name, 'STATUS_')) {
                        $this->state->lines[] = $const->getStartLine();
                    }
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function rawEnumLikeApiResourceLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Expr\ArrayItem || ! $node->key instanceof Node\Scalar\String_) {
                    return null;
                }

                $key = $node->key->value;

                if (! $this->isEnumLikeAttribute($key) || ! $this->isRawEnumLikeValue($node->value)) {
                    return null;
                }

                $this->state->lines[] = $node->getStartLine();

                return null;
            }

            private function isEnumLikeAttribute(string $attribute): bool
            {
                return $attribute === 'status'
                    || $attribute === 'state'
                    || $attribute === 'type'
                    || $attribute === 'category'
                    || str_ends_with($attribute, '_status')
                    || str_ends_with($attribute, '_state')
                    || str_ends_with($attribute, '_type')
                    || str_ends_with($attribute, '_category');
            }

            private function isRawEnumLikeValue(Node $node): bool
            {
                if ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) {
                    if (! $node->name instanceof Node\Identifier) {
                        return false;
                    }

                    $property = $node->name->toString();

                    return $this->isEnumLikeAttribute($property)
                        && ! $this->endsWithValueProperty($node);
                }

                return false;
            }

            private function endsWithValueProperty(Node $node): bool
            {
                return ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'value';
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClass(array $nodes): ?Stmt\Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_) {
                return $node;
            }

            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Stmt\Class_) {
                        return $statement;
                    }
                }
            }
        }

        return null;
    }

    private function shortTypeName(string $name): string
    {
        $parts = explode('\\', $name);

        return $parts[count($parts) - 1];
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), DIRECTORY_SEPARATOR);
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'enums', $path, $line, $message);
    }
}
