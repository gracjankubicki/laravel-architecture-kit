<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\CustomEloquentBuilders;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class CustomEloquentBuildersRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::CustomEloquentBuilders, $enabled, true)
            && ($this->classification?->kindMatches($path, 'builder', str_starts_with($path, 'app/Models/Builders/')) ?? str_starts_with($path, 'app/Models/Builders/'));
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

        if (Node::is($class, 'statement.class')) {
            $className = $class->name?->toString();

            if (! $class->isFinal()) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Custom Eloquent Builder classes must be final.');
            }

            if (! $this->classExtendsAny($class, ['Builder'])) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Custom Eloquent Builders must extend Illuminate\\Database\\Eloquent\\Builder.');
            }

            if ($className !== null && ! str_ends_with($className, 'Builder')) {
                $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Custom Eloquent Builder classes must use the Builder suffix.');
            }
        }

        foreach ($this->httpUseLines($nodes) as $use) {
            $findings[] = $this->finding('error', $file->path, $use['line'], $use['message']);
        }

        foreach ($this->forbiddenCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', $file->path, $call['line'], $call['message']);
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

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function httpUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $uses = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! Node::is($node, 'use-item')) {
                    return null;
                }

                $name = $node->name->toString();

                if (
                    in_array($name, [
                        'Illuminate\\Http\\Request',
                        'Illuminate\\Http\\JsonResponse',
                        'Illuminate\\Http\\RedirectResponse',
                        'Illuminate\\Http\\Response',
                        'Illuminate\\Foundation\\Http\\FormRequest',
                        'Symfony\\Component\\HttpFoundation\\Response',
                        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
                        'Symfony\\Component\\HttpFoundation\\RedirectResponse',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Custom Eloquent Builders must not depend on HTTP request or response classes.',
                    ];
                }

                return null;
            }
        });

        return $state->uses;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function forbiddenCallLines(array $nodes): array
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
                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && in_array($node->name->toString(), ['event', 'dispatch'], true)) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Custom Eloquent Builders must not dispatch events or jobs.',
                    ];
                }

                if (Node::is($node, 'expression.static-call') && Node::is($node->name, 'identifier')) {
                    $class = Node::is($node->class, 'name') ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if ($class === 'DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Custom Eloquent Builders must not own transactions.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Custom Eloquent Builders must not dispatch events or jobs.',
                        ];
                    }

                    if (in_array($method, ['create', 'update', 'delete', 'forceDelete', 'restore', 'insert', 'upsert'], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Custom Eloquent Builders must not mutate domain state.',
                        ];
                    }
                }

                if (
                    Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), ['create', 'update', 'delete', 'forceDelete', 'restore', 'save', 'insert', 'upsert'], true)
                ) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Custom Eloquent Builders must not mutate domain state.',
                    ];
                }

                return null;
            }
        });

        return $state->calls;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'custom-eloquent-builders', $path, $line, $message);
    }
}
