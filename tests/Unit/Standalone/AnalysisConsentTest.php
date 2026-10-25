<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnalysisConsentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        mkdir($this->directory = sys_get_temp_dir().'/kit-consent-'.bin2hex(random_bytes(8)), 0700);
    }

    protected function tearDown(): void
    {
        RepositoryContext::activate(null);
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_grant_is_bound_to_repository_and_task_and_survives_new_process_context(): void
    {
        $one = $this->context('one');
        $two = $this->context('two');
        $consent = new AnalysisConsent($one);
        self::assertFalse($consent->hasDeep('A'));
        $record = $consent->grantDeep('A');
        self::assertSame($record, $consent->grantDeep('A'));
        self::assertTrue((new AnalysisConsent(new RepositoryContext($one->rootPath, $one->home)))->hasDeep('A'));
        self::assertFalse($consent->hasDeep('B'));
        self::assertFalse((new AnalysisConsent($two))->hasDeep('A'));
        self::assertFalse($one->execute);
        self::assertFalse($one->runtimeRoutes);
    }

    public function test_without_grant_deep_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires prior human consent');
        (new AnalysisConsent($this->context('one')))->requireDeep('A');
    }

    public function test_copied_or_corrupt_consent_is_not_accepted(): void
    {
        $context = $this->context('one');
        $consent = new AnalysisConsent($context);
        $record = $consent->grantDeep('A');
        $key = hash('sha256', $context->id.':A');
        (new StateStore($context->storage.'/analysis-consent', $context->home))->update($key, fn () => [...$record, 'task' => 'B']);
        $this->expectException(RuntimeException::class);
        $consent->hasDeep('A');
    }

    public function test_invalid_task_does_not_grant_anything(): void
    {
        $this->expectException(RuntimeException::class);
        (new AnalysisConsent($this->context('one')))->grantDeep('');
    }

    private function context(string $name): RepositoryContext
    {
        $root = $this->directory.'/'.$name;
        mkdir($root);
        file_put_contents($root.'/composer.json', '{"type":"project","require":{"php":"^8.3"}}');
        $home = $this->directory.'/home';
        $registration = (new Projects($home))->register($root, ['actions']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn ($old) => [...$old, 'analysis' => ['engine' => 'php']]);

        return new RepositoryContext(realpath($root), $home);
    }
}
