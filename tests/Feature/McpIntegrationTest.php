<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ExplainFinding;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Facades\Mcp;

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
                ->where('architectures.0.sum', fn (string $summary): bool => str_contains($summary, 'Actions are final application use cases'))
                ->where('guideline', fn (string $guideline): bool => str_contains($guideline, '### Actions'))
                ->where('guideline', fn (string $guideline): bool => str_contains($guideline, 'Good example:'))
            );
    }

    public function test_guard_tool_returns_guard_state(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);

        ArchitectureKitServer::tool(Guard::class, ['changed' => false, 'strict' => true])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('cmd', 'guard')
                ->where('doctor', 'ok')
                ->where('audit', 'ok')
                ->where('err', 0)
                ->etc()
            );
    }

    public function test_audit_changed_tool_returns_agent_payload_with_structured_content_and_text_fallback(): void
    {
        $this->writeCurrentResources([
            Architecture::ThinControllers,
            Architecture::Actions,
        ]);

        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class DocumentController
{
    public function update($document): void
    {
        $document->update(['name' => 'changed']);
    }
}
PHP);

        ArchitectureKitServer::tool(AuditChanged::class, ['changed' => false])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'audit')
                ->where('find.0.m', 'E_THIN_CONTROLLER_MODEL_WRITE')
                ->etc()
            )
            ->assertSee('E_THIN_CONTROLLER_MODEL_WRITE');
    }

    public function test_explain_finding_tool_returns_agent_explanation_for_codes(): void
    {
        ArchitectureKitServer::tool(ExplainFinding::class, ['code' => 'E_THIN_CONTROLLER_MODEL_WRITE'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('cmd', 'explain')
                ->where('rule', 'thin-controller')
                ->where('title', 'Controller writes through an Eloquent model')
                ->etc()
            );
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    private function writeCurrentResources(array $enabled): void
    {
        $files = new Filesystem;
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

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
