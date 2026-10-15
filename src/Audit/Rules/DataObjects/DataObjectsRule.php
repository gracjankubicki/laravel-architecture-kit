<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\DataObjects;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class DataObjectsRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::DataObjects, $enabled, true)
            && ($this->classification?->kindMatches($path, 'data', str_starts_with($path, 'app/Data/')) ?? str_starts_with($path, 'app/Data/'));
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

        $isEloquentModel = Node::is($class, 'statement.class') && $this->classExtendsEloquentModel($file, $class);

        if ($isEloquentModel) {
            $findings[] = $this->finding('error', $file->path, $class->getStartLine(), 'Data Objects must not extend Eloquent Model.');
        }

        foreach ($this->setterLines($nodes) as $line) {
            $findings[] = $this->finding('error', $file->path, $line, 'Data Objects must not expose setters.');
        }

        foreach ($this->workflowCallLines($file, $nodes, $isEloquentModel) as $call) {
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

    private function classExtendsEloquentModel(FileContext $file, Node $class): bool
    {
        if (! Node::is($class->extends, 'name')) {
            return false;
        }

        $extends = $file->resolvedName($class->extends);

        return $extends === 'Illuminate\\Database\\Eloquent\\Model'
            || str_starts_with($extends, 'App\\Models\\');
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
                    Node::is($node, 'statement.class-method')
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
    private function workflowCallLines(FileContext $file, array $nodes, bool $isEloquentModel): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($file, $state, $isEloquentModel) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
                private bool $isEloquentModel,
            ) {}

            public function enterNode(Node $node): null
            {
                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && in_array($node->name->toString(), ['event', 'dispatch'], true)) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Data Objects must not dispatch workflow side effects.',
                    ];
                }

                if (Node::is($node, 'expression.static-call') && Node::is($node->name, 'identifier')) {
                    $class = Node::is($node->class, 'name') ? $this->file->resolvedName($node->class) : null;
                    $method = $node->name->toString();

                    if (in_array($class, [
                        'Illuminate\\Support\\Facades\\DB',
                        'Illuminate\\Support\\Facades\\Http',
                        'Illuminate\\Support\\Facades\\Mail',
                        'Illuminate\\Support\\Facades\\Notification',
                        'Illuminate\\Support\\Facades\\Bus',
                        'Illuminate\\Support\\Facades\\Event',
                    ], true)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not orchestrate infrastructure or workflow side effects.',
                        ];
                    }

                    if (
                        $method === 'dispatch'
                        && str_starts_with($this->file->resolvedClassName($node) ?? '', 'App\\Events\\')
                    ) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not dispatch workflow side effects.',
                        ];
                    }

                    if (
                        $class !== null
                        && str_starts_with($this->file->resolvedClassName($node) ?? '', 'App\\Models\\')
                        && in_array($method, ['create', 'update', 'delete', 'forceDelete', 'restore', 'insert', 'upsert'], true)
                    ) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Data Objects must not mutate domain state.',
                        ];
                    }
                }

                if (
                    Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
                    && $this->isEloquentModel
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
