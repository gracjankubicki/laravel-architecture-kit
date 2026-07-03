<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\FormRequests;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;

final readonly class FormRequestsRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function __construct(private array $enabled) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Requests/')
            || in_array(Architecture::DataObjects, $enabled, true);
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

        if (! $class instanceof Stmt\Class_) {
            return [];
        }

        if (str_starts_with($file->path, 'app/Http/Requests/') && $this->classExtendsAny($class, ['EmailVerificationRequest'])) {
            $findings[] = $this->finding(
                'error',
                $file->path,
                $class->getStartLine(),
                'Do not extend EmailVerificationRequest as a generic FormRequest base class. Extend FormRequest or a real project FormRequest base.',
            );
        }

        if (! $this->classExtendsAny($class, ['FormRequest', 'EmailVerificationRequest'])) {
            return $findings;
        }

        $dataMethod = $this->classMethod($class, 'data');

        if ($dataMethod instanceof Stmt\ClassMethod) {
            $findings[] = $this->finding('error', $file->path, $dataMethod->getStartLine(), 'Do not define data() on FormRequests; use toData().');
        }

        foreach (['authorize', 'rules'] as $method) {
            $classMethod = $this->classMethod($class, $method);

            if ($classMethod instanceof Stmt\ClassMethod && $classMethod->isPublic() && $this->classMethodHasAttribute($classMethod, 'Override')) {
                $findings[] = $this->finding(
                    'error',
                    $file->path,
                    $classMethod->getStartLine(),
                    "Do not add #[\\Override] to FormRequest {$method}(); Laravel resolves it by convention and the parent does not declare that method.",
                );
            }
        }

        $rulesMethod = $this->classMethod($class, 'rules') ?? $this->classMethod($class, 'architectureRules');
        $toDataMethod = $this->classMethod($class, 'toData');

        if (
            in_array(Architecture::DataObjects, $this->enabled, true)
            && ! $toDataMethod instanceof Stmt\ClassMethod
            && $rulesMethod instanceof Stmt\ClassMethod
        ) {
            $findings[] = $this->finding('error', $file->path, $rulesMethod->getStartLine(), 'Data Objects are enabled; FormRequest should expose toData().');
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
        return new AuditFinding($severity, 'form-request', $path, $line, $message);
    }
}
