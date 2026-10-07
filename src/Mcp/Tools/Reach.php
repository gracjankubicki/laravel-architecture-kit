<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('reach')]
#[Description('Count recognized incoming and outgoing reach of a class, Class::method or PHP file. Code symbols, execution entry declarations and DATA operations have separate units. Resolved, possible and references remain separate; references are not calls. Read direct/exclusively_indirect counts, layer crossings, scope/depth, notices, freshness and lower bounds before decisions. Limit only sizes displayed lists; fixed analysis budgets remain explicit. Continue the same report using report_id and page without subject; source stat/list changes require a new report. Static project settings, no project code execution. Zero never proves no runtime users; crossings do not mean violations and test candidates do not prove coverage. Use impact for change proposals and path for A-to-B chains.')]
#[IsReadOnly]
final class Reach extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['subject' => $schema->string()->description('Class, Class::method or PHP path. Omit with report_id.'), 'limit' => $schema->integer()->min(0)->max(500)->default(20)->description('Maximum displayed records. Zero returns summary information without removing analysis limits.'), 'depth' => $schema->integer()->min(1)->max(32)->default(4)->description('Maximum reach-analysis hops, from 1 to 32. Read lower bounds and unresolved boundaries.'), 'report_id' => $schema->string()->description('Immutable report identifier from a previous reach response. Continue without subject.'), 'page' => $schema->integer()->min(1)->max(1000000)->default(1)->description('One-based continuation page within report_id. Source changes require a new report.')];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['subject' => 'string', 'limit' => 'integer', 'depth' => 'integer', 'report_id' => 'string', 'page' => 'integer'])) !== null) {
            return Response::structured(ArchitectureReach::error('E_INVALID_TOOL_INPUT', $message));
        }

        return Response::structured((new ArchitectureReach(app(Filesystem::class), base_path()))->inspect($request->get('subject', ''), $request->get('limit', 20), $request->get('depth', 4), $request->get('report_id'), $request->get('page', 1)));
    }
}
