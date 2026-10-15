<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use Illuminate\Filesystem\Filesystem;

final class LifecycleAst
{
    private readonly ProjectClassification $classification;

    /** @var array<string, bool>|null Model declarations for this audit only. */
    private ?array $observedModels = null;

    /**
     * @var array<int, string>
     */
    public const BEFORE_METHODS = [
        'creating',
        'saving',
        'updating',
        'deleting',
        'restoring',
    ];

    /**
     * @var array<int, string>
     */
    public const AFTER_METHODS = [
        'created',
        'saved',
        'updated',
        'deleted',
        'restored',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        ?ProjectClassification $classification = null,
    ) {
        $this->classification = $classification ?? new ProjectClassification($files, $basePath);
    }

    public function isProviderPath(string $path): bool
    {
        return $this->classification->kindMatches($path, 'provider', str_starts_with($path, 'app/Providers/'));
    }

    public function isObserverPath(string $path): bool
    {
        return str_ends_with($path, 'Observer.php')
            && (str_starts_with($path, 'app/Observers/') || str_contains($path, '/Observers/'));
    }

    public function isLifecyclePath(string $path): bool
    {
        return str_starts_with($path, 'app/Lifecycle/')
            || str_contains($path, '/Lifecycle/');
    }

    public function looksLikeLifecycleHandler(string $className, Node $class): bool
    {
        if (
            str_ends_with($className, 'Data')
            || str_ends_with($className, 'Dto')
            || str_ends_with($className, 'DTO')
            || str_ends_with($className, 'Result')
            || str_ends_with($className, 'Resource')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Exception')
            || str_ends_with($className, 'Failure')
            || str_ends_with($className, 'Status')
        ) {
            return false;
        }

        if (! $class->isFinal()) {
            return false;
        }

        $handleMethods = array_filter(
            $class->getMethods(),
            fn (Node $method): bool => $method->isPublic() && $method->name->toString() === 'handle',
        );

        return count($handleMethods) === 1;
    }

