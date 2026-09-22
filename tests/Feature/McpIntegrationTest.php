<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use Composer\InstalledVersions;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureKit;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Doctor;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\EnabledArchitectures;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ExplainFinding;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\FileRules;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\PlanUpgrade;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Scaffold;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Resources\UpgradeGuideResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Symfony\Component\Process\Process;

class McpIntegrationTest extends TestCase
{
    public function test_incomplete_laravel_ai_analysis_has_the_same_cli_and_mcp_finding(): void
    {
        $this->writeLaravelAiFixture('^0.11', '0.11.2');
        $this->writeCurrentResources([Architecture::LaravelAi]);
        $this->writeFile('app/Ai/Gateways/DynamicGateway.php', <<<'PHP'
<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Contracts\Agent;

final class DynamicGateway
{
    public function run(Agent $agent, string $method): void
    {
        $agent->{$method}('dynamic');
    }
}
PHP);
        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('W_LARAVEL_AI_ANALYSIS_INCOMPLETE', $payload['find'][0]['m']);

        foreach ([AuditChanged::class, Guard::class] as $tool) {
            ArchitectureKitServer::tool($tool, ['changed' => false, 'strict' => true])
                ->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('ok', false)
                    ->where('find', $payload['find'])
                    ->etc());
        }
    }

    public function test_incomplete_test_analysis_has_the_same_cli_and_mcp_finding(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);
        $this->writeFile('config/architectures.php', "<?php return ['enabled' => ['actions'], 'audit' => ['missing_test' => 'warn']];");
        $this->writeFile('tests/Feature/DynamicTest.php', "<?php it('dynamic', function () { \$this->get(\$url); });");
        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        foreach ([AuditChanged::class, Guard::class] as $tool) {
            ArchitectureKitServer::tool($tool, ['changed' => false, 'strict' => true])
                ->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('ok', false)
                    ->where('find', $payload['find'])
                    ->etc());
        }
    }

    public function test_mcp_audit_exposes_non_blocking_suggestions_and_analysis(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);
        $this->withRoutes('<?php Illuminate\Support\Facades\Route::get("/invoices", [\App\Http\Controllers\InvoiceController::class, "show"]);');
        $this->writeFile('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->writeFile('app/Http/Controllers/InvoiceController.php', '<?php namespace App\Http\Controllers; final class InvoiceController { public function show(\App\Models\Invoice $invoice) { $invoice->save(); } }');

        ArchitectureKitServer::tool(AuditChanged::class, ['changed' => false, 'strict' => true])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('suggestions.items.0.code', 'S_MOVE_WRITE_TO_ACTION')
                ->where('analysis.status', 'complete')
                ->etc()
            );
    }

    public function test_mcp_server_version_is_read_from_composer_metadata(): void
    {
        $server = new ArchitectureKitServer(new FakeTransporter);
        $context = $server->createContext();

        $this->assertSame(ArchitectureKit::version(), $context->implementation->version);
    }

    public function test_mcp_server_instructions_require_enabled_architectures_preflight(): void
    {
        $instructions = new \ReflectionProperty(ArchitectureKitServer::class, 'instructions');

        $this->assertStringContainsString('first Architecture Kit MCP call MUST be enabled-architectures', $instructions->getDefaultValue());
        $this->assertStringContainsString('Do not implement architecture-sensitive code before this preflight', $instructions->getDefaultValue());
    }

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

    public function test_enabled_architectures_tool_reports_the_resolved_laravel_ai_profile(): void
    {
        $this->writeLaravelAiFixture('^0.11', '0.11.2');
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([Architecture::LaravelAi]);

        ArchitectureKitServer::tool(EnabledArchitectures::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('architectures.0.value', 'laravel-ai')
                ->where('laravel_ai.status', 'supported')
                ->where('laravel_ai.profile', 'laravel-ai@0.11')
                ->where('laravel_ai.installed_version', '0.11.2')
                ->etc()
            );
    }

    public function test_inertia_guidance_and_profile_are_available_through_mcp(): void
    {
        $this->writeInertiaFixture('^3.0', '3.1.0');
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([Architecture::Inertia]);

        ArchitectureKitServer::tool(EnabledArchitectures::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('architectures.0.value', 'inertia')
                ->where('architectures.0.skill', 'architecture-kit-inertia')
                ->where('inertia.status', 'supported')
                ->where('inertia.profile', 'inertia@3')
                ->etc()
            );

        ArchitectureKitServer::tool(ArchitectureRules::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('inertia.profile', 'inertia@3')
                ->where('architectures.0.value', 'inertia')
                ->where('architectures.0.skill', 'architecture-kit-inertia')
                ->where('guideline', fn (string $guideline): bool => str_contains($guideline, '## Shared and incremental data')
                    && str_contains($guideline, 'The `inertia` audit rule enforces two boundaries'))
                ->etc()
            );
    }

    public function test_fortify_guidance_and_profile_are_available_through_mcp_with_or_without_inertia(): void
    {
        $this->writeFortifyFixture('^1.0', '1.31.0');
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([Architecture::Fortify]);

        ArchitectureKitServer::tool(EnabledArchitectures::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('architectures.0.value', 'fortify')
                ->where('architectures.0.skill', 'architecture-kit-fortify')
                ->where('fortify.status', 'supported')
                ->where('fortify.profile', 'fortify@1')
                ->etc()
            );

        ArchitectureKitServer::tool(ArchitectureRules::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('fortify.profile', 'fortify@1')
                ->where('guideline', fn (string $guideline): bool => str_contains($guideline, '## Native extension contracts')
                    && str_contains($guideline, 'Inertia is optional'))
                ->etc()
            );

        $this->writeInertiaFixture('^3.0', '3.1.0');
        $this->writeFortifyFixture('^1.0', '1.31.0');
        $files = new Filesystem;
        $files->put($this->tempPath.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'gracjankubicki/laravel-architecture-kit', 'version' => '0.2.0'],
                ['name' => 'inertiajs/inertia-laravel', 'version' => '3.1.0'],
                ['name' => 'laravel/fortify', 'version' => '1.31.0'],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        $files->put($this->tempPath.'/vendor/composer/installed.php', <<<'PHP'
<?php
return ['versions' => [
    'inertiajs/inertia-laravel' => ['pretty_version' => '3.1.0'],
    'laravel/fortify' => ['pretty_version' => '1.31.0'],
]];
PHP);
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([
            Architecture::Inertia,
            Architecture::Fortify,
        ]);

        ArchitectureKitServer::tool(EnabledArchitectures::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('inertia.status', 'supported')
                ->where('inertia.profile', 'inertia@3')
                ->where('fortify.status', 'supported')
                ->where('fortify.profile', 'fortify@1')
                ->etc()
            );
    }

    public function test_enabled_architectures_tool_returns_scoped_custom_audit_rules(): void
    {
        $this->writeCustomArchitecture('billing-workflows');
        $this->writeCurrentResources(['billing-workflows']);
        $this->writeFile('config/architectures.php', <<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Tests\Fixtures\ForbiddenWorkflowAuditRule;

return [
    'enabled' => [
        'billing-workflows',
    ],
    'rules' => [
        'billing-workflows' => [
            ForbiddenWorkflowAuditRule::class,
        ],
    ],
];
PHP);

        ArchitectureKitServer::tool(EnabledArchitectures::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('architectures', 1)
                ->where('architectures.0.value', 'billing-workflows')
                ->where('architectures.0.rules.0', 'ForbiddenWorkflowAuditRule')
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

    public function test_architecture_rules_tool_reads_one_config_snapshot_per_request(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);
        $files = new Filesystem;
        $path = $this->tempPath.'/config/architectures.php';
        $contents = $files->get($path);
        $files->put($path, str_replace(
            '<?php',
            "<?php\n\n\$GLOBALS['architecture_kit_config_reads'] = (\$GLOBALS['architecture_kit_config_reads'] ?? 0) + 1;",
            $contents,
        ));
        $GLOBALS['architecture_kit_config_reads'] = 0;

        ArchitectureKitServer::tool(ArchitectureRules::class)->assertOk();

        $this->assertSame(1, $GLOBALS['architecture_kit_config_reads']);
        unset($GLOBALS['architecture_kit_config_reads']);
    }

    public function test_doctor_tool_detects_boost_from_composer_without_a_console_application(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);
        $installedPath = dirname(__DIR__, 2).'/vendor/composer/installed.php';
        $installed = require $installedPath;
        $withBoost = $installed;
        $withBoost['versions']['laravel/boost'] = [
            'pretty_version' => 'v1.0.0',
            'version' => '1.0.0.0',
            'reference' => null,
            'type' => 'library',
            'install_path' => $this->tempPath.'/vendor/laravel/boost',
            'aliases' => [],
            'dev_requirement' => true,
        ];
        InstalledVersions::reload($withBoost);

        try {
            ArchitectureKitServer::tool(Doctor::class)
                ->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('boost.installed', true)
                    ->where('boost.sync', 'php artisan boost:update --no-interaction')
                    ->etc()
                );
        } finally {
            InstalledVersions::reload($installed);
        }
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

    public function test_guard_and_audit_changed_tools_report_memory_budget_failures_as_structured_errors(): void
    {
        $this->writeCurrentResources([Architecture::Actions]);
        $this->writeFile('app/Actions/DocumentAction.php', '<?php final class DocumentAction {}');

        $previousMemoryLimit = ini_get('memory_limit');
        $baselineMemory = memory_get_usage(true);
        $memoryPadding = str_repeat('x', 24 * 1024 * 1024);

        try {
            $this->assertNotFalse(ini_set('memory_limit', (string) ($baselineMemory + 32 * 1024 * 1024)));

            ArchitectureKitServer::tool(Guard::class, ['changed' => false, 'strict' => true])
                ->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('ok', false)
                    ->where('cmd', 'guard')
                    ->where('m', 'E_COMMAND_FAILED')
                    ->where('msg', fn (string $message): bool => str_contains($message, 'Audit memory budget exceeded'))
                    ->etc()
                );

            $this->assertNotFalse(ini_set('memory_limit', (string) ($baselineMemory + 32 * 1024 * 1024)));

            ArchitectureKitServer::tool(AuditChanged::class, ['changed' => false])
                ->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->where('ok', false)
                    ->where('cmd', 'audit')
                    ->where('m', 'E_COMMAND_FAILED')
                    ->where('msg', fn (string $message): bool => str_contains($message, 'Audit memory budget exceeded'))
                    ->etc()
                );
        } finally {
            ini_set('memory_limit', (string) $previousMemoryLimit);
        }
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

