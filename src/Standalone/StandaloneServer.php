<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\ToolGuidance;
use GracjanKubicki\ArchitectureKit\Resources\PackageGuidance;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

final class StandaloneServer extends ArchitectureKitServer
{
    public int $defaultPaginationLength = 50;

    private bool $graphRequestSeen = false;

    protected function handleMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        if ($request->method === 'tools/call') {
            if ($this->graphRequestSeen) {
                GraphRuntime::beginRequest();
            }
            $this->graphRequestSeen = true;
        }
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

    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $dependent = in_array($request->method, ['tools/call', 'resources/read', 'prompts/get'], true)
            && ! ($request->method === 'tools/call' && ($request->params['name'] ?? null) === 'repository-context');
        if (! $dependent) {
            return parent::runMethodHandle($request, $context);
        }
        try {
            $observed = $request->method === 'tools/call'
                && in_array($request->params['name'] ?? '', ['architecture-map', 'architecture-search', 'architecture-graph', 'search', 'path', 'impact', 'reach', 'architecture-context'], true)
                && ($request->params['arguments']['analysis_mode'] ?? 'basic') === 'basic';
            $scope = RepositoryRequestScope::begin($observed);
        } catch (Throwable $error) {
            return $this->contextFailure($request, $error->getMessage());
        }
        $response = parent::runMethodHandle($request, $context);
        if (is_iterable($response)) {
            return (function () use ($response, $scope, $request): \Generator {
                foreach ($response as $message) {
                    yield $this->currentResponse($request, $message, $scope);
                }
            })();
        }

        return $this->currentResponse($request, $response, $scope);
    }

    private function currentResponse(JsonRpcRequest $request, JsonRpcResponse $response, ?RepositoryRequestScope $scope): JsonRpcResponse
    {
        try {
            $scope?->assertCurrent();

            return $response;
        } catch (Throwable $error) {
            return $this->contextFailure($request, 'stale_snapshot: '.$error->getMessage());
        }
    }

    private function contextFailure(JsonRpcRequest $request, string $reason): JsonRpcResponse
    {
        if ($request->method !== 'tools/call') {
            return JsonRpcResponse::error($request->id, -32002, $reason);
        }
        $result = ['ok' => false, 'status' => str_starts_with($reason, 'context_required:') ? 'context_required' : 'unverified',
            'freshness' => 'stale', 'reason' => $reason,
            'analysis' => ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false],
            'control' => ['policy' => 'advisory', 'blocking' => false]];

        return JsonRpcResponse::result($request->id, ['isError' => true, 'content' => [['type' => 'text',
            'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]], 'structuredContent' => $result]);
    }

    protected function boot(): void
    {
        if (RepositoryBinding::current() !== null) {
            $this->tools[] = RepositoryContextTool::class;
            $this->tools[] = TaskTool::class;
            $this->instructions = ToolGuidance::FAMILY_INSTRUCTIONS;

            return;
        }
        if (($repository = RepositoryContext::current()) !== null) {
            $this->instructions = (new PackageGuidance(ProjectLayout::read($repository->rootPath), $repository->settings))->rewrite($this->instructions);
        }
        $this->tools[] = TaskTool::class;
        $this->instructions .= ' This standalone process is pinned to one repository. Basic is the default source map and local-rule scope, including existing profiles. Full only expands output. Before Deep analysis ask the human for consent for this repository and task, record it with analysis-consent, then use analysis_mode=deep and task on MCP or --mode=deep --task on CLI. Refusal leaves Basic search available. Consent never grants runtime or custom PHP execution. For a relevant source-map gap, search and read the files, cite a concrete file and relationship or state the uncertainty. Ask a concrete question when missing information prevents choosing a correct change. Candidate tests are not coverage, execution or PASS. Try to correct new errors and report remaining failures and unverified checks; advisory permits completion. Enforce is an explicit separate policy. Before edits use task-control begin once, and bind its client/session when applicable. Retain the task beginning through retries and resume. Before finishing use task-control check and read analysis and reason: incomplete Basic checks remain unverified, with advisory reaction by default. Pass means no new recognized errors, not complete analysis or passing tests. Failures, missing beginning and drift never pass. Repository execution and writes require explicit CLI opt-in. Source facts do not prove execution or tests.';
    }
}