    /**
     * @return array<int, Node>
     */
    public function lifecycleMethods(FileContext $file): array
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, Node>
             */
            public array $methods = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    Node::is($node, 'statement.class-method')
                    && $node->isPublic()
                    && in_array($node->name->toString(), [...LifecycleAst::BEFORE_METHODS, ...LifecycleAst::AFTER_METHODS], true)
                ) {
                    $this->state->methods[] = $node;
                }

                return null;
            }
        });

        return $state->methods;
    }

    public function isSingleNamedEventDispatch(Node $method): bool
    {
        if (count($method->stmts ?? []) !== 1) {
            return false;
        }

        $statement = $method->stmts[0];

        if (! Node::is($statement, 'statement.expression')) {
            return false;
        }

        $expr = $statement->expr;

        if (
            Node::is($expr, 'expression.func-call')
            && Node::is($expr->name, 'name')
            && $expr->name->toString() === 'event'
            && Node::is(($expr->args[0]->value ?? null), 'expression.new')
        ) {
            return true;
        }

        if (
            ! Node::is($expr, 'expression.static-call')
            || ! Node::is($expr->class, 'name')
            || ! Node::is($expr->name, 'identifier')
            || $expr->name->toString() !== 'dispatch'
        ) {
            return false;
        }

        $resolved = $expr->class->getAttribute('resolvedName');
        $class = Node::is($resolved, 'name') ? $resolved->toString() : $expr->class->toString();

        return str_starts_with($class, 'App\\Events\\');
    }

    public function isTechnicalLifecycleCondition(Node $condition): bool
    {
        if (Node::is($condition, 'expression.boolean-not')) {
            return $this->isTechnicalLifecycleCondition($condition->expr);
        }

        if (Node::is($condition, 'expression.binary-op.boolean-and') || Node::is($condition, 'expression.binary-op.boolean-or')) {
            return $this->isTechnicalLifecycleCondition($condition->left)
                && $this->isTechnicalLifecycleCondition($condition->right);
        }

        if (
            Node::is($condition, 'expression.binary-op.identical')
            || Node::is($condition, 'expression.binary-op.not-identical')
            || Node::is($condition, 'expression.binary-op.equal')
            || Node::is($condition, 'expression.binary-op.not-equal')
        ) {
            if (Node::is($condition->left, 'expression.const-fetch') && strtolower($condition->left->name->toString()) === 'null') {
                return $this->isNullComparable($condition->right);
            }

            if (Node::is($condition->right, 'expression.const-fetch') && strtolower($condition->right->name->toString()) === 'null') {
                return $this->isNullComparable($condition->left);
            }

            return $this->isTechnicalLifecycleCondition($condition->left)
                && $this->isTechnicalLifecycleCondition($condition->right);
        }

        if (Node::is($condition, 'expression.method-call') && Node::is($condition->name, 'identifier')) {
            return in_array($condition->name->toString(), ['isDirty', 'wasChanged', 'getDirty', 'getChanges'], true);
        }

        if (Node::is($condition, 'expression.property-fetch') && Node::is($condition->name, 'identifier')) {
            return $condition->name->toString() === 'wasRecentlyCreated';
        }

        if (Node::is($condition, 'expression.const-fetch')) {
            return in_array(strtolower($condition->name->toString()), ['true', 'false', 'null'], true);
        }

        if (Node::is($condition, 'scalar')) {
            return false;
        }

        return false;
    }

    public function afterCommitObserverLine(FileContext $file): ?int
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return null;
        }

        $class = ClassInspector::firstClass($nodes);

        if (! Node::is($class, 'statement.class')) {
            return null;
        }

        foreach ($class->implements as $implemented) {
            $name = $implemented->toString();

            if ($name === 'ShouldHandleEventsAfterCommit' || str_ends_with($name, '\\ShouldHandleEventsAfterCommit')) {
                return $implemented->getStartLine();
            }
        }

        foreach ($class->stmts as $statement) {
            if (! Node::is($statement, 'statement.property')) {
                continue;
            }

            foreach ($statement->props as $property) {
                if (
                    $property->name->toString() === 'afterCommit'
                    && Node::is($property->default, 'expression.const-fetch')
                    && strtolower($property->default->name->toString()) === 'true'
                ) {
                    return $property->getStartLine();
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    public function significantCalls(Node $method): array
    {
        $ignored = [
            'getKey',
            'getChanges',
            'getOriginal',
            'getDirty',
            'isDirty',
            'wasChanged',
            'fromModel',
            'toString',
        ];

        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse([$method], new class($ignored, $state) extends NodeVisitorAbstract
        {
            /**
             * @param  array<int, string>  $ignored
             */
            public function __construct(
                private array $ignored,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                $name = null;

                if ((Node::is($node, 'expression.method-call') || Node::is($node, 'expression.static-call')) && Node::is($node->name, 'identifier')) {
                    $name = $node->name->toString();
                }

                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name')) {
                    $name = $node->name->toString();
                }

                if ($name !== null && ! in_array($name, $this->ignored, true)) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @return array<int, array{model: string, line: int}>
     */
    public function observerRegistrations(FileContext $file): array
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, array{model: string, line: int}>
             */
            public array $registrations = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'observe'
                ) {
                    $this->state->registrations[] = [
                        'model' => $this->resolvedName($node->class),
                        'line' => $node->getStartLine(),
                    ];
                }

                return null;
            }

            private function resolvedName(Node $name): string
            {
                $resolved = $name->getAttribute('resolvedName');

                return Node::is($resolved, 'name') ? $resolved->toString() : $name->toString();
            }
        });

        return $state->registrations;
    }

    public function modelHasObservedBy(string $model): bool
    {
        if ($this->observedModels === null) {
            $this->observedModels = [];
            foreach ($this->classification->sourcePaths() as $path) {
                if (! $this->classification->kindMatches($path, 'model', str_starts_with($path, 'app/Models/'))) {
                    continue;
                }
                $state = new class
                {
                    /** @var array<string, bool> */
                    public array $models = [];
                };
                $nodes = PhpAst::parse($this->files->get($this->basePath.'/'.$path));
                PhpAst::traverse($nodes ?? [], new class($state) extends NodeVisitorAbstract
                {
                    public function __construct(private object $state) {}

                    public function enterNode(Node $node): null
                    {
                        if (! Node::is($node, 'statement.class') || $node->name === null) {
                            return null;
                        }
                        $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                        $observed = false;
                        foreach ($node->attrGroups as $group) {
                            foreach ($group->attrs as $attribute) {
                                $resolved = $attribute->name->getAttribute('resolvedName');
                                $attributeName = Node::is($resolved, 'name') ? $resolved->toString() : $attribute->name->toString();
                                $observed = $observed || $attributeName === 'Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy' || $attributeName === 'ObservedBy';
                            }
                        }
                        $this->state->models[strtolower($name)] = $observed;

                        return null;
                    }
                });
                $this->observedModels += $state->models;
            }
        }

        return $this->observedModels[strtolower(ltrim($model, '\\'))] ?? false;
    }

    public function firstQuietSaveLine(FileContext $file): ?int
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return null;
        }

        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($this->state->line !== null) {
                    return null;
                }

                if (
                    Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), ['saveQuietly', 'updateQuietly', 'deleteQuietly'], true)
                ) {
                    $this->state->line = $node->getStartLine();
                }

                if (
                    Node::is($node, 'expression.static-call')
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'withoutEvents'
                ) {
                    $this->state->line = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->line;
    }

    public function containsTraitDeclaration(FileContext $file): bool
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return false;
        }

        return PhpAst::containsAny(
            $nodes,
            fn (Node $node): bool => Node::is($node, 'statement.trait'),
        );
    }

    public function inlineModelEventLine(FileContext $file): ?int
    {
        $nodes = $file->syntax();

        if ($nodes === null) {
            return null;
        }

        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $this->state->line === null
                    && Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && $node->class->toString() === 'static'
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), [...LifecycleAst::BEFORE_METHODS, ...LifecycleAst::AFTER_METHODS], true)
                ) {
                    $this->state->line = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->line;
    }

    private function isNullComparable(Node $node): bool
    {
        return Node::is($node, 'expression.variable')
            || Node::is($node, 'expression.property-fetch')
            || Node::is($node, 'expression.method-call');
    }
}
