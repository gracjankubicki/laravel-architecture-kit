<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\LaravelAi;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibility;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityStatus;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaravelAiCompatibilityTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-ai-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    /** @return array<string, array{string, string, string}> */
    public static function supportedConstraints(): array
    {
        return [
            '0.8 caret' => ['^0.8', '0.8.1', 'laravel-ai@0.8'],
            '0.9 caret' => ['^0.9', '0.9.0', 'laravel-ai@0.9'],
            '0.10 caret' => ['^0.10', '0.10.1', 'laravel-ai@0.10'],
            '0.11 minimum' => ['^0.11', '0.11.0', 'laravel-ai@0.11'],
            '0.11 latest verified' => ['^0.11', '0.11.2', 'laravel-ai@0.11'],
            '0.11 exact' => ['0.11.2', '0.11.2', 'laravel-ai@0.11'],
            'supported union' => ['^0.8 || ^0.9 || ^0.10 || ^0.11', '0.11.2', 'laravel-ai@0.11'],
            'supported interval' => ['>=0.8 <0.12', '0.8.0', 'laravel-ai@0.8'],
        ];
    }

    #[DataProvider('supportedConstraints')]
    public function test_it_selects_one_profile_for_fully_supported_constraints(string $constraint, string $version, string $profile): void
    {
        $this->writeProject($constraint, $version, $version);

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertSame($profile, $result->profile?->key());
        $this->assertSame($version, $result->installedVersion);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedConstraints(): array
    {
        return [
            'future minor' => ['^0.12'],
            'future major' => ['>=0.8 <2.0'],
            'next major' => ['^1.0'],
            'wildcard' => ['*'],
            'development branch' => ['dev-main'],
        ];
    }

    #[DataProvider('unsupportedConstraints')]
    public function test_it_rejects_constraints_that_allow_unsupported_versions(string $constraint): void
    {
        $this->writeProject($constraint, '0.9.0', '0.9.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::UnsupportedConstraint, $result->status);
        $this->assertFalse($result->supported());
    }

    public function test_it_rejects_dev_only_dependency_placement(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0', 'require-dev');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::RuntimeDependencyInRequireDev, $result->status);
        $this->assertStringContainsString('composer require laravel/ai', $result->remediation);
    }

    public function test_it_reports_a_missing_root_dependency(): void
    {
        (new Filesystem)->put($this->path.'/composer.json', '{}');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::Missing, $result->status);
        $this->assertStringContainsString('^0.11', $result->remediation);
    }

    public function test_it_rejects_an_invalid_constraint_without_modifying_the_project(): void
    {
        $this->writeProject('not a constraint', '0.11.2', '0.11.2');
        $composerJson = (new Filesystem)->get($this->path.'/composer.json');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::InvalidConstraint, $result->status);
        $this->assertSame($composerJson, (new Filesystem)->get($this->path.'/composer.json'));
    }

    public function test_it_reports_declared_but_not_installed_sdk(): void
    {
        $this->writeProject('^0.11', '0.11.2', '0.11.2');
        (new Filesystem)->delete($this->path.'/vendor/composer/installed.php');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::NotInstalled, $result->status);
    }

    public function test_it_rejects_stale_lock_state(): void
    {
        $this->writeProject('>=0.8 <0.10', '0.9.0', '0.8.1');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::StaleLock, $result->status);
    }

    public function test_it_rejects_a_runtime_dependency_locked_only_for_development(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0');
        (new Filesystem)->put($this->path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [['name' => 'laravel/ai', 'version' => '0.9.0']],
        ], JSON_THROW_ON_ERROR));

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::StaleLock, $result->status);
        $this->assertStringContainsString('packages-dev', $result->message);
        $this->assertStringContainsString('composer update laravel/ai', $result->remediation);
    }

    public function test_it_rejects_a_runtime_dependency_missing_from_an_existing_lockfile(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0');
        (new Filesystem)->put($this->path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::StaleLock, $result->status);
        $this->assertStringContainsString('missing from composer.lock', $result->message);
    }

    public function test_it_uses_installed_metadata_when_no_lockfile_exists(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0');
        (new Filesystem)->delete($this->path.'/composer.lock');

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertNull($result->lockedVersion);
    }

    public function test_it_rejects_missing_referenced_capability(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0', withCapabilities: false);

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::MissingCapability, $result->status);
        $this->assertNotEmpty($result->missingCapabilities);
    }

    public function test_011_requires_the_verified_streaming_failover_and_queue_contracts(): void
    {
        $this->writeProject('^0.11', '0.11.2', '0.11.2');
        (new Filesystem)->delete($this->path.'/vendor/laravel/ai/src/Exceptions/ProviderConnectionException.php');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::MissingCapability, $result->status);
        $this->assertSame(['provider-connection-failover'], $result->missingCapabilities);
    }

    public function test_010_requires_the_approval_resumption_contract(): void
    {
        $this->writeProject('^0.10', '0.10.1', '0.10.1');
        (new Filesystem)->delete($this->path.'/vendor/laravel/ai/src/Contracts/ConversationStore.php');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::MissingCapability, $result->status);
        $this->assertSame(['approval-resumption-contract'], $result->missingCapabilities);
    }

    private function resolver(): LaravelAiCompatibility
    {
        $files = new Filesystem;

        return new LaravelAiCompatibility(
            new ProjectPackageInventory($files, $this->path),
            $files,
            $this->path,
        );
    }

    private function writeProject(
        string $constraint,
        string $installed,
        string $locked,
        string $section = 'require',
        bool $withCapabilities = true,
    ): void {
        $files = new Filesystem;
        $files->put($this->path.'/composer.json', json_encode([
            $section => ['laravel/ai' => $constraint],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->path.'/vendor/composer');
        $files->put($this->path.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$installed}']]];\n");
        $files->put($this->path.'/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/ai', 'version' => $locked]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        if (! $withCapabilities) {
            return;
        }

        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Responses');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Responses/StructuredAgentResponse.php',
            '<?php class StructuredAgentResponse implements ArrayAccess { public function toArray(): array {} }',
        );
        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Concerns');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Concerns/Promptable.php',
            '<?php trait Promptable { public function withProviderOptions(array $options): static {} }',
        );
        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Approvals');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Approvals/Decisions.php',
            '<?php class Decisions {}',
        );
        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Contracts');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Contracts/ConversationStore.php',
            '<?php interface ConversationStore { public function storeApprovalResults(): void; }',
        );
        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Exceptions');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Exceptions/ProviderConnectionException.php',
            '<?php class ProviderConnectionException implements FailoverableException {}',
        );
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Exceptions/StreamErrorException.php',
            '<?php class StreamErrorException {}',
        );
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Promptable.php',
            '<?php trait Promptable { public function queue() { return InvokeAgent::dispatch(); } public function broadcastOnQueue() { return BroadcastAgent::dispatch(); } }',
        );
    }
}
