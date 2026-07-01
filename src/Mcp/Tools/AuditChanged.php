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

        return Response::structured([
            'ok' => $audit->errors() === 0 && (! $strict || $audit->warnings() === 0),
            'scope' => $audit->scope,
            'errors' => $audit->errors(),
            'warnings' => $audit->warnings(),
            'findings' => array_map(
                fn ($finding): array => [
                    'severity' => $finding->severity,
                    'rule' => $finding->rule,
                    'path' => $finding->path,
                    'line' => $finding->line,
                    'message' => $finding->message,
                ],
                $audit->findings,
            ),
        ]);
    }
}
