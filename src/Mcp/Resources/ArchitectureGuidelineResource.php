<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use Taqie\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;

#[Name('architecture-kit-guideline')]
#[Description('Generated Architecture Kit guideline for the current project.')]
#[Uri('architecture-kit://guideline')]
#[MimeType('text/markdown')]
class ArchitectureGuidelineResource extends Resource
{
    use UsesArchitectureKitState;

    public function handle(): Response
    {
        return Response::text($this->guideline());
    }
}
