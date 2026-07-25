<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Install;

use GracjanKubicki\ArchitectureKit\Install\Mcp\McpServerConfigValidator;
use PHPUnit\Framework\TestCase;

final class McpServerConfigValidatorTest extends TestCase
{
    public function test_json_requires_the_invocation_in_command_or_arguments(): void
    {
        $validator = new McpServerConfigValidator;

        $this->assertTrue($validator->jsonInvokesArchitectureKit([
            'command' => './bin/custom-mcp',
            'args' => ['php', 'artisan', 'architecture-kit:mcp'],
        ]));
        $this->assertFalse($validator->jsonInvokesArchitectureKit([
            'command' => 'node',
            'args' => ['unrelated-server.js'],
            'env' => ['EXAMPLE' => 'architecture-kit:mcp'],
        ]));
        $this->assertFalse($validator->jsonInvokesArchitectureKit([
            'command' => 'node',
            'args' => ['unused' => 'architecture-kit:mcp'],
        ]));
    }

    public function test_toml_accepts_multiline_arguments_but_ignores_comments(): void
    {
        $validator = new McpServerConfigValidator;

        $this->assertTrue($validator->tomlInvokesArchitectureKit(<<<'TOML'
[mcp_servers.architecture-kit]
command = "docker"
args = [
    "compose",
    "exec",
    "php",
    "artisan",
    "architecture-kit:mcp",
]
TOML));
        $this->assertFalse($validator->tomlInvokesArchitectureKit(<<<'TOML'
[mcp_servers.architecture-kit]
command = "node"
args = ["unrelated-server.js"]
# "architecture-kit:mcp"
TOML));
        $this->assertFalse($validator->tomlInvokesArchitectureKit(<<<'TOML'
[mcp_servers.architecture-kit]
command = "node"
args = [
    "unrelated-server.js",
    # "architecture-kit:mcp"
]
TOML));
    }

    public function test_toml_rejects_a_malformed_arguments_assignment(): void
    {
        $this->assertFalse((new McpServerConfigValidator)->tomlInvokesArchitectureKit(<<<'TOML'
[mcp_servers.architecture-kit]
command = "architecture-kit:mcp"
args = "not-an-array"
TOML));
    }
}
