<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class LifecycleAst
{
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
        private Filesystem $files,
        private string $basePath,
    ) {}

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

    public function looksLikeLifecycleHandler(string $className, Stmt\Class_ $class): bool
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
            fn (Stmt\ClassMethod $method): bool => $method->isPublic() && $method->name->toString() === 'handle',
        );

        return count($handleMethods) === 1;
    }

    /**
     * @return array<int, Stmt\ClassMethod>
     */
    public function lifecycleMethods(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, Stmt\ClassMethod>
             */
            public array $methods = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Stmt\ClassMethod
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

    public function isSingleNamedEventDispatch(Stmt\ClassMethod $method): bool
    {
        if (count($method->stmts ?? []) !== 1) {
            return false;
        }

        $statement = $method->stmts[0];

        if (! $statement instanceof Stmt\Expression) {
            return false;
        }

        $expr = $statement->expr;

        if (
            $expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'event'
            && ($expr->args[0]->value ?? null) instanceof Node\Expr\New_
        ) {
            return true;
        }

        if (
            ! $expr instanceof StaticCall
            || ! $expr->class instanceof Name
            || ! $expr->name instanceof Node\Identifier
            || $expr->name->toString() !== 'dispatch'
        ) {
            return false;
        }

        $resolved = $expr->class->getAttribute('resolvedName');
        $class = $resolved instanceof Name ? $resolved->toString() : $expr->class->toString();

        return str_starts_with($class, 'App\\Events\\');
    }

    public function isTechnicalLifecycleCondition(Node $condition): bool
    {
        if ($condition instanceof BooleanNot) {
            return $this->isTechnicalLifecycleCondition($condition->expr);
        }

        if ($condition instanceof BinaryOp\BooleanAnd || $condition instanceof BinaryOp\BooleanOr) {
            return $this->isTechnicalLifecycleCondition($condition->left)
                && $this->isTechnicalLifecycleCondition($condition->right);
        }

        if (
            $condition instanceof BinaryOp\Identical
            || $condition instanceof BinaryOp\NotIdentical
            || $condition instanceof BinaryOp\Equal
            || $condition instanceof BinaryOp\NotEqual
        ) {
            if ($condition->left instanceof Node\Expr\ConstFetch && strtolower($condition->left->name->toString()) === 'null') {
                return $this->isNullComparable($condition->right);
            }

            if ($condition->right instanceof Node\Expr\ConstFetch && strtolower($condition->right->name->toString()) === 'null') {
                return $this->isNullComparable($condition->left);
            }

            return $this->isTechnicalLifecycleCondition($condition->left)
                && $this->isTechnicalLifecycleCondition($condition->right);
        }

        if ($condition instanceof MethodCall && $condition->name instanceof Node\Identifier) {
            return in_array($condition->name->toString(), ['isDirty', 'wasChanged', 'getDirty', 'getChanges'], true);
        }

        if ($condition instanceof PropertyFetch && $condition->name instanceof Node\Identifier) {
            return $condition->name->toString() === 'wasRecentlyCreated';
        }

        if ($condition instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($condition->name->toString()), ['true', 'false', 'null'], true);
        }

        if ($condition instanceof Scalar) {
            return false;
        }

        return false;
    }

    public function afterCommitObserverLine(FileContext $file): ?int
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return null;
        }

        $class = ClassInspector::firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return null;
        }

        foreach ($class->implements as $implemented) {
            $name = $implemented->toString();

            if ($name === 'ShouldHandleEventsAfterCommit' || str_ends_with($name, '\\ShouldHandleEventsAfterCommit')) {
                return $implemented->getStartLine();
            }
        }

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                if (
                    $property->name->toString() === 'afterCommit'
                    && $property->default instanceof Node\Expr\ConstFetch
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
    public function significantCalls(Stmt\ClassMethod $method): array
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

                if (($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Node\Identifier) {
                    $name = $node->name->toString();
                }

                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Name) {
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
        $nodes = $file->ast();

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
                    $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'observe'
                ) {
                    $this->state->registrations[] = [
                        'model' => $this->shortName($node->class),
                        'line' => $node->getStartLine(),
                    ];
                }

                return null;
            }

            private function shortName(Name $name): string
            {
                $parts = $name->getParts();

                return $parts[count($parts) - 1];
            }
        });

        return $state->registrations;
    }

    public function modelHasObservedBy(string $model): bool
    {
        $path = $this->basePath.'/app/Models/'.$model.'.php';

        if (! $this->files->exists($path)) {
            return false;
        }

        $nodes = PhpAst::parse($this->files->get($path));

        if ($nodes === null) {
            return false;
        }

        $class = ClassInspector::firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        foreach ($class->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                $name = $attribute->name->toString();

                if ($name === 'ObservedBy' || str_ends_with($name, '\\ObservedBy')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function firstQuietSaveLine(FileContext $file): ?int
    {
        $nodes = $file->ast();

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
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['saveQuietly', 'updateQuietly', 'deleteQuietly'], true)
                ) {
                    $this->state->line = $node->getStartLine();
                }

                if (
                    $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
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
        $nodes = $file->ast();

        if ($nodes === null) {
            return false;
        }

        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\Trait_,
        );
    }

    public function inlineModelEventLine(FileContext $file): ?int
    {
        $nodes = $file->ast();

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
                    && $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'static'
                    && $node->name instanceof Node\Identifier
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
        return $node instanceof Variable
            || $node instanceof PropertyFetch
            || $node instanceof MethodCall;
    }
}
