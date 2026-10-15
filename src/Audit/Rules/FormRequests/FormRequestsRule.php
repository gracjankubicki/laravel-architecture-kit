<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\FormRequests;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class FormRequestsRule implements PortableAuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function __construct(private array $enabled, private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return ($this->classification?->kindMatches($path, 'request', str_starts_with($path, 'app/Http/Requests/')) ?? str_starts_with($path, 'app/Http/Requests/'))
            || in_array(Architecture::DataObjects, $enabled, true);
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
        $class = $this->firstClass($nodes);

        if (! Node::is($class, 'statement.class')) {
            return [];
        }

        if (($this->classification?->kindMatches($file->path, 'request', str_starts_with($file->path, 'app/Http/Requests/')) ?? str_starts_with($file->path, 'app/Http/Requests/')) && $this->classExtendsAny($class, ['EmailVerificationRequest'])) {
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

        if (Node::is($dataMethod, 'statement.class-method')) {
            $findings[] = $this->finding('error', $file->path, $dataMethod->getStartLine(), 'Do not define data() on FormRequests; use toData().');
        }

        foreach (['authorize', 'rules'] as $method) {
            $classMethod = $this->classMethod($class, $method);

            if (Node::is($classMethod, 'statement.class-method') && $classMethod->isPublic() && $this->classMethodHasAttribute($classMethod, 'Override')) {
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
            && ! Node::is($toDataMethod, 'statement.class-method')
            && Node::is($rulesMethod, 'statement.class-method')
        ) {
            $findings[] = $this->finding('error', $file->path, $rulesMethod->getStartLine(), 'Data Objects are enabled; FormRequest should expose toData().');
        }

        return $findings;
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
        return new AuditFinding($severity, 'form-request', $path, $line, $message);
    }
}
