<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;

final readonly class RequestCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        $nodes = $file->syntax();
        $class = $nodes === null ? null : ClassInspector::firstClass($nodes);

        if (! $this->paths->isIntegrationPath($file->path) || $class === null || ! ClassInspector::classExtendsAny($class, ['Request', 'SoloRequest'])) {
            return [];
        }

        $findings = [];
        $className = ClassInspector::className($class) ?? basename($file->path, '.php');

        if (! $class->isFinal()) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Saloon Request classes should be final.');
        }

        if (! str_ends_with($className, 'Request')) {
            $findings[] = $this->finding('warn', $file->path, 1, 'Saloon endpoint classes should use the Request suffix.');
        }

        array_push($findings, ...$this->requestAstFindings($file->path, $nodes, $class));

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function requestAstFindings(string $path, array $nodes, Node $class): array
    {
        $state = new class
        {
            public bool $importsGuzzleClient = false;

            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (Node::is($node, 'use-item') && $node->name->toString() === 'GuzzleHttp\\Client') {
                    $this->state->importsGuzzleClient = true;
                }

                if (Node::is($node, 'expression.static-call') && Node::is($node->class, 'name') && $node->class->toString() === 'Http') {
                    $this->state->findings[] = $this->finding($node, 'Do not call the Laravel HTTP facade inside Saloon Requests.');
                }

                if (Node::is($node, 'expression.new') && Node::is($node->class, 'name')) {
                    $class = $node->class->toString();

                    if ($class === 'GuzzleHttp\\Client' || ($class === 'Client' && $this->state->importsGuzzleClient)) {
                        $this->state->findings[] = $this->finding($node, 'Do not create direct Guzzle clients inside Saloon Requests.');
                    }
                }

                return null;
            }

            private function finding(Node $node, string $message): AuditFinding
            {
                return new AuditFinding('error', 'saloon', $this->path, $node->getStartLine(), $message);
            }
        });

        $hardcodedEndpointLine = $this->methodHardcodedUrlLine($class, 'resolveEndpoint');

        if ($hardcodedEndpointLine !== null) {
            $state->findings[] = $this->finding('error', $path, $hardcodedEndpointLine, 'Saloon Request endpoints must be relative paths, never absolute URLs.');
        }

        return $state->findings;
    }

    private function methodHardcodedUrlLine(Node $class, string $method): ?int
    {
        $classMethod = ClassInspector::classMethod($class, $method);

        if (! Node::is($classMethod, 'statement.class-method')) {
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
                if ($this->state->line === null && Node::is($node, 'scalar.string') && str_starts_with($node->value, 'http')) {
                    $this->state->line = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->line;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'saloon', $path, $line, $message);
    }
}
