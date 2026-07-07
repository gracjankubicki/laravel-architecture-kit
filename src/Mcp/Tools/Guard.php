<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('guard')]
#[Description('Run the same Architecture Kit gate used by hooks and CLI.')]
#[IsReadOnly]
class Guard extends Tool
{
    use UsesArchitectureKitState;

    public function handle(Request $request): ResponseFactory
    {
        $agent = new AgentOutput;

        return Response::structured($agent->guard($this->guard(
            changedOnly: (bool) $request->get('changed', true),
            baseRef: $request->get('base') !== null ? (string) $request->get('base') : null,
            strict: (bool) $request->get('strict', true),
        ), $agent->limit($request->get('limit', 20)), (bool) $request->get('full', false)));
    }
}
