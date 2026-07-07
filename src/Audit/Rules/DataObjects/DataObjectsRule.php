<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\DataObjects;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class DataObjectsRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::DataObjects, $enabled, true)
            && str_starts_with($path, 'app/Data/');
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
        $class = $this->firstClass($nodes);

        if ($class instanceof Stmt\Class_ && $this->classExtendsAny($class, ['Model'])) {
            $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Data Objects must not extend Eloquent Model.');
        }

        foreach ($this->setterLines($nodes) as $line) {
            $findings[] = $this->finding('error', $file->path, $line, 'Data Objects must not expose setters.');
        }

        foreach ($this->workflowCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', $file->path, $call['line'], $call['message']);
        }

        return $findings;
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

    /**
     * @param  array<int, string>  $expected
     */
    private function classExtendsAny(Stmt\Class_ $class, array $expected): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $extends = $class->extends->toString();

        foreach ($expected as $name) {
            if ($extends === $name || str_ends_with($extends, '\\'.$name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function setterLines(array $nodes): array
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
                    $node instanceof Stmt\ClassMethod
                    && $node->isPublic()
                    && str_starts_with($node->name->toString(), 'set')
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function workflowCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toString(), ['event', 'dispatch'], true)) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Data Objects must not dispatch workflow side effects.',
                    ];
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if (in_array($class, ['DB', 'Http', 'Mail', 'Notification', 'Bus', 'Event'], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not orchestrate infrastructure or workflow side effects.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not dispatch workflow side effects.',
                        ];
                    }

                    if (in_array($method, ['create', 'update', 'delete', 'forceDelete', 'restore', 'insert', 'upsert'], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not mutate domain state.',
                        ];
                    }
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['create', 'update', 'delete', 'forceDelete', 'restore', 'save', 'insert', 'upsert'], true)
                ) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Data Objects must not mutate domain state.',
                    ];
                }

                return null;
            }
        });

        return $state->calls;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'data-objects', $path, $line, $message);
    }
}
