<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('architecture-map')]
#[Description('Prepare the shared Basic map, inspect status, or collect result metadata. A building response includes job_id; continue ordinary source search while waiting, then call result with that job_id. Building never proves the time budget passed. Ready, freshness, checks_complete and cache persistence are separate. The repository, profile and backend are pinned by this server.')]
#[IsReadOnly]
final class ArchitectureMap extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['prepare', 'status', 'result'])->required(),
            'job_id' => $schema->string()->description('Required for status and result. Use the job returned by prepare.')];
    }

    public function handle(Request $request): ResponseFactory
    {
        try {
            $action = $request->get('action');
            $job = $request->get('job_id');
            if (! is_string($action) || $job !== null && (! is_string($job) || ! preg_match('/^[a-f0-9]{32}$/D', $job))) {
                throw new \RuntimeException('Invalid map action or job_id.');
            }

            return Response::structured((new GraphRuntime(RepositoryContext::current() ?? throw new \RuntimeException('Map runtime requires an explicit repository context and tool home.')))->map($action, $job));
        } catch (Throwable $error) {
            return Response::structured(['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified',
                'checks_complete' => false, 'reason' => $error->getMessage()]);
        }
    }
}
