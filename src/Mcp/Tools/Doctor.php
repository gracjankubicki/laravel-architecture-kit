<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Taqie\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use Taqie\ArchitectureKit\Support\AgentOutput;

#[Name('doctor')]
#[Description('Inspect Architecture Kit config and generated resources without changing files.')]
#[IsReadOnly]
class Doctor extends Tool
{
    use UsesArchitectureKitState;

    public function handle(Request $request): ResponseFactory
    {
        $agent = new AgentOutput;

        return Response::structured($agent->doctor(
            result: $this->doctor(),
            limit: $agent->limit($request->get('limit', 20)),
            full: (bool) $request->get('full', false),
        ));
    }
}
