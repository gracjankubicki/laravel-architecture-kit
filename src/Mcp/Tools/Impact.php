<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
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
use Throwable;

#[Name('impact')]
#[Description('To start from a database table, omit subject and supply table. Use table_match=contains for a literal substring, connection=default/dynamic/named:name and operation=read/write/schema/schema-read to narrow results. Inspect table_report usages, separate connections, paths, global unresolved boundaries, limits and freshness. Same-table uses never connect processes. Before changing a class, file or Class::method, inspect bounded direct and indirect relationships. Short names return candidates when ambiguous. Resolved, possible contract calls, references and class-only context are separate. Inspect uncertain evidence before dependent decisions. Inspect execution.routes for HTTP declarations and their precise handler-to-symbol chains, plus execution.unresolved, status and freshness. Inspect execution.flows and execution.flow_analysis for contextual paths through Artisan commands, scheduled tasks, jobs, chains, batches, events, listeners and Eloquent lifecycle events. Read mode, timing and conditions separately. Schedule filters, before/after callbacks and success/failure conditions have separate paths. Scheduler success after queue dispatch does not prove worker success. Model event suppression belongs to a path. Queue requests and registrations do not prove execution; inspect flow uncertainty and limits before dependent decisions. Route declarations do not prove active runtime routes. Sources are discovered automatically without booting the application. Inspect data.outgoing for table reads, writes and schema changes reachable from the subject and data.consumers for effects in callers, with separate subject_via and effect via paths. Shared tables never connect processes. Query preparation is not execution. Inspect partial DATA effects alongside unresolved, limits and freshness; connection names/default/dynamic are not database identities. Anonymous migration file selectors expose declared up/down schema changes, not applied migrations. Test candidates do not prove coverage; relationships alone are not BREAKING verdicts. Inspect execution.authorization in impact for precise Gate/policy rules, route and Form Request checks, conditions, boolean/exception usage, shared rule site counts and unresolved boundaries. Use path to expand individual authorization chains and reach for immutable continuation pages. Inline allowIf/denyIf bypass policies and hooks; missing checks are not security verdicts. Before relocating a file or renaming a class/namespace use change=move. Without target_class/target_path it inspects uses; with either or both it compares remaining uses and declared Composer autoload rules. It does not rewrite code. Inspect namespace dependencies, string registrations and manual includes separately. Before removal use change=delete for a method, class or entire PHP file. It simulates removal without editing files and evaluates remaining calls, fallbacks and contracts. For a method change use change=signature, optionally supply a PHP signature without body. Breaking rows prove supported static incompatibilities; inspect check rows, types and limits. No breaking rows does not prove safety. No runtime execution. Increase limit/depth to expand a truncated report.')]
#[IsReadOnly]
final class Impact extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['change' => $schema->string()->enum(['signature', 'delete', 'move']), 'target_class' => $schema->string()->description('Target fully qualified class name for change=move. Requires a single selected class declaration.'), 'target_path' => $schema->string()->description('Project-relative PHP destination for change=move. Relocates every declaration in the source file.'), 'signature' => $schema->string()->description('Single PHP method declaration without a body. Implies change=signature. Use fully qualified class types.'), 'subject' => $schema->string()->description('Class, method or PHP file; omit for table queries.'), 'table' => $schema->string()->description('Literal DATA table name; mutually exclusive with subject and change proposals.'), 'table_match' => $schema->string()->enum(['exact', 'contains'])->description('Exact case-sensitive full name by default; contains is a literal substring.'), 'connection' => $schema->string()->description('Table filter: default, dynamic, or named:connection. Omit to show separate connections.'), 'operation' => $schema->string()->enum(['read', 'write', 'schema', 'schema-read'])->description('Table effect kind filter.'), 'limit' => $schema->integer()->min(0)->max(500)->default(20), 'depth' => $schema->integer()->min(1)->max(32)->default(4)];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['table' => 'string', 'table_match' => 'string', 'connection' => 'string', 'operation' => 'string', 'subject' => 'string', 'change' => 'string', 'signature' => 'string', 'target_class' => 'string', 'target_path' => 'string', 'limit' => 'integer', 'depth' => 'integer'])) !== null) {
            return Response::structured(ArchitectureImpact::error('E_INVALID_TOOL_INPUT', $message));
        }
        try {
            $state = DiscoverySettings::load($this->files(), base_path());
            $result = (new ArchitectureImpact($this->files(), base_path(), $state->scope, $state->cache, $state->fingerprint))
                ->inspect($request->get('subject', ''), $state->exclude, $request->get('limit', 20), $request->get('depth', 4), $request->get('change'), $request->get('signature'), $request->get('target_class'), $request->get('target_path'), $request->get('table'), $request->get('table_match'), $request->get('connection'), $request->get('operation'));
        } catch (Throwable $exception) {
            $result = ArchitectureImpact::error('E_IMPACT_FAILED', $exception->getMessage());
        }

        return Response::structured($result);
    }
}
