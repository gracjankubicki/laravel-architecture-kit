<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Audit\FindingOccurrence;
use GracjanKubicki\ArchitectureKit\Audit\FindingOccurrenceResolver;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
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
#[Description('Explain an Architecture Kit finding. Pass the reported path and line to get an explanation about that occurrence, naming the symbol at fault and, where the rule states a destination, a proposed change.')]
#[IsReadOnly]
class ExplainFinding extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required(),
            'path' => $schema->string()->description('Application-relative path the finding was reported for.'),
            'line' => $schema->integer()->description('Line the finding was reported on.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['code' => 'string', 'path' => 'string'])) !== null) {
            return $this->inputError('explain', $message);
        }

        $code = $request->get('code');

        if ($code === null || trim($code) === '') {
            return $this->inputError('explain', 'Provide a finding code.', 'E_MISSING_TOOL_INPUT');
        }

        $code = strtoupper($code);
        $explanation = (new FindingCodeRegistry)->explain($code, $this->occurrence($request));

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

    private function occurrence(Request $request): ?FindingOccurrence
    {
        $path = $request->get('path');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $line = $request->get('line');

        // Same resolver as the command: resolving this twice is how the two drifted.
        return (new FindingOccurrenceResolver($this->files(), $this->packagePath(), base_path()))
            ->resolve(trim($path), is_numeric($line) ? (int) $line : null);
    }
}
