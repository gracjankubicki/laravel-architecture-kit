<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ModernPhp85;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;

final readonly class ModernPhp85Rule implements PortableAuditRule
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
        $nodes = $file->syntax();

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
            if (! Node::is($node, 'statement.declare')) {
                continue;
            }

            foreach ($node->declares as $declare) {
                if (
                    $declare->key->toString() === 'strict_types'
                    && Node::is($declare->value, 'scalar.int')
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
                Node::is($method, 'statement.class-method')
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
     * @return array<int, Node>
     */
    private function resourceOverrideCandidateClasses(array $nodes): array
    {
        $classes = [];

        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.class') && $this->classExtendsAny($node, ['JsonResource', 'ResourceCollection'])) {
                $classes[] = $node;
            }

            if (! Node::is($node, 'statement.namespace')) {
                continue;
            }

            foreach ($node->stmts as $statement) {
                if (Node::is($statement, 'statement.class') && $this->classExtendsAny($statement, ['JsonResource', 'ResourceCollection'])) {
                    $classes[] = $statement;
                }
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function classExtendsAny(Node $class, array $expected): bool
    {
        if (! Node::is($class->extends, 'name')) {
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

    private function classMethod(Node $class, string $method): ?Node
    {
        foreach ($class->getMethods() as $classMethod) {
            if ($classMethod->name->toString() === $method) {
                return $classMethod;
            }
        }

        return null;
    }

    private function classMethodHasAttribute(Node $method, string $attribute): bool
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
