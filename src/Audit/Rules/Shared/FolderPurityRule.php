<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Shared;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;
use Taqie\ArchitectureKit\Support\PhpAst;

final readonly class FolderPurityRule implements AuditRule
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
        return str_starts_with($path, 'app/Actions/')
            || str_starts_with($path, 'app/Services/')
            || str_starts_with($path, 'app/Data/')
            || $this->isValueObjectPath($path)
            || str_starts_with($path, 'app/Enums/')
            || str_starts_with($path, 'app/Exceptions/')
            || str_starts_with($path, 'app/Http/Resources/')
            || str_starts_with($path, 'app/Queries/')
            || str_starts_with($path, 'app/Models/Builders/');
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

        if (str_starts_with($file->path, 'app/Actions/') && ! $this->looksLikeAction($nodes)) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Actions/** must contain Actions only.');
        }

        if (
            in_array(Architecture::Services, $this->enabled, true)
            && str_starts_with($file->path, 'app/Services/')
            && ! $this->looksLikeService($nodes)
        ) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Services/** must contain Services only.');
        }

        if (str_starts_with($file->path, 'app/Data/') && ! $this->looksLikeDataObject($nodes)) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Data/** must contain Data Objects, DTOs, and Result objects only.');
        }

        if (
            in_array(Architecture::ValueObjects, $this->enabled, true)
            && $this->isValueObjectPath($file->path)
            && ! $this->looksLikeValueObject($nodes)
        ) {
            $findings[] = $this->finding('error', $file->path, 1, 'Value Object folders must contain final readonly Value Object classes only.');
        }

        if (str_starts_with($file->path, 'app/Enums/') && ! $this->looksLikeEnum($nodes)) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Enums/** must contain Enums only.');
        }

        if (str_starts_with($file->path, 'app/Exceptions/') && ! $this->looksLikeException($nodes)) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Exceptions/** must contain Exceptions only.');
        }

        if (str_starts_with($file->path, 'app/Http/Resources/') && ! $this->looksLikeApiResource($nodes)) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Http/Resources/** must contain API Resources and Resource Collections only.');
        }

        if (str_starts_with($file->path, 'app/Queries/') && ! $this->looksLikeQueryObject($nodes)) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Queries/** must contain Query Objects only.');
        }

        if (
            in_array(Architecture::CustomEloquentBuilders, $this->enabled, true)
            && str_starts_with($file->path, 'app/Models/Builders/')
            && ! $this->looksLikeCustomEloquentBuilder($nodes)
        ) {
            $findings[] = $this->finding('error', $file->path, 1, 'app/Models/Builders/** must contain final custom Eloquent Builder classes only.');
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeAction(array $nodes): bool
    {
        if ($this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        if (
            $className === null
            || str_ends_with($className, 'Data')
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

        return $this->classHasPublicMethod($class, 'handle');
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeService(array $nodes): bool
    {
        if ($this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        if ($className === null || ! str_ends_with($className, 'Service')) {
            return false;
        }

        return ! (
            str_ends_with($className, 'Data')
            || str_ends_with($className, 'Dto')
            || str_ends_with($className, 'DTO')
            || str_ends_with($className, 'Result')
            || str_ends_with($className, 'Resource')
            || str_ends_with($className, 'Request')
            || str_ends_with($className, 'Exception')
            || str_ends_with($className, 'Failure')
            || str_ends_with($className, 'Status')
            || str_ends_with($className, 'Action')
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeQueryObject(array $nodes): bool
    {
        if ($this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        if (
            $className === null
            || str_ends_with($className, 'Data')
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

        return $this->classHasPublicMethod($class, 'handle');
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeApiResource(array $nodes): bool
    {
        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        return $this->classExtendsAny($class, ['JsonResource', 'ResourceCollection']);
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeException(array $nodes): bool
    {
        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_ || ! $class->extends instanceof Name) {
            return false;
        }

        return str_ends_with($this->shortTypeName($class->extends->toString()), 'Exception');
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeDataObject(array $nodes): bool
    {
        if ($this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        return $className !== null
            && (
                str_ends_with($className, 'Data')
                || str_ends_with($className, 'Dto')
                || str_ends_with($className, 'DTO')
                || str_ends_with($className, 'Result')
            )
            && $class->isFinal()
            && $class->isReadonly();
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeValueObject(array $nodes): bool
    {
        if ($this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        return $className !== null
            && ! $this->hasForbiddenValueObjectSuffix($className)
            && $class->isFinal()
            && $class->isReadonly()
            && ! $this->classExtendsAny($class, ['Model']);
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeEnum(array $nodes): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\Enum_,
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function looksLikeCustomEloquentBuilder(array $nodes): bool
    {
        if ($this->containsEnumDeclaration($nodes)) {
            return false;
        }

        $class = $this->firstClass($nodes);

        if (! $class instanceof Stmt\Class_) {
            return false;
        }

        $className = $class->name?->toString();

        return $className !== null
            && str_ends_with($className, 'Builder')
            && $class->isFinal()
            && $this->classExtendsAny($class, ['Builder']);
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function containsEnumDeclaration(array $nodes): bool
    {
        return PhpAst::contains(
            new Stmt\Namespace_(null, $nodes),
            fn (Node $node): bool => $node instanceof Stmt\Enum_,
        );
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

    private function classHasPublicMethod(Stmt\Class_ $class, string $method): bool
    {
        $classMethod = $this->classMethod($class, $method);

        return $classMethod instanceof Stmt\ClassMethod && $classMethod->isPublic();
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

    private function shortTypeName(string $name): string
    {
        $parts = explode('\\', $name);

        return $parts[count($parts) - 1];
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'folder-purity', $path, $line, $message);
    }
}
