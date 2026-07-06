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
