<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Facades\Mcp;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Mcp\ArchitectureKitServer;
use Taqie\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use Taqie\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use Taqie\ArchitectureKit\Mcp\Tools\Guard;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureResources;
use Taqie\ArchitectureKit\Tests\TestCase;

class McpIntegrationTest extends TestCase
{
    public function test_it_registers_local_mcp_server(): void
    {
        $this->assertNotNull(Mcp::getLocalServer('architecture-kit'));
    }

    public function test_it_registers_mcp_wrapper_command(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('architecture-kit:mcp')
            ->assertExitCode(0);
    }

    public function test_enabled_architectures_tool_returns_configured_architectures(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        ArchitectureKitServer::tool(EnabledArchitectures::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('architectures', 1)
                ->where('architectures.0.value', 'actions')
                ->where('architectures.0.skill', 'architecture-kit-actions')
            );
    }

    public function test_architecture_rules_tool_returns_generated_guideline(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        ArchitectureKitServer::tool(ArchitectureRules::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('architectures.0.value', 'actions')
                ->where('guideline', fn (string $guideline): bool => str_contains($guideline, '### Actions'))
            );
    }

    public function test_guard_tool_returns_guard_state(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        ArchitectureKitServer::tool(Guard::class, ['changed' => false, 'strict' => true])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('doctor.ok', true)
                ->where('audit.errors', 0)
                ->etc()
            );
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    private function writeCurrentResources(array $enabled): void
    {
        $files = new Filesystem();
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);

        $config->write($enabled);

        $generated = array_merge(
            [$resources->guideline($enabled)],
            array_values($resources->skills($enabled)),
        );

        foreach ($generated as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
    }
}