use App\Models\Document;

final class DocumentController
{
    public function update(Document $document): void
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

    public function test_tools_publish_typed_input_schemas(): void
    {
        $tools = (new ArchitectureKitServer(new FakeTransporter))
            ->createContext()
            ->tools()
            ->mapWithKeys(fn ($tool): array => [$tool->name() => $tool->toArray()]);

        $this->assertSame('boolean', $tools['audit-changed']['inputSchema']['properties']['changed']['type']);
        $this->assertSame('integer', $tools['guard']['inputSchema']['properties']['limit']['type']);
        $this->assertSame('string', $tools['explain-finding']['inputSchema']['properties']['code']['type']);
        $this->assertContains('code', $tools['explain-finding']['inputSchema']['required']);
        $this->assertArrayNotHasKey('rule', $tools['explain-finding']['inputSchema']['properties']);
        $this->assertSame('integer', $tools['doctor']['inputSchema']['properties']['limit']['type']);
        $this->assertSame('string', $tools['plan-upgrade']['inputSchema']['properties']['package']['type']);
        $this->assertSame('string', $tools['plan-upgrade']['inputSchema']['properties']['target']['type']);
        $this->assertContains('package', $tools['plan-upgrade']['inputSchema']['required']);
        $this->assertContains('target', $tools['plan-upgrade']['inputSchema']['required']);
        $this->assertSame('string', $tools['architecture-context']['inputSchema']['properties']['subject']['type']);
        $this->assertSame('integer', $tools['architecture-context']['inputSchema']['properties']['limit']['type']);
        $this->assertContains('subject', $tools['architecture-context']['inputSchema']['required']);
    }

