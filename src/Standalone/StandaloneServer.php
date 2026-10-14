<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;

final class StandaloneServer extends ArchitectureKitServer
{
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $this->tools[] = TaskTool::class;
        $this->instructions .= ' This standalone process is pinned to one repository. Before edits use task-control begin once, and bind its client/session when applicable. Retain the task beginning through retries and resume. Before finishing use task-control check; incomplete, missing beginning and drift never pass. Repository execution and writes require explicit CLI opt-in. Source facts do not prove execution or tests.';
    }
}
