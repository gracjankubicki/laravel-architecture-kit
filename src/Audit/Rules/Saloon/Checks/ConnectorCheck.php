<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class ConnectorCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        $nodes = $file->ast();
        $class = $nodes === null ? null : ClassInspector::firstClass($nodes);

        if (! $this->paths->isIntegrationPath($file->path) || $class === null || ! ClassInspector::classExtendsAny($class, ['Connector'])) {
            return [];
        }

        $findings = [];

        if (! $class->isFinal()) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Saloon Connectors should be final classes.');
        }

        if (! ClassInspector::classUsesTrait($class, 'AlwaysThrowOnErrors')) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Saloon Connectors should use AlwaysThrowOnErrors and map failures at the Action/Job boundary.');
        }

        if (! ClassInspector::classUsesTrait($class, 'HasRateLimits')) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Saloon Connectors should use HasRateLimits from saloonphp/rate-limit-plugin.');
        }

        if (! ClassInspector::classHasProperty($class, 'tries') && ! ClassInspector::classHasMethod($class, 'resolveRetry') && ! ClassInspector::classHasMethod($class, 'defaultRetry')) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Saloon Connectors should define retry/backoff defaults.');
        }

        $hardcodedBaseUrlLine = $this->methodHardcodedUrlLine($class, 'resolveBaseUrl');

        if ($hardcodedBaseUrlLine !== null) {
            $findings[] = $this->finding('warn', $file->path, $hardcodedBaseUrlLine, 'Connector base URLs should come from config("services.*"), not hard-coded literals.');
        }

        array_push($findings, ...$this->envCallFindings($file->path, $nodes));

        return $findings;
    }

    private function methodHardcodedUrlLine(Stmt\Class_ $class, string $method): ?int
    {
        $classMethod = ClassInspector::classMethod($class, $method);

        if (! $classMethod instanceof Stmt\ClassMethod) {
            return null;
        }

        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse([$classMethod], new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($this->state->line === null && $node instanceof String_ && str_starts_with($node->value, 'http')) {
                    $this->state->line = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->line;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function envCallFindings(string $path, array $nodes): array
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
                if ($node instanceof FuncCall && $node->name instanceof Node\Name && $node->name->toString() === 'env') {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return array_map(
            fn (int $line): AuditFinding => $this->finding('error', $path, $line, 'Do not call env() inside integrations; read credentials and URLs from config("services.*").'),
            array_values(array_unique($state->lines)),
        );
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'saloon', $path, $line, $message);
    }
}
