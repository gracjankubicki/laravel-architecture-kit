<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\Baseline;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class AnalysisEngineAuditTest extends TestCase
{
    public static function engines(): iterable
    {
        yield 'php' => ['php'];
        yield 'mago' => ['mago'];
    }

    #[DataProvider('engines')]
    public function test_invalid_syntax_never_passes_suppression_and_other_files_are_analyzed(string $engine): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if ($engine === 'mago' && (! is_string($binary) || $binary === '')) {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE.');
        }
        AnalysisRuntime::activate(new AnalysisSession($engine === 'mago' ? new MagoAnalysisEngine($binary) : new PhpAnalysisEngine, '8.3'));
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Actions');
        $files->put($this->tempPath.'/app/Actions/Broken.php', "<?php\n// @architecture-kit-ignore-file unparseable-file\nclass Broken {\n public function (\n");
        $files->put($this->tempPath.'/app/Actions/Other.php', '<?php namespace App\Actions; use Illuminate\Http\Request; class Other { public function handle(Request $request) {} }');
        $audit = new ApplicationAudit($files, $this->tempPath);
        $first = $audit->run([Architecture::Actions], false, useBaseline: false);
        self::assertTrue(StandaloneAudit::incomplete($first));
        $broken = array_values(array_filter($first->findings, static fn ($finding) => $finding->rule === 'unparseable-file'));
        self::assertCount(1, $broken);
        self::assertSame('error', $broken[0]->severity);
        self::assertGreaterThanOrEqual(3, $broken[0]->line);
        self::assertContains('app/Actions/Other.php', array_column(array_map(get_object_vars(...), $first->findings), 'path'));
        (new Baseline($files, $this->tempPath))->write($first->findings);
        $again = $audit->run([Architecture::Actions], false);
        self::assertSame(1, $again->errors());
        self::assertTrue(StandaloneAudit::incomplete($again));
    }

    #[DataProvider('engines')]
    public function test_unchanged_syntax_failure_survives_changed_audit_graph_cache(string $engine): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if ($engine === 'mago' && (! is_string($binary) || $binary === '')) {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE.');
        }
        AnalysisRuntime::activate(new AnalysisSession($engine === 'mago' ? new MagoAnalysisEngine($binary) : new PhpAnalysisEngine, '8.3'));
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Broken.php', "<?php\nclass Broken {\npublic function (\n");
        $files->put($this->tempPath.'/app/Changed.php', '<?php class Changed {}');
        foreach ([['git', 'init', '--quiet'], ['git', 'add', '.'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'fixture']] as $command) {
            $process = new Process($command, $this->tempPath);
            self::assertSame(0, $process->run(), $process->getErrorOutput());
        }
        $parsed = [];
        $audit = new ApplicationAudit($files, $this->tempPath, onParse: static function (string $path) use (&$parsed): void {
            $parsed[] = $path;
        });
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $audit->run([], false, cache: $cache);
        $files->put($this->tempPath.'/app/Changed.php', '<?php class Changed { public function run() {} }');
        $parsed = [];
        $changed = $audit->run([], true, baseRef: 'HEAD', cache: $cache);
        self::assertNotContains('app/Broken.php', $parsed, 'The unchanged invalid file must be restored from cache.');
        self::assertTrue(StandaloneAudit::incomplete($changed));
        self::assertSame(1, $changed->errors());
        self::assertSame('app/Broken.php', $changed->findings[0]->path);
        self::assertGreaterThanOrEqual(3, $changed->findings[0]->line);
    }
}
