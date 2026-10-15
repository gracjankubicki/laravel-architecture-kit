<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Resources\PackageGuidance;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

final class StandaloneServer extends ArchitectureKitServer
{
    public int $defaultPaginationLength = 50;

    protected function handleMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        $transport = $this->transport;
        if (! $transport instanceof ReportStdioTransport || $request->method !== 'tools/call'
            || ! in_array($request->params['name'] ?? null, ['audit-changed', 'guard'], true)
            || ($request->params['arguments']['full'] ?? false) !== true) {
            parent::handleMessage($request, $context);

            return;
        }
        // Keep SDK dispatch, validation and response shape. Only the final
        // encoding changes, preserving text and structuredContent on one line.
        try {
            $response = $this->runMethodHandle($request, $context);
            if (! is_iterable($response)) {
                $transport->sendReport($response);

                return;
            }
            $transport->stream(function () use ($response, $transport): void {
                foreach ($response as $message) {
                    $transport->sendReport($message);
                }
            });
        } finally {
            // A long-lived server must release the previous full response and
            // its allocator pages before the next audit uses the same budget.
            unset($response);
            gc_collect_cycles();
            gc_mem_caches();
        }
    }

    protected function boot(): void
    {
        if (($repository = RepositoryContext::current()) !== null) {
            $this->instructions = (new PackageGuidance(ProjectLayout::read($repository->rootPath), $repository->settings))->rewrite($this->instructions);
        }
        $this->tools[] = TaskTool::class;
        $this->instructions .= ' This standalone process is pinned to one repository. Before edits use task-control begin once, and bind its client/session when applicable. Retain the task beginning through retries and resume. Before finishing use task-control check and read analysis and reason: partial analysis warns by default, while audit.incomplete_analysis=block requires completeness. Pass means no new recognized errors, not complete analysis or passing tests. Failures, missing beginning and drift never pass. Repository execution and writes require explicit CLI opt-in. Source facts do not prove execution or tests.';
    }
}
