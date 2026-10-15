<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;

final readonly class RawSaloonResponseCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        if ($this->paths->isIntegrationPath($file->path)) {
            return [];
        }

        $nodes = $file->syntax();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<string, true>
             */
            public array $responseVariables = [];

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
                    Node::is($node, 'expression.assign')
                    && Node::is($node->var, 'expression.variable')
                    && is_string($node->var->name)
                    && $this->isSaloonSendCall($node->expr)
                ) {
                    $this->state->responseVariables[$node->var->name] = true;
                }

                if (
                    Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), ['json', 'body'], true)
                    && (
                        $this->isSaloonSendCall($node->var)
                        || (
                            Node::is($node->var, 'expression.variable')
                            && is_string($node->var->name)
                            && isset($this->state->responseVariables[$node->var->name])
                        )
                    )
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isSaloonSendCall(Node $node): bool
            {
                return Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), ['send', 'sendAsync'], true);
            }
        });

        return array_map(
            fn (int $line): AuditFinding => new AuditFinding(
                'warn',
                'saloon',
                $file->path,
                $line,
                'Code outside app/Http/Integrations/** must not consume raw Saloon responses with ->json() or ->body(); define createDtoFromResponse() and use dto()/dtoOrFail().',
            ),
            array_values(array_unique($state->lines)),
        );
    }
}
