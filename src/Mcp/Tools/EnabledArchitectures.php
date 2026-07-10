<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('enabled-architectures')]
#[Description('Return enabled Architecture Kit enum cases, labels, skills, and source paths.')]
#[IsReadOnly]
class EnabledArchitectures extends Tool
{
    use UsesArchitectureKitState;

    public function handle(): ResponseFactory
    {
        $state = $this->projectState();

        return Response::structured([
            'architectures' => $this->architectureSummaries($state),
        ]);
    }
}
