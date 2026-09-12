<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class FileRulesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write(Architecture::defaultSelection());
    }

    public function test_it_returns_only_the_rules_that_govern_the_path(): void
    {
        $payload = $this->agentPayload('app/Actions/SendInvoice.php');

        $this->assertTrue($payload['ok']);
        $this->assertSame('file-rules', $payload['cmd']);
        $this->assertSame('app/Actions/SendInvoice.php', $payload['path']);
        $this->assertSame('application', $payload['scope']);

        $slugs = array_column($payload['arch'], 'slug');
        $this->assertContains('actions', $slugs);
        $this->assertNotContains('api-resources', $slugs);
    }

    public function test_it_marks_guidance_without_any_rule_as_advisory(): void
    {
        $payload = $this->agentPayload('app/Actions/SendInvoice.php');
        $bySlug = array_column($payload['arch'], null, 'slug');

        $this->assertSame('advisory', $bySlug['laravel-best-practices']['enforcement']);
        $this->assertSame([], $bySlug['laravel-best-practices']['rules']);
        $this->assertSame('enforced', $bySlug['actions']['enforcement']);
        $this->assertContains('actions', $bySlug['actions']['rules']);
    }

    public function test_it_answers_for_a_file_that_has_not_been_written_yet(): void
    {
        $this->assertFileDoesNotExist($this->tempPath.'/app/Actions/Unwritten.php');

        $payload = $this->agentPayload('app/Actions/Unwritten.php');

        $this->assertTrue($payload['ok']);
        $this->assertContains('actions', array_column($payload['arch'], 'slug'));
    }

    public function test_it_reports_a_path_outside_the_application_as_unenforced(): void
    {
        $payload = $this->agentPayload('routes/api.php');

        $this->assertTrue($payload['ok']);
        $this->assertSame('outside_application', $payload['scope']);
        $this->assertSame([], $payload['arch']);
        $this->assertSame(['no_rules_enforced_here'], $payload['next']);
    }

    public function test_it_requires_a_path(): void
    {
        $exitCode = Artisan::call('architecture-kit:file-rules', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('file-rules', $payload['cmd']);
    }

    public function test_human_output_separates_enforced_from_advisory(): void
    {
        Artisan::call('architecture-kit:file-rules', ['path' => 'app/Actions/SendInvoice.php']);
        $output = Artisan::output();

        $this->assertStringContainsString('enforced', $output);
        $this->assertStringContainsString('advisory', $output);
        $this->assertStringContainsString('guidance only', $output);
    }

    public function test_it_outputs_agent_schema(): void
    {
        Artisan::call('architecture-kit:file-rules', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true);

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertArrayHasKey('oneOf', $schema);
    }

    public function test_a_rule_registered_by_the_project_is_reported_separately(): void
    {
        $this->writeRawConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Tests\Fixtures\ForbiddenWorkflowAuditRule;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        ForbiddenWorkflowAuditRule::class,
    ],
];
PHP);

        $payload = $this->agentPayload('app/Actions/ForbiddenWorkflow.php');

        $this->assertSame(['forbidden-workflow-audit-rule'], $payload['project']);
        $this->assertContains('forbidden-workflow-audit-rule', $payload['rules']);
    }

    public function test_a_traversing_path_is_resolved_before_the_scope_is_decided(): void
    {
        $payload = $this->agentPayload('app/../routes/api.php');

        $this->assertSame('routes/api.php', $payload['path']);
        $this->assertSame('outside_application', $payload['scope']);
        $this->assertSame([], $payload['rules']);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentPayload(string $path): array
    {
        Artisan::call('architecture-kit:file-rules', ['path' => $path, '--agent' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(trim(Artisan::output()), true);

        return $payload;
    }

    private function writeRawConfig(string $contents): void
    {
        $files = new Filesystem;
        $absolute = $this->tempPath.'/config/architectures.php';
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
