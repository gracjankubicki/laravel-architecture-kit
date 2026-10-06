<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Revision\ArchitectureRevisionDiff;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('revision-diff')]
#[Description('Compare architecture from Git to working sources or between two Git revisions. Read separate symbol, structural, HTTP, execution and DATA differences with source witnesses, certainty and conditions. Each state uses its own source-only configuration unless shared_config explicitly selects before/after. Unknown historical settings never fall back to current configuration. Scope loss is not deletion. Confirm candidate identities with manual_pairs. Requires a project Git checkout. No checkout, source execution, cache writes or architecture quality/runtime verdict. Inspect partial analysis, notices, lower bounds and source freshness; rerun stale working inputs.')]
#[IsReadOnly]
final class RevisionDiff extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['from' => $schema->string()->required(), 'to' => $schema->string()->default('working'),
            'shared_config' => $schema->string()->enum(['before', 'after']), 'manual_pairs' => $schema->object(),
            'limit' => $schema->integer()->min(0)->max(500)->default(50)];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['v' => $schema->integer()->enum([1])->required(), 'cmd' => $schema->string()->enum(['revision-diff'])->required(),
            'ok' => $schema->boolean()->required(), 'sources' => $schema->object(), 'analysis' => $schema->object(),
            'changes' => $schema->object(), 'totals' => $schema->object(), 'configuration_sources' => $schema->object(),
            'metrics' => $schema->object(), 'notices' => $schema->array()->items($schema->object()),
            'limitations' => $schema->array()->items($schema->string()), 'next' => $schema->array()->items($schema->string()),
            'm' => $schema->string(), 'msg' => $schema->string()];
    }

    public function handle(Request $request): ResponseFactory
    {
        $invalid = $this->invalidInput($request, ['from' => 'string', 'to' => 'string', 'shared_config' => 'string', 'limit' => 'integer']);
        $pairs = $request->get('manual_pairs', []);
        if ($invalid !== null || ! is_array($pairs)) {
            return Response::structured(ArchitectureRevisionDiff::error('E_REVISION_INPUT', $invalid ?? 'manual_pairs must map old symbols to new symbols.'));
        }

        return Response::structured((new ArchitectureRevisionDiff(base_path()))->compare(
            $request->get('from', ''), $request->get('to', 'working'), $request->get('shared_config'), $pairs, $request->get('limit', 50),
        ));
    }
}
