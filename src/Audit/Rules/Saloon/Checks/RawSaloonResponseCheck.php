<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;

final readonly class RawSaloonResponseCheck implements FileCheck
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
                    $node instanceof Assign
                    && $node->var instanceof Variable
                    && is_string($node->var->name)
                    && $this->isSaloonSendCall($node->expr)
                ) {
                    $this->state->responseVariables[$node->var->name] = true;
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['json', 'body'], true)
                    && (
                        $this->isSaloonSendCall($node->var)
                        || (
                            $node->var instanceof Variable
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
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
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
