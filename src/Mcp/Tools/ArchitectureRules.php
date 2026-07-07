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

#[Name('architecture-rules')]
#[Description('Return the full generated Architecture Kit guideline for enabled architectures.')]
#[IsReadOnly]
class ArchitectureRules extends Tool
{
    use UsesArchitectureKitState;

    public function handle(): ResponseFactory
    {
        return Response::structured([
            'guideline' => $this->guideline(),
            'architectures' => $this->architectureSummaries(),
        ]);
    }
}