    public function test_plan_upgrade_tool_returns_the_same_atomic_route_as_the_cli_without_writing(): void
    {
        $this->writeLaravelAiFixture('^0.8', '0.8.1');
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([Architecture::LaravelAi]);
        $files = new Filesystem;

        foreach ((new UpgradeGuideResources(dirname(__DIR__, 2), $this->tempPath, $files))->skills([Architecture::LaravelAi]) as $skill) {
            $files->ensureDirectoryExists(dirname($skill->path));
            $files->put($skill->path, $skill->contents);
        }

        $before = $this->snapshotProject($files);

        ArchitectureKitServer::tool(PlanUpgrade::class, [
            'package' => 'laravel/ai',
            'target' => '0.11',
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('cmd', 'upgrade-plan')
                ->where('status', 'ready')
                ->where('route.0.status', 'ready')
                ->where('route.1.status', 'pending')
                ->where('route.2.status', 'pending')
                ->where('active.skill', 'architecture-kit-upgrade-laravel-ai-0-8-to-0-9')
                ->etc()
            );

        $this->assertSame($before, $this->snapshotProject($files));
    }

    public function test_json_rpc_initialize_and_tools_list_publish_the_real_contract(): void
    {
        $transport = new class implements Transport
        {
            /** @var array<int, string> */
            public array $messages = [];

            public function onReceive(\Closure $handler): void {}

            public function run(): never
            {
                throw new \LogicException('The test drives JSON-RPC messages directly.');
            }

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->messages[] = $message;
            }

            public function sessionId(): string
            {
                return 'test-session';
            }

            public function stream(\Closure $stream): void
            {
                $stream();
            }
        };

        $server = new ArchitectureKitServer($transport);
        $protocol = ProtocolVersion::supported()[0];

        $server->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => $protocol, 'capabilities' => []],
        ], JSON_THROW_ON_ERROR));
        $server->handle(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], JSON_THROW_ON_ERROR));

        $initialize = json_decode($transport->messages[0], true, flags: JSON_THROW_ON_ERROR);
        $tools = json_decode($transport->messages[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(ArchitectureKit::version(), $initialize['result']['serverInfo']['version']);
        $this->assertTrue(collect($tools['result']['tools'])->contains(fn (array $tool): bool => $tool['name'] === 'audit-changed' && isset($tool['inputSchema']['properties']['changed'])));
    }

    public function test_stdio_server_completes_initialize_and_tools_list(): void
    {
        $files = new Filesystem;
        $bootstrap = $this->tempPath.'/mcp-stdio.php';
        $root = dirname(__DIR__, 2);

        $script = <<<'PHP'
<?php

ini_set('display_errors', 'stderr');

require __ROOT__ . '/vendor/autoload.php';

$app = new Illuminate\Foundation\Application(getcwd());
$app->instance('config', new Illuminate\Config\Repository(['app' => ['debug' => false]]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
Illuminate\Container\Container::setInstance($app);

$transport = new Laravel\Mcp\Server\Transport\StdioTransport('test-session');
$server = new GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer($transport);
$server->start();
$transport->run();
PHP;
        $files->put($bootstrap, str_replace('__ROOT__', var_export($root, true), $script));

        $protocol = ProtocolVersion::supported()[0];
        $initialize = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => $protocol, 'capabilities' => []],
        ], JSON_THROW_ON_ERROR);
        $toolsList = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], JSON_THROW_ON_ERROR);
        $command = sprintf(
            "{ printf '%%s\\n%%s\\n' %s %s; sleep 1; } | %s %s",
            escapeshellarg($initialize),
            escapeshellarg($toolsList),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bootstrap),
        );

        $process = Process::fromShellCommandline($command, $this->tempPath);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'stderr: '.$process->getErrorOutput().' stdout: '.$process->getOutput());
        $responses = array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($process->getOutput())))),
        );

        $this->assertSame(ArchitectureKit::version(), $responses[0]['result']['serverInfo']['version']);
        $this->assertTrue(collect($responses[1]['result']['tools'])->contains(fn (array $tool): bool => $tool['name'] === 'architecture-rules'));
    }

    public function test_tools_reject_invalid_input_types_without_casting(): void
    {
        ArchitectureKitServer::tool(AuditChanged::class, ['changed' => 'yes'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'audit')
                ->where('m', 'E_INVALID_TOOL_INPUT')
                ->etc()
            );

        ArchitectureKitServer::tool(Doctor::class, ['limit' => '20'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'doctor')
                ->where('m', 'E_INVALID_TOOL_INPUT')
                ->etc()
            );

        ArchitectureKitServer::tool(ExplainFinding::class, ['code' => 123])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'explain')
                ->where('m', 'E_INVALID_TOOL_INPUT')
                ->etc()
            );

        ArchitectureKitServer::tool(PlanUpgrade::class, ['package' => 123, 'target' => '0.10'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'upgrade-plan')
                ->where('m', 'E_INVALID_TOOL_INPUT')
                ->etc()
            );
    }

    public function test_explain_finding_requires_a_code(): void
    {
        ArchitectureKitServer::tool(ExplainFinding::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'explain')
                ->where('m', 'E_MISSING_TOOL_INPUT')
                ->etc()
            );
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

    public function test_explain_finding_tool_resolves_the_occurrence_like_the_command(): void
    {
        // The tool and the command used to resolve this separately and disagreed in a
        // project without configuration, so the same input gave a different answer
        // depending on how it was asked.
        $this->writeFile('app/Http/Controllers/InvoiceController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

final class InvoiceController
{
    public function store(Invoice $invoice): void
    {
        $invoice->update(['total' => 1]);
    }
}
PHP);

        ArchitectureKitServer::tool(ExplainFinding::class, [
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            'path' => 'app/Http/Controllers/InvoiceController.php',
            'line' => 11,
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('cmd', 'explain')
                ->where('occurrence.symbol', 'App\\Http\\Controllers\\InvoiceController')
                ->where('occurrence.role', 'adapter')
                ->where('occurrence.line', 11)
                ->where('proposal.to', 'an Action invoked by the controller')
                ->etc()
            );
    }

    public function test_file_rules_tool_returns_only_rules_governing_the_path(): void
    {
        $this->writeCurrentResources(Architecture::defaultSelection());

        ArchitectureKitServer::tool(FileRules::class, ['path' => 'app/Actions/SendInvoice.php'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('cmd', 'file-rules')
                ->where('path', 'app/Actions/SendInvoice.php')
                ->where('scope', 'application')
                ->where('arch', function ($arch): bool {
                    $slugs = collect($arch)->pluck('slug')->all();

                    return in_array('actions', $slugs, true) && ! in_array('api-resources', $slugs, true);
                })
                ->etc()
            );
    }

    public function test_file_rules_tool_requires_a_path(): void
    {
        $this->writeCurrentResources(Architecture::defaultSelection());

        ArchitectureKitServer::tool(FileRules::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', false)
                ->where('cmd', 'file-rules')
                ->etc()
            );
    }

    public function test_scaffold_tool_returns_a_skeleton_without_writing_anything(): void
    {
        $this->writeCurrentResources(Architecture::defaultSelection());

        ArchitectureKitServer::tool(Scaffold::class, ['architecture' => 'actions', 'name' => 'SendInvoice'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('ok', true)
                ->where('cmd', 'make')
                ->where('written', false)
                ->where('class', 'App\\Actions\\SendInvoice')
                ->etc()
            );

        $this->assertFileDoesNotExist($this->tempPath.'/app/Actions/SendInvoice.php');
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function writeCurrentResources(array $enabled): void
    {
        $files = new Filesystem;
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $laravelAi = in_array(Architecture::LaravelAi, $enabled, true)
            ? LaravelAiRequirement::resolve($files, $this->tempPath)
            : null;
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files, laravelAi: $laravelAi);

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

    private function writeCustomArchitecture(string $slug): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/'.$slug);
        $files->put($this->tempPath.'/.architecture-kit/architectures/'.$slug.'/guideline.md', 'Custom architecture guideline.');
    }

    private function writeLaravelAiFixture(string $constraint, string $version): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'gracjankubicki/laravel-architecture-kit' => '^0.2',
                'laravel/ai' => $constraint,
            ],
        ], JSON_THROW_ON_ERROR));
        $files->put($this->tempPath.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'gracjankubicki/laravel-architecture-kit', 'version' => '0.2.0'],
                ['name' => 'laravel/ai', 'version' => $version],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        $files->put($this->tempPath.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$version}']]];\n");
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Responses');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Responses/StructuredAgentResponse.php', '<?php class StructuredAgentResponse implements ArrayAccess { public function toArray(): array {} }');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Concerns');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Concerns/ProviderOptions.php', '<?php trait ProviderOptions { public function withProviderOptions(array $options): static {} }');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Approvals');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Approvals/Decisions.php', '<?php class Decisions {}');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Contracts');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Contracts/ConversationStore.php', '<?php interface ConversationStore { public function storeApprovalResults(): void; }');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Exceptions');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Exceptions/ProviderConnectionException.php', '<?php class ProviderConnectionException implements FailoverableException {}');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Exceptions/StreamErrorException.php', '<?php class StreamErrorException {}');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Promptable.php', '<?php trait Promptable { public function queue() { return InvokeAgent::dispatch(); } public function broadcastOnQueue() { return BroadcastAgent::dispatch(); } }');
    }

    /** @return array<string, string> */
    private function snapshotProject(Filesystem $files): array
    {
        $snapshot = [];

        foreach ($files->allFiles($this->tempPath) as $file) {
            $path = str_replace($this->tempPath.'/', '', $file->getPathname());
            $snapshot[$path] = hash_file('sha256', $file->getPathname());
        }

        ksort($snapshot);

        return $snapshot;
    }
}
