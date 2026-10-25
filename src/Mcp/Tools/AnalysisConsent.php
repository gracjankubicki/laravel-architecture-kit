<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent as ConsentStore;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('analysis-consent')]
#[Description('Only after asking the human and obtaining consent, record Deep consent for this repository and task. confirm records the caller statement, not human authentication. Retry/resume retain consent; another task/repository needs new consent. This grants neither runtime nor custom PHP execution. Basic search remains available after refusal.')]
final class AnalysisConsent extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return ['task' => $schema->string()->required(), 'confirm' => $schema->boolean()->required()->description('Record previously obtained human consent; never infer it from another tool or profile.')];
    }

    public function handle(Request $request): ResponseFactory
    {
        try {
            if ($request->get('confirm') !== true || ! is_string($request->get('task'))) {
                throw new \RuntimeException('Obtain human consent first, then provide task and confirm=true.');
            }

            return Response::structured(['ok' => true, 'consent' => (new ConsentStore(RepositoryContext::consentContext()))->grantDeep($request->get('task'))]);
        } catch (Throwable $error) {
            return Response::structured(['ok' => false, 'reason' => $error->getMessage()]);
        }
    }
}
