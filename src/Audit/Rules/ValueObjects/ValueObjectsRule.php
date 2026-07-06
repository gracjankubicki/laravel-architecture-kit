<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\ValueObjects;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;

final readonly class ValueObjectsRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::ValueObjects, $enabled, true)
            && $this->isValueObjectPath($path);
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

        if ($class instanceof Stmt\Class_) {
            $className = $class->name?->toString();

            if (! $class->isFinal()) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Value Objects must be final classes.');
            }

            if (! $class->isReadonly()) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Value Objects must be readonly classes.');
            }

            if ($className !== null && $this->hasForbiddenValueObjectSuffix($className)) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Value Objects must be named after the domain concept; do not use Value, ValueObject, or Vo suffixes.');
            }

            if ($this->classExtendsAny($class, ['Model'])) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Value Objects must not extend Eloquent Model.');
            }
        }

        foreach ($this->setterLines($nodes) as $line) {
            $findings[] = $this->finding('error', $file->path, $line, 'Value Objects must not expose setters.');
        }

        foreach ($this->selfMutationLines($nodes) as $line) {
            $findings[] = $this->finding('error', $file->path, $line, 'Value Object methods must return new objects instead of mutating current state.');
        }

        return $findings;
    }

    private function isValueObjectPath(string $path): bool
    {
        return str_starts_with($path, 'app/ValueObjects/')
            || str_contains($path, '/ValueObjects/');
    }

    private function hasForbiddenValueObjectSuffix(string $class): bool
    {
        return str_ends_with($class, 'Value')
            || str_ends_with($class, 'ValueObject')
            || str_ends_with($class, 'Vo');
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
     * @return array<int, int>
     */
    private function selfMutationLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, int>
             */
            public array $lines = [];

            public ?string $method = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->state->method = $node->name->toString();

                    return null;
                }

                if ($this->state->method === '__construct') {
                    return null;
                }

                if (
                    ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp)
                    && $node->var instanceof Node\Expr\PropertyFetch
                    && $node->var->var instanceof Node\Expr\Variable
                    && $node->var->var->name === 'this'
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->state->method = null;
                }

                return null;
            }
        });

        return $state->lines;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'value-objects', $path, $line, $message);
    }
}
