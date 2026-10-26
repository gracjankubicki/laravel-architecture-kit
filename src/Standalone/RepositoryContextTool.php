<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Throwable;

#[Name('repository-context')]
#[Description('Bind this family process once to the absolute task workspace, or inspect its status and current rule origin. A different root requires another process. No audit, map or task beginning is started.')]
final class RepositoryContextTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['bind', 'status'])->required(),
            'workspace' => $schema->string()->description('Absolute task directory, containing one member of this family or inside that member.')];
    }

    public function handle(Request $request): ResponseFactory
    {
        try {
            $binding = RepositoryBinding::current() ?? throw new RuntimeException('repository-context is available in --family mode.');
            if ($request->get('action') === 'bind') {
                $workspace = $request->get('workspace');
                if (! is_string($workspace) || $workspace === '') {
                    throw new RuntimeException('context_invalid: provide an absolute workspace.');
                }
                $binding->bind($workspace);
            } elseif ($request->get('action') !== 'status') {
                throw new RuntimeException('Unknown repository-context action.');
            }

            return Response::structured($binding->status());
        } catch (Throwable $error) {
            return Response::structured(['ok' => false, 'status' => 'unverified', 'reason' => $error->getMessage(),
                'code_root' => RepositoryBinding::current()?->root()]);
        }
    }
}
