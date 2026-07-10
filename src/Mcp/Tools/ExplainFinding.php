<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('explain-finding')]
#[Description('Explain an Architecture Kit finding code.')]
#[IsReadOnly]
class ExplainFinding extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['code' => 'string'])) !== null) {
            return $this->inputError('explain', $message);
        }

        $code = $request->get('code');

        if ($code === null || trim($code) === '') {
            return $this->inputError('explain', 'Provide a finding code.', 'E_MISSING_TOOL_INPUT');
        }

        $code = strtoupper($code);
        $explanation = (new FindingCodeRegistry)->explain($code);

        return Response::structured($explanation === null
            ? [
                'v' => 1,
                'ok' => false,
                'cmd' => 'explain',
                'code' => $code,
                'm' => 'E_UNKNOWN_FINDING_CODE',
                'next' => ['rerun:audit --agent', 'use_known_finding_code'],
            ]
            : [
                'v' => 1,
                'ok' => true,
                'cmd' => 'explain',
                ...$explanation,
            ]);
    }
}
