<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Resources;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDiagnostics;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use Throwable;

#[Name('architecture-kit-analysis-engine')]
#[Description('Selected analysis engine, version, implementation, project PHP and configuration origin.')]
#[Uri('architecture-kit://analysis-engine')]
#[MimeType('application/json')]
final class AnalysisEngineResource extends Resource
{
    public function handle(): Response
    {
        try {
            return Response::text(json_encode(AnalysisDiagnostics::current(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $error) {
            return Response::error($error->getMessage());
        }
    }
}
