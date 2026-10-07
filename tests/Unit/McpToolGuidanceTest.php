<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\ToolGuidance;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Laravel\Mcp\Server\Transport\FakeTransporter;

final class McpToolGuidanceTest extends TestCase
{
    public function test_actual_tools_list_matches_the_authored_copy_pack_for_every_tool_and_parameter(): void
    {
        $copy = $this->copy();
        $transport = new class extends FakeTransporter
        {
            public array $messages = [];

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->messages[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            }
        };
        $server = new ArchitectureKitServer($transport);
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]], JSON_THROW_ON_ERROR));
        $tools = array_column($transport->messages[0]['result']['tools'], null, 'name');
        $this->assertCount(count($copy['tools']), $tools);
        foreach ($copy['tools'] as $name => $expected) {
            $this->assertArrayHasKey($name, $tools);
            $this->assertSame($expected['description'], $tools[$name]['description'], $name);
            $properties = $tools[$name]['inputSchema']['properties'] ?? [];
            $this->assertCount(count($expected['parameters']), $properties, $name);
            foreach ($expected['parameters'] as $parameter => $description) {
                $this->assertSame($description, $properties[$parameter]['description'] ?? null, $name.'.'.$parameter);
            }
        }
        $instructions = new \ReflectionProperty(ArchitectureKitServer::class, 'instructions');
        $this->assertSame($copy['serverInstructions'], $instructions->getDefaultValue());
        $this->assertSame($copy['blocks']['server'], $server->createContext()->instructions);
    }

    public function test_shared_graph_guidance_is_distributed_to_compact_full_and_boost_resources(): void
    {
        $copy = $this->copy();
        $this->assertSame($copy['guidance']['GRAPH_WORKFLOW'], ToolGuidance::GRAPH_WORKFLOW);
        $this->assertSame($copy['guidance']['RESULT_INTERPRETATION'], ToolGuidance::RESULT_INTERPRETATION);
        $root = dirname(__DIR__, 2);
        $resources = new ArchitectureResources($root, $this->tempPath);
        $boost = view()->file($root.'/resources/boost/guidelines/core.blade.php')->render();
        $compact = $resources->guideline([Architecture::Actions])->contents;
        $full = $resources->fullGuideline([Architecture::Actions]);
        $this->assertSame($copy['blocks']['boost'], rtrim($boost));
        $this->assertStringContainsString($copy['blocks']['compact'], $compact);
        $this->assertStringContainsString($copy['blocks']['beforeFinishing'], $compact);
        $this->assertStringContainsString($copy['blocks']['server'], $full);
        foreach ([$compact, $full, $boost] as $text) {
            foreach ($copy['guidance'] as $block) {
                $this->assertStringContainsString($block, $text);
            }
        }
    }

    private function copy(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/Mcp/tool-guidance.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
