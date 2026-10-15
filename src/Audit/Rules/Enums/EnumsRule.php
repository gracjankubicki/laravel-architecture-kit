<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Enums;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class EnumsRule implements PortableAuditRule
{
    /** @var array<string, true>|null Enum declarations, scoped to this audit's rule instance. */
    private ?array $enumNames = null;

    private readonly ProjectClassification $classification;

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        private readonly array $enabled,
        ?ProjectClassification $classification = null,
    ) {
        $this->classification = $classification ?? new ProjectClassification($files, $basePath);
    }

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
        $nodes = $file->syntax();

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->ruleInEnumConstantLines($nodes) as $line) {
            $findings[] = $this->finding('warn', $file->path, $line, 'Finite request values should use backed enums and Rule::enum().');
        }

        if ($this->classification->kindMatches($file->path, 'model', str_starts_with($file->path, 'app/Models/'))) {
            foreach ($this->modelEnumConstantLines($nodes) as $line) {
                $findings[] = $this->finding('warn', $file->path, $line, 'Finite model type sets should be backed enums with Eloquent casts.');
            }

            array_push($findings, ...$this->missingEnumCastFindings($file->path, $nodes));
        }

        if (
            in_array(Architecture::ApiResources, $this->enabled, true)
            && $this->classification->kindMatches($file->path, 'resource', str_starts_with($file->path, 'app/Http/Resources/'))
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
                if (! Node::is($node, 'statement.property')) {
                    return null;
                }

                foreach ($node->props as $property) {
                    if ($property->name->toString() !== 'fillable' || ! Node::is($property->default, 'expression.array')) {
                        continue;
                    }

                    foreach ($property->default->items as $item) {
                        if (! Node::is($item, 'array-item') || ! Node::is($item->value, 'scalar.string')) {
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
        if ($this->enumNames === null) {
            $this->enumNames = [];
            foreach ($this->classification->sourcePaths() as $path) {
                if (! $this->classification->kindMatches($path, 'enum', str_contains($path, '/Enums/'))) {
                    continue;
                }
                foreach ($this->classification->file($path) as $row) {
                    if ($row['php_kind'] === 'enum') {
                        $this->enumNames[Str::afterLast($row['name'], '\\')] = true;
                    }
                }
            }
        }

        return isset($this->enumNames[$class]);
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function modelCastsAttributeToEnum(array $nodes, string $attribute, string $enum): bool
    {
        return PhpAst::containsAny(
            $nodes,
            fn (Node $node): bool => Node::is($node, 'array-item')
                && Node::is($node->key, 'scalar.string')
                && $node->key->value === $attribute
                && Node::is($node->value, 'expression.class-const-fetch')
                && Node::is($node->value->class, 'name')
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
                    Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && $node->class->toString() === 'Rule'
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'in'
                    && PhpAst::contains($node, fn (Node $child): bool => $this->isEnumSetClassConstFetch($child))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isEnumSetClassConstFetch(Node $node): bool
            {
                return Node::is($node, 'expression.class-const-fetch')
                    && Node::is($node->name, 'identifier')
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
                if (! Node::is($node, 'statement.class-const') || ! $node->isPublic()) {
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
                if (! Node::is($node, 'array-item') || ! Node::is($node->key, 'scalar.string')) {
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
                if (Node::is($node, 'expression.property-fetch') || Node::is($node, 'expression.nullsafe-property-fetch')) {
                    if (! Node::is($node->name, 'identifier')) {
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
                return (Node::is($node, 'expression.property-fetch') || Node::is($node, 'expression.nullsafe-property-fetch'))
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'value';
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClass(array $nodes): ?Node
    {
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.class')) {
                return $node;
            }

            if (Node::is($node, 'statement.namespace')) {
                foreach ($node->stmts as $statement) {
                    if (Node::is($statement, 'statement.class')) {
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

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'enums', $path, $line, $message);
    }
}
