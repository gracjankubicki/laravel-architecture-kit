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

#[Name('audit-changed')]
#[Description('Audit changed application files against enabled Architecture Kit rules.')]
#[IsReadOnly]
class AuditChanged extends Tool
{
    use UsesArchitectureKitState;

    public function handle(Request $request): ResponseFactory
    {
        $audit = $this->audit(
            changedOnly: (bool) $request->get('changed', true),
            baseRef: $request->get('base') !== null ? (string) $request->get('base') : null,
        );
        $strict = (bool) $request->get('strict', false);

        $agent = new AgentOutput;

        return Response::structured($agent->audit(
            result: $audit,
            ok: $audit->errors() === 0 && (! $strict || $audit->warnings() === 0),
            limit: $agent->limit($request->get('limit', 20)),
            full: (bool) $request->get('full', false),
        ));
    }
}
