<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\BuiltInRules;
use GracjanKubicki\ArchitectureKit\Audit\FileAuditPool;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class FileAuditPoolTest extends TestCase
{
    public function test_workers_keep_complete_file_contributions_findings_and_suppression_sources(): void
    {
        $files = new Filesystem;
        $home = $this->tempPath.'-tool-home';
        $files->put($this->tempPath.'/config/architectures.php', '<?php return '.var_export(['analysis' => ['engine' => EngineFixture::selected()], 'enabled' => ['thin-controllers'], 'audit' => ['cache' => false]], true).';');
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($home);
        }
        $files->ensureDirectoryExists($this->tempPath.'/app/Http/Controllers');
        $files->put($this->tempPath.'/app/Http/Controllers/Example.php', '<?php namespace App\\Http\\Controllers; // @architecture-kit-ignore thin-controllers'."\n".'class Example { public function index() { return \\App\\User::query()->delete(); } }');
        $files->put($this->tempPath.'/app/User.php', '<?php namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $files->put($this->tempPath.'/app/Invalid.php', '<?php class {');
        $files->put($this->tempPath.'/app/helper.php', '<?php namespace App; class Functions { public function declare() { function count($items) { return 1; } } }');
        $paths = [];
        foreach ($files->allFiles($this->tempPath.'/app') as $file) {
            $paths[substr($file->getPathname(), strlen($this->tempPath) + 1)] = $file->getPathname();
        }
        $context = new RepositoryContext(realpath($this->tempPath), $home);
        RepositoryContext::activate($context);
        try {
            $enabled = [Architecture::ThinControllers];
            $scope = new AuditScope;
            $facts = new AnalysisFactsCache('', '', null);
            $batch = FileAuditPool::collect($context, $paths, $enabled, $scope, $facts);
            self::assertNotNull($batch);
            self::assertCount(count($paths), $batch);
            $classification = new ProjectClassification($files, $this->tempPath);
            $rules = BuiltInRules::all($files, $this->tempPath, $enabled, $classification);
            foreach ($paths as $path => $absolute) {
                $serial = (new ApplicationAudit($files, $this->tempPath))->collectFile(new FileContext($path, $files->get($absolute)), $enabled, $rules, $classification, $scope);
                self::assertEquals($serial, $batch[$path]);
                $restored = new ProjectClassification($files, $this->tempPath);
                $restored->restore($path, $batch[$path]->classification);
                $files->delete($absolute);
                self::assertSame($classification->file($path), $restored->file($path));
            }
            self::assertNotEmpty($batch['app/Invalid.php']->findings);
            self::assertTrue($facts->hasNoFunctions($context->rootPath.'/app/User.php'));
            self::assertFalse($facts->hasNoFunctions($context->rootPath.'/app/Invalid.php'));
            self::assertFalse($facts->hasNoFunctions($context->rootPath.'/app/helper.php'));
        } finally {
            RepositoryContext::activate(null);
            $files->deleteDirectory($home);
        }
    }

    public function test_an_incomplete_or_unsafe_batch_requires_serial_fallback(): void
    {
        $files = new Filesystem;
        $home = $this->tempPath.'-tool-home';
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["analysis"=>["engine"=>"php"],"enabled"=>[]];');
        $context = new RepositoryContext(realpath($this->tempPath), $home);
        try {
            self::assertNull(FileAuditPool::collect($context, ['../foreign.php' => '/foreign.php'], [], new AuditScope, null));
        } finally {
            $files->deleteDirectory($home);
        }
    }
}
