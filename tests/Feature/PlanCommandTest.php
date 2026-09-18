<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class PlanCommandTest extends TestCase
{
    public function test_it_recommends_an_architecture_from_project_evidence_without_writing(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $files->ensureDirectoryExists($this->tempPath.'/app/Actions');
        $files->put($this->tempPath.'/app/Actions/CreateInvoice.php', '<?php final class CreateInvoice {}');

        $before = $this->snapshot($files);
        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame('plan', $payload['cmd']);
        $this->assertFalse($payload['configured']);
        $this->assertSame('actions', $payload['recommendations'][0]['slug']);
        $this->assertSame('high', $payload['recommendations'][0]['confidence']);
        $this->assertSame(['app/Actions/CreateInvoice.php'], $payload['recommendations'][0]['evidence']);
        $this->assertContains('config/architectures.php', $payload['changes']['create']);
        $this->assertContains('.ai/guidelines/architecture-kit.md', $payload['changes']['create']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_enum_recommendation_requires_a_real_parseable_enum_declaration(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $files->ensureDirectoryExists($this->tempPath.'/app/Support');
        $files->put($this->tempPath.'/app/Support/EnumNotes.php', <<<'PHP'
<?php

// enum CommentStatus is not a declaration.
/** Future example: enum DocblockStatus */
$example = 'enum StringStatus';
PHP);
        $files->put($this->tempPath.'/app/Support/BrokenEnum.php', '<?php enum BrokenStatus {');

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $withoutEnum = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNotContains('enums', array_column($withoutEnum['recommendations'], 'slug'));

        $files->put($this->tempPath.'/app/Support/DocumentStatus.php', <<<'PHP'
<?php

namespace App\Support;

enum DocumentStatus
{
    case Draft;
}
PHP);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $withEnum = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $recommendations = array_column($withEnum['recommendations'], null, 'slug');
        $this->assertArrayHasKey('enums', $recommendations);
        $this->assertSame(['app/Support/DocumentStatus.php'], $recommendations['enums']['evidence']);
    }

    public function test_it_plans_from_existing_configuration_and_reports_runtime_requirement_without_writing(): void
    {
        $files = new Filesystem;
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files))->write([Architecture::Actions]);
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['configured']);
        $this->assertTrue($payload['recommendations'][0]['configured']);
        $this->assertSame('actions', $payload['recommendations'][0]['slug']);
        $this->assertSame(['config/architectures.php'], $payload['recommendations'][0]['evidence']);
        $this->assertSame('architecture-kit-runtime', $payload['requirements'][0]['name']);
        $this->assertTrue($payload['requirements'][0]['satisfied']);
        $this->assertContains('.ai/skills/architecture-kit-actions/SKILL.md', $payload['changes']['create']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_recommends_a_repo_local_custom_architecture_from_its_guideline(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/billing-workflows');
        $files->put(
            $this->tempPath.'/.architecture-kit/architectures/billing-workflows/guideline.md',
            '# Billing workflows',
        );
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('billing-workflows', $payload['recommendations'][0]['slug']);
        $this->assertSame(
            ['.architecture-kit/architectures/billing-workflows/guideline.md'],
            $payload['recommendations'][0]['evidence'],
        );
        $this->assertContains(
            '.ai/skills/architecture-kit-billing-workflows/SKILL.md',
            $payload['changes']['create'],
        );
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_does_not_guess_an_architecture_without_project_evidence(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame([], $payload['recommendations']);
        $this->assertSame([
            'create' => [],
            'update' => [],
            'remove' => [],
            'blocked' => [],
        ], $payload['changes']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_recommends_saloon_from_composer_and_reports_missing_requirements(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $files->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'gracjankubicki/laravel-architecture-kit' => '^0.2',
                'saloonphp/saloon' => '^4.0',
            ],
        ], JSON_THROW_ON_ERROR));
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('saloon', $payload['recommendations'][0]['slug']);
        $this->assertSame(['composer.json:require.saloonphp/saloon'], $payload['recommendations'][0]['evidence']);
        $this->assertSame('saloon', $payload['requirements'][1]['name']);
        $this->assertFalse($payload['requirements'][1]['satisfied']);
        $this->assertStringContainsString('laravel-plugin', $payload['requirements'][1]['message']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_exposes_a_versioned_plan_agent_schema(): void
    {
        $exit = Artisan::call('architecture-kit:plan', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('Architecture Kit plan agent output', $schema['title']);
        $this->assertCount(2, $schema['oneOf']);
        $this->assertSame(1, $schema['oneOf'][0]['properties']['v']['const']);
        $this->assertSame('plan', $schema['oneOf'][0]['properties']['cmd']['const']);
        $this->assertSame('plan', $schema['oneOf'][1]['properties']['cmd']['const']);
    }

    public function test_human_output_uses_the_same_plan_and_reports_requirements(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $files->ensureDirectoryExists($this->tempPath.'/app/Actions');
        $files->put($this->tempPath.'/app/Actions/CreateInvoice.php', '<?php final class CreateInvoice {}');

        $this->artisan('architecture-kit:plan')
            ->expectsOutputToContain('Architecture Kit Plan')
            ->expectsOutputToContain('recommend  actions')
            ->expectsOutputToContain('requirement architecture-kit-runtime')
            ->expectsOutputToContain('create     config/architectures.php')
            ->expectsOutputToContain('No files were changed.')
            ->assertExitCode(0);
    }

    public function test_agent_reports_invalid_project_state_with_schema_compatible_error_without_writing(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/config/architectures.php', '<?php return "invalid";');
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('plan', $payload['cmd']);
        $this->assertSame('E_COMMAND_FAILED', $payload['m']);
        $this->assertStringContainsString('must return an array', $payload['msg']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_reports_unsupported_laravel_ai_as_a_blocked_read_only_plan(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'gracjankubicki/laravel-architecture-kit' => '^0.2',
                'laravel/ai' => '^0.12',
            ],
        ], JSON_THROW_ON_ERROR));
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files))->write([Architecture::LaravelAi]);
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('laravel-ai', $payload['recommendations'][0]['slug']);
        $this->assertSame('laravel-ai', $payload['requirements'][1]['name']);
        $this->assertFalse($payload['requirements'][1]['satisfied']);
        $this->assertSame(['requirements:laravel-ai'], $payload['changes']['blocked']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_reports_supported_laravel_ai_011_without_writing(): void
    {
        $files = new Filesystem;
        $this->writeLaravelAiFixture('^0.11', '0.11.2');
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files))->write([Architecture::LaravelAi]);
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['requirements'][1]['satisfied']);
        $this->assertStringContainsString('laravel-ai@0.11', $payload['requirements'][1]['message']);
        $this->assertNotContains('requirements:laravel-ai', $payload['changes']['blocked']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_recommends_supported_inertia_3_without_writing(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $this->writeInertiaFixture('^3.0', '3.1.0');
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $recommendations = array_column($payload['recommendations'], null, 'slug');

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(
            ['composer.json:require.inertiajs/inertia-laravel'],
            $recommendations['inertia']['evidence'],
        );
        $this->assertTrue($payload['requirements'][1]['satisfied']);
        $this->assertSame('inertia', $payload['requirements'][1]['name']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_it_does_not_recommend_unsupported_inertia_but_blocks_an_explicit_selection(): void
    {
        $files = new Filesystem;
        $files->delete($this->tempPath.'/config/architectures.php');
        $this->writeInertiaFixture('^2.0', '2.0.0');

        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $detected = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNotContains('inertia', array_column($detected['recommendations'], 'slug'));

        (new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files))->write([Architecture::Inertia]);
        $exit = Artisan::call('architecture-kit:plan', ['--agent' => true]);
        $configured = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFalse($configured['requirements'][1]['satisfied']);
        $this->assertSame(['requirements:inertia'], $configured['changes']['blocked']);
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
    private function snapshot(Filesystem $files): array
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
