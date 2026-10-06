<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\PublicApi;
use GracjanKubicki\ArchitectureKit\PublicApi\ArchitecturePublicApi;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

final class PublicApiReportTest extends TestCase
{
    public function test_dirty_working_contracts_cli_and_mcp_agree_without_execution_or_git_mutations(): void
    {
        $this->fixture('<?php namespace Demo; class Api { public function run(int $id = 1): void {} private function secret(): int { return 1; } }');
        $this->writeSource('<?php namespace Demo; file_put_contents(__DIR__."/executed", "bad"); class Api { public function run(int $id): void {} private function secret(): int { return 2; } }');
        $status = $this->git('status', '--porcelain');
        $report = (new ArchitecturePublicApi($this->tempPath))->compare('HEAD', version: '1.2.3');
        $this->assertTrue($report['ok']);
        $this->assertSame('complete', $report['analysis']['status']);
        $this->assertSame(1, $report['totals']['breaking']);
        $this->assertSame(1, $report['totals']['internal']);
        $this->assertSame('major', $report['semver']['recommendation']);
        $this->assertSame(0, Artisan::call('architecture-kit:public-api', ['from' => 'HEAD', '--agent' => true, '--current-version' => '1.2.3']));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($report, $cli);
        ArchitectureKitServer::tool(PublicApi::class, ['from' => 'HEAD', 'current_version' => '1.2.3'])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('totals.breaking', 1)->where('totals.internal', 1)->where('semver.recommendation', 'major')->etc());
        $this->assertSame($status, $this->git('status', '--porcelain'));
        $this->assertFileDoesNotExist($this->tempPath.'/src/executed');
    }

    public function test_two_git_states_ignore_dirty_working_and_limits_only_size_display(): void
    {
        $this->fixture('<?php namespace Demo; class Api { public function run(): void {} }');
        $before = $this->git('rev-parse', 'HEAD');
        $this->writeSource('<?php namespace Demo; class Api { public function run(): void {} public function added(): void {} }');
        $this->commit();
        $this->writeSource('<?php broken');
        $report = (new ArchitecturePublicApi($this->tempPath))->compare($before, 'HEAD', version: '1.2.3', limit: 0);
        $this->assertTrue($report['ok']);
        $this->assertSame('git', $report['sources']['after']['state']);
        $this->assertSame(1, $report['totals']['compatible']);
        $this->assertSame([], $report['changes']);
        $this->assertTrue($report['analysis']['truncated']);
        $this->assertSame('minor', $report['semver']['recommendation']);
    }

    public function test_zero_policy_and_unchanged_contracts_never_guess_patch(): void
    {
        $this->fixture('<?php namespace Demo; class Api { public function run(): void {} }');
        $service = new ArchitecturePublicApi($this->tempPath);
        $unchanged = $service->compare('HEAD', version: '1.2.3');
        $this->assertSame([], $unchanged['changes']);
        $this->assertNull($unchanged['semver']['recommendation']);
        $this->writeSource('<?php namespace Demo; class Api {}');
        $without = $service->compare('HEAD', version: '0.6.2');
        $this->assertNull($without['semver']['recommendation']);
        $this->assertSame('minor', $service->compare('HEAD', version: '0.6.2', zeroPolicy: 'breaking-minor')['semver']['recommendation']);
        $this->assertSame('major', $service->compare('HEAD', version: '0.6.2', zeroPolicy: 'breaking-major')['semver']['recommendation']);
    }

    public function test_partial_sources_keep_known_changes_and_errors_are_structured(): void
    {
        $this->fixture('<?php namespace Demo; class Api { public function run(): void {} }');
        $this->writeSource('<?php namespace Demo; class Api { public function run(int $id): void {} }');
        (new Filesystem)->put($this->tempPath.'/src/Broken.php', '<?php class Broken {');
        $service = new ArchitecturePublicApi($this->tempPath);
        $report = $service->compare('HEAD', version: '1.2.3');
        $this->assertTrue($report['ok']);
        $this->assertSame('incomplete', $report['analysis']['status']);
        $this->assertTrue($report['analysis']['total_is_lower_bound']);
        $this->assertSame(1, $report['totals']['breaking']);
        $this->assertNotEmpty($report['notices']);
        $this->assertFalse($service->compare('missing-revision')['ok']);
        $this->assertSame('E_PUBLIC_API_INPUT', $service->compare('working')['m']);
        $this->assertSame('E_PUBLIC_API_INPUT', $service->compare('HEAD', publicPaths: ['../outside'])['m']);
        $this->assertSame('E_PUBLIC_API_INPUT', $service->compare('HEAD', limit: 501)['m']);
    }

    public function test_internal_type_identity_is_checked_without_publicizing_all_members(): void
    {
        $this->fixture('<?php namespace Demo; /** @internal */ class Input {} class Api { public function run(Input $input): void {} }');
        $this->writeSource('<?php namespace Demo; /** @internal */ interface Input {} class Api { public function run(Input $input): void {} }');
        $report = (new ArchitecturePublicApi($this->tempPath))->compare('HEAD');
        $this->assertSame(1, $report['totals']['check']);
        $this->assertSame(1, $report['totals']['internal']);
        $this->assertSame(0, $report['totals']['breaking']);
    }

    private function fixture(string $source): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', '{"autoload":{"classmap":["src"]}}');
        $this->writeSource($source);
        $this->git('init', '-q');
        $this->commit();
    }

    private function writeSource(string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/src');
        $files->put($this->tempPath.'/src/Api.php', $source);
    }

    private function commit(): void
    {
        $this->git('add', '.');
        $this->git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture');
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-C', $this->tempPath, ...$args]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());

        return trim($process->getOutput());
    }
}
