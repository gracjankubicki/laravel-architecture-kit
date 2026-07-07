<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class RawHttpCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        if ($this->paths->isIntegrationPath($file->path)) {
            return [];
        }

        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            public bool $importsGuzzleClient = false;

            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($file->path, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && $node->name->toString() === 'GuzzleHttp\\Client') {
                    $this->state->importsGuzzleClient = true;
                }

                if ($node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === 'Http') {
                    $this->state->findings[] = $this->finding($node, 'Raw Laravel Http:: calls are forbidden when Saloon is enabled; create a Saloon integration under app/Http/Integrations/**.');
                }

                if ($this->isDirectGuzzleClient($node)) {
                    $this->state->findings[] = $this->finding($node, 'Direct Guzzle clients are forbidden when Saloon is enabled; create a Saloon Connector and Request.');
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && in_array($node->name->toString(), ['curl_init', 'curl_exec'], true)) {
                    $this->state->findings[] = $this->finding($node, 'curl_* calls are forbidden when Saloon is enabled; create a Saloon integration.');
                }

                if ($this->isOutboundFileGetContents($node)) {
                    $this->state->findings[] = $this->finding($node, 'Outbound file_get_contents(http...) is forbidden when Saloon is enabled; create a Saloon integration.');
                }

                return null;
            }

            private function finding(Node $node, string $message): AuditFinding
            {
                return new AuditFinding('error', 'raw-http', $this->path, $node->getStartLine(), $message);
            }

            private function isDirectGuzzleClient(Node $node): bool
            {
                if (! $node instanceof New_ || ! $node->class instanceof Name) {
                    return false;
                }

                $class = $node->class->toString();

                return $class === 'GuzzleHttp\\Client'
                    || ($class === 'Client' && $this->state->importsGuzzleClient);
            }

            private function isOutboundFileGetContents(Node $node): bool
            {
                if (! $node instanceof FuncCall || ! $node->name instanceof Name || $node->name->toString() !== 'file_get_contents') {
                    return false;
                }

                $argument = $node->args[0]->value ?? null;

                return $argument instanceof String_ && str_starts_with($argument->value, 'http');
            }
        });

        return $state->findings;
    }
}
