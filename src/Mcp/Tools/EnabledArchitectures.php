<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Taqie\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;

#[Name('enabled-architectures')]
#[Description('Return enabled Architecture Kit enum cases, labels, skills, and source paths.')]
#[IsReadOnly]
class EnabledArchitectures extends Tool
{
    use UsesArchitectureKitState;

    public function handle(): ResponseFactory
    {
        return Response::structured([
            'architectures' => $this->architectureSummaries(),
        ]);
    }
}
