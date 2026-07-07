<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use Illuminate\Console\Command;

class McpCommand extends Command
{
    protected $signature = 'architecture-kit:mcp';

    protected $description = 'Start the Architecture Kit MCP server.';

    public function handle(): int
    {
        return $this->call('mcp:start', ['handle' => 'architecture-kit']);
    }
}
