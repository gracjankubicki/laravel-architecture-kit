<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class EloquentLifecycleRequirement
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
    ) {
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function findings(string $path, string $contents): array
    {
        return [
            ...$this->lifecycleFolderPurityFindings($path, $contents),
            ...$this->observerFindings($path, $contents),
            ...$this->quietSaveFindings($path, $contents),
            ...$this->providerObserverRegistrationFindings($path, $contents),
            ...$this->inlineModelEventFindings($path, $contents),
            ...$this->transactionSideEffectFindings($path, $contents),
        ];
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function lifecycleFolderPurityFindings(string $path, string $contents): array
    {
        if (! $this->isLifecyclePath($path)) {
            return [];
        }

        $nodes = PhpAst::parse($contents);
        $class = $nodes === null ? null : $this->firstClass($nodes);
        $className = $class === null ? basename($path, '.php') : ($this->className($class) ?? basename($path, '.php'));

        if ($class !== null && $this->looksLikeLifecycleHandler($className, $class)) {
            return [];
        }

        return [
            $this->finding(
                'error',
                'eloquent-lifecycle',
                $path,
                1,
                'Lifecycle folders must contain final lifecycle handlers only: one public handle(Model $model): void method and no Data/Result/Enum/Request/Resource classes.',
            ),
        ];
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function observerFindings(string $path, string $contents): array
    {
        if (! $this->isObserverPath($path)) {
            return [];
        }

        $findings = [];
        $methods = $this->lifecycleMethods($contents);
        $hasBeforeMethod = false;
        $afterCommitLine = $this->afterCommitObserverLine($contents);

        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            $isBefore = in_array($methodName, self::BEFORE_METHODS, true);
            $isAfter = in_array($methodName, self::AFTER_METHODS, true);
            $hasBeforeMethod = $hasBeforeMethod || $isBefore;

            if ($isBefore) {
                array_push(
                    $findings,
                    ...$this->beforeObserverMethodFindings($path, $method),
                );
            }

            if ($isAfter) {
                array_push(
                    $findings,
                    ...$this->afterObserverMethodFindings($path, $method),
                );
            }

            array_push(
                $findings,
                ...$this->tooManyCallsFindings($path, $method),
            );
        }

        array_push($findings, ...$this->observerAstBranchingFindings($path, $contents));

        if ($hasBeforeMethod && $afterCommitLine !== null) {
            $findings[] = $this->finding(
                'warn',
                'eloquent-lifecycle',
                $path,
                $afterCommitLine,
                'Do not delay the whole observer with ShouldHandleEventsAfterCommit/$afterCommit when it has before-save methods; apply after-commit at the event/listener/job dispatch level.',
            );
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function beforeObserverMethodFindings(string $path, Stmt\ClassMethod $method): array
    {
        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse([$method], new class($path, $state, $this, $method) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private EloquentLifecycleRequirement $requirement,
                private Stmt\ClassMethod $method,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($this->isForbiddenFacadeCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Before-save observers must not use facades; delegate to one lifecycle handler or move side effects outside lifecycle.',
                    );
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'app') {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Before-save observers must not resolve collaborators with app(); inject one lifecycle handler.',
                    );
                }

                if ($this->isDispatchCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Before-save observers must not dispatch events or jobs; move side effects to after-save events/listeners.',
                    );
                }

                if ($this->isModelWriteCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Before-save observers must not write other models; they may only mutate the current model or block the save.',
                    );
                }

                if ($this->isModelCreationOrQueryCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'error',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Before-save observers must not create models or build queries; delegate to an explicit lifecycle handler or Action.',
                    );
                }

                if (
                    in_array($this->method->name->toString(), ['deleting', 'restoring'], true)
                    && $this->mutatesLifecycleParameter($node)
                ) {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'deleting/restoring handlers are gatekeepers only; verify invariants and throw named exceptions instead of mutating attributes.',
                    );
                }

                return null;
            }

            private function isForbiddenFacadeCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && in_array($node->class->toString(), ['DB', 'Http', 'Mail', 'Notification', 'Cache', 'Log', 'Auth'], true);
            }

            private function isDispatchCall(Node $node): bool
            {
                if ($node instanceof FuncCall && $node->name instanceof Name) {
                    return in_array($node->name->toString(), ['event', 'dispatch'], true);
                }

                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'dispatch';
            }

            private function isModelWriteCall(Node $node): bool
            {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['save', 'update', 'delete'], true);
            }

            private function isModelCreationOrQueryCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['create', 'query'], true);
            }

            private function mutatesLifecycleParameter(Node $node): bool
            {
                if (! $node instanceof Assign && ! $node instanceof AssignOp\Coalesce) {
                    return false;
                }

                $parameter = $this->firstParameterName();

                return $parameter !== null && $this->isParameterMemberFetch($node->var, $parameter);
            }

            private function firstParameterName(): ?string
            {
                $parameter = $this->method->params[0] ?? null;

                if (! $parameter instanceof Node\Param || ! $parameter->var instanceof Variable || ! is_string($parameter->var->name)) {
                    return null;
                }

                return $parameter->var->name;
            }

            private function isParameterMemberFetch(Node $node, string $parameter): bool
            {
                if ($node instanceof PropertyFetch || $node instanceof ArrayDimFetch) {
                    return $node->var instanceof Variable && $node->var->name === $parameter;
                }

                return false;
            }
        });

        return $state->findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function afterObserverMethodFindings(string $path, Stmt\ClassMethod $method): array
    {
        if ($this->isSingleNamedEventDispatch($method)) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse([$method], new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private EloquentLifecycleRequirement $requirement,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($this->isForbiddenFacadeCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'After-save observers should dispatch one named event; put side effects in after-commit listeners or jobs.',
                    );
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'app') {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'After-save observers should not resolve collaborators with app(); dispatch one named event or delegate through an enabled boundary.',
                    );
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'dispatch') {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'After-save observers should not dispatch jobs directly; dispatch one named event and let listeners own async work.',
                    );
                }

                if ($this->isModelWriteCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'After-save observers should not write models directly; move reactions to named listeners or Actions.',
                    );
                }

                if ($this->isModelCreationOrQueryCall($node)) {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'After-save observers should not create models or build queries directly; move reactions to named listeners or Actions.',
                    );
                }

                return null;
            }

            private function isForbiddenFacadeCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && in_array($node->class->toString(), ['DB', 'Http', 'Mail', 'Notification', 'Cache', 'Log', 'Auth'], true);
            }

            private function isModelWriteCall(Node $node): bool
            {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['save', 'update', 'delete'], true);
            }

            private function isModelCreationOrQueryCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['create', 'query'], true);
            }
        });

        return $state->findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function observerAstBranchingFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private EloquentLifecycleRequirement $requirement,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                if (! in_array($node->name->toString(), [...EloquentLifecycleRequirement::BEFORE_METHODS, ...EloquentLifecycleRequirement::AFTER_METHODS], true)) {
                    return null;
                }

                foreach ($node->stmts ?? [] as $statement) {
                    $this->inspectLifecycleStatement($statement);
                }

                return null;
            }

            private function inspectLifecycleStatement(Node $node): void
            {
                if ($node instanceof Stmt\If_) {
                    if (! $this->requirement->isTechnicalLifecycleCondition($node->cond)) {
                        $this->state->findings[] = $this->requirement->finding(
                            'error',
                            'eloquent-lifecycle',
                            $this->path,
                            $node->getStartLine(),
                            'Business branching does not belong in observer methods; move the condition to a lifecycle handler or named listener.',
                        );
                    }
                } elseif ($node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Switch_) {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Observer methods should not orchestrate loops or switch logic; move branching rules to a lifecycle handler or named listener.',
                    );
                } elseif ($node instanceof Stmt\Expression && $node->expr instanceof Node\Expr\Match_) {
                    $this->state->findings[] = $this->requirement->finding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Observer methods should not contain match-based orchestration; move branching rules to a lifecycle handler or named listener.',
                    );
                }
            }
        });

        return $state->findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function tooManyCallsFindings(string $path, Stmt\ClassMethod $method): array
    {
        $calls = $this->significantCalls($method);

        if (count($calls) <= 1) {
            return [];
        }

        return [
            $this->finding(
                'warn',
                'eloquent-lifecycle',
                $path,
                $calls[1],
                'Observer methods should call exactly one lifecycle handler or dispatch exactly one named event; do not orchestrate several calls in the observer.',
            ),
        ];
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function quietSaveFindings(string $path, string $contents): array
    {
        if (str_starts_with($path, 'tests/') || str_contains($path, '/Tests/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $line = $this->firstQuietSaveLine($nodes);

        if ($line === null) {
            return [];
        }

        return [
            $this->finding(
                'warn',
                'eloquent-lifecycle',
                $path,
                $line,
                'Quiet saves/withoutEvents suggest observers carry behavior callers need to opt out of; move that behavior to an explicit Action or named event.',
            ),
        ];
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function providerObserverRegistrationFindings(string $path, string $contents): array
    {
        if (! str_starts_with($path, 'app/Providers/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->observerRegistrations($nodes) as $registration) {
            $model = $registration['model'];

            if ($this->modelHasObservedBy($model)) {
                continue;
            }

            $findings[] = $this->finding(
                'warn',
                'eloquent-lifecycle',
                $path,
                $registration['line'],
                'Register observers with #[ObservedBy(...)] on the model instead of hidden Model::observe(...) provider registration.',
            );
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function inlineModelEventFindings(string $path, string $contents): array
    {
        if (! str_starts_with($path, 'app/Models/')) {
            return [];
        }

        $nodes = PhpAst::parse($contents);

        if ($nodes === null || $this->containsTraitDeclaration($nodes)) {
            return [];
        }

        $line = $this->inlineModelEventLine($nodes);

        if ($line === null) {
            return [];
        }

        return [
            $this->finding(
                'error',
                'eloquent-lifecycle',
                $path,
                $line,
                'Concrete models must not register inline lifecycle closures; use #[ObservedBy] + observer/handler or $dispatchesEvents.',
            ),
        ];
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function transactionSideEffectFindings(string $path, string $contents): array
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private EloquentLifecycleRequirement $requirement,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (! $this->isDbTransactionCall($node)) {
                    return null;
                }

                $callback = $node->args[0]->value ?? null;

                if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
                    return null;
                }

                PhpAst::traverse([$callback], new class($this->path, $this->state, $this->requirement) extends NodeVisitorAbstract
                {
                    public function __construct(
                        private string $path,
                        private object $state,
                        private EloquentLifecycleRequirement $requirement,
                    ) {
                    }

                    public function enterNode(Node $node): null
                    {
                        if ($this->isAfterCommitCall($node)) {
                            return null;
                        }

                        if ($this->isTransactionSideEffect($node)) {
                            $this->state->findings[] = $this->requirement->finding(
                                'warn',
                                'transaction-side-effects',
                                $this->path,
                                $node->getStartLine(),
                                'Do not dispatch events, jobs, notifications, mail, HTTP, or external API calls inside an open DB transaction; move the side effect after commit.',
                            );
                        }

                        return null;
                    }

                    private function isAfterCommitCall(Node $node): bool
                    {
                        return $node instanceof StaticCall
                            && $node->class instanceof Name
                            && $node->class->toString() === 'DB'
                            && $node->name instanceof Node\Identifier
                            && $node->name->toString() === 'afterCommit';
                    }

                    private function isTransactionSideEffect(Node $node): bool
                    {
                        if ($node instanceof FuncCall && $node->name instanceof Name) {
                            return in_array($node->name->toString(), ['event', 'dispatch'], true);
                        }

                        if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                            $class = $node->class instanceof Name ? $node->class->toString() : null;
                            $method = $node->name->toString();

                            if ($class !== null && in_array($class, ['Event', 'Bus', 'Mail', 'Notification', 'Http'], true)) {
                                return true;
                            }

                            return $method === 'dispatch' && $class !== 'DB';
                        }

                        if ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
                            return in_array($node->name->toString(), ['notify', 'send', 'sendAsync'], true);
                        }

                        return false;
                    }
                });

                return null;
            }

            private function isDbTransactionCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'DB'
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'transaction';
            }
        });

        return $state->findings;
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

    private function isNullComparable(Node $node): bool
    {
        return $node instanceof Variable
            || $node instanceof PropertyFetch
            || $node instanceof MethodCall;
    }

    /**
     * @return array<int, Stmt\ClassMethod>
     */
    private function lifecycleMethods(string $contents): array
    {
        $nodes = PhpAst::parse($contents);

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
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof Stmt\ClassMethod
                    && $node->isPublic()
                    && in_array($node->name->toString(), [...EloquentLifecycleRequirement::BEFORE_METHODS, ...EloquentLifecycleRequirement::AFTER_METHODS], true)
                ) {
                    $this->state->methods[] = $node;
                }

                return null;
            }
        });

        return $state->methods;
    }

    private function isSingleNamedEventDispatch(Stmt\ClassMethod $method): bool
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
            $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toString() === 'event'
            && ($expr->args[0]->value ?? null) instanceof Node\Expr\New_
        ) {
            return true;
        }

        return $expr instanceof StaticCall
            && $expr->class instanceof Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'dispatch';
    }

    /**
     * @return array<int, int>
     */
    private function significantCalls(Stmt\ClassMethod $method): array
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
            ) {
            }

            public function enterNode(Node $node): null
            {
                $name = null;

                if (($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Node\Identifier) {
                    $name = $node->name->toString();
                }

                if ($node instanceof FuncCall && $node->name instanceof Name) {
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

    private function modelHasObservedBy(string $model): bool
    {
        $path = $this->basePath.'/app/Models/'.$model.'.php';

        if (! $this->files->exists($path)) {
            return false;
        }

        $nodes = PhpAst::parse($this->files->get($path));

        if ($nodes === null) {
            return false;
        }

        $class = $this->firstClass($nodes);

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

    private function isObserverPath(string $path): bool
    {
        return str_ends_with($path, 'Observer.php')
            && (str_starts_with($path, 'app/Observers/') || str_contains($path, '/Observers/'));
    }

    private function isLifecyclePath(string $path): bool
    {
        return str_starts_with($path, 'app/Lifecycle/')
            || str_contains($path, '/Lifecycle/');
    }

    private function looksLikeLifecycleHandler(string $className, Stmt\Class_ $class): bool
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

    private function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
    }

    public function finding(string $severity, string $rule, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, $rule, $path, $line, $message);
    }

    private function afterCommitObserverLine(string $contents): ?int
    {
        $nodes = PhpAst::parse($contents);

        if ($nodes === null) {
            return null;
        }

        $class = $this->firstClass($nodes);

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
     * @param  array<int, Node>  $nodes
     */
    private function firstQuietSaveLine(array $nodes): ?int
    {
        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

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

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{model: string, line: int}>
     */
    private function observerRegistrations(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{model: string, line: int}>
             */
            public array $registrations = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

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

    /**
     * @param  array<int, Node>  $nodes
     */
    private function containsTraitDeclaration(array $nodes): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\Trait_,
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function inlineModelEventLine(array $nodes): ?int
    {
        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state)
            {
            }

            public function enterNode(Node $node): null
            {
                if (
                    $this->state->line === null
                    && $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'static'
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), [...EloquentLifecycleRequirement::BEFORE_METHODS, ...EloquentLifecycleRequirement::AFTER_METHODS], true)
                ) {
                    $this->state->line = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->line;
    }
}
