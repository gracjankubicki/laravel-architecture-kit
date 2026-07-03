<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\ModernPhp85;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;

final readonly class ModernPhp85Rule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::ModernPhp85, $enabled, true);
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

        if (! $this->hasStrictTypesDeclare($nodes)) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Changed PHP files should declare strict_types=1 when Modern PHP 8.5 is enabled.');
        }

        foreach ($this->missingOverrideMethods($nodes) as $method) {
            $findings[] = $this->finding('warn', $file->path, $method['line'], "Add #[\\Override] to {$method['method']}().");
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function hasStrictTypesDeclare(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Stmt\Declare_) {
                continue;
            }

            foreach ($node->declares as $declare) {
                if (
                    $declare->key->toString() === 'strict_types'
                    && $declare->value instanceof Node\Scalar\Int_
                    && $declare->value->value === 1
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, method: string}>
     */
    private function missingOverrideMethods(array $nodes): array
    {
        $findings = [];

        foreach ($this->resourceOverrideCandidateClasses($nodes) as $class) {
            $method = $this->classMethod($class, 'toArray');

            if (
                $method instanceof Stmt\ClassMethod
                && $method->isPublic()
                && ! $this->classMethodHasAttribute($method, 'Override')
            ) {
                $findings[] = [
                    'line' => $method->getStartLine(),
                    'method' => 'toArray',
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, Stmt\Class_>
     */
    private function resourceOverrideCandidateClasses(array $nodes): array
    {
        $classes = [];

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_ && $this->classExtendsAny($node, ['JsonResource', 'ResourceCollection'])) {
                $classes[] = $node;
            }

            if (! $node instanceof Stmt\Namespace_) {
                continue;
            }

            foreach ($node->stmts as $statement) {
                if ($statement instanceof Stmt\Class_ && $this->classExtendsAny($statement, ['JsonResource', 'ResourceCollection'])) {
                    $classes[] = $statement;
                }
            }
        }

        return $classes;
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

    private function classMethod(Stmt\Class_ $class, string $method): ?Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $classMethod) {
            if ($classMethod->name->toString() === $method) {
                return $classMethod;
            }
        }

        return null;
    }

    private function classMethodHasAttribute(Stmt\ClassMethod $method, string $attribute): bool
    {
        foreach ($method->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attr) {
                $name = $attr->name->toString();

                if ($name === $attribute || str_ends_with($name, '\\'.$attribute)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'modern-php-85', $path, $line, $message);
    }
}
