<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Throwable;

#[Name('task-control')]
#[Description('Before edits begin an explicitly named task once. Keep its immutable beginning through corrections and resume. Check before completion: only new errors block; missing/incomplete/drift never passes. Skip only on explicit human instruction, remains unverified. Bind a client session when starting another task; never refresh a failing beginning.')]
class TaskTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['begin', 'check', 'status', 'skip', 'bind'])->required(),
            'task' => $schema->string()->required(),
            'confirm' => $schema->boolean()->default(false),
            'client' => $schema->string()->enum(['codex', 'claude']),
            'session' => $schema->string(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        try {
            $context = RepositoryContext::current() ?? throw new RuntimeException('Standalone context is missing.');
            $task = $request->get('task');
            if (! is_string($task)) {
                throw new RuntimeException('Task must be a string.');
            }
            $control = new TaskControl($context);
            $result = match ($request->get('action')) {
                'begin' => $control->begin($task),
                'check' => $control->check($task),
                'status' => $control->status($task),
                'skip' => $control->skip($task, $request->get('confirm') === true),
                'bind' => SessionBinding::bind($context, $request->get('client'), $request->get('session'), $task),
                default => throw new RuntimeException('Unknown task action.'),
            };

            return Response::structured($result);
        } catch (Throwable $error) {
            return Response::structured(['ok' => false, 'status' => 'blocked', 'reason' => $error->getMessage()]);
        }
    }
}
