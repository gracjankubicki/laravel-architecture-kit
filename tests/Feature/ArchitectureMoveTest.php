<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Impact\MoveAutoload;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureMoveTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$source);
        clearstatcache();
    }

    private function composer(array $config = ['autoload' => ['psr-4' => ['App\\' => 'app/']]]): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function query(string $subject = 'Target', ?string $class = null, ?string $path = null, int $limit = 100, bool $cache = false, ?Filesystem $files = null): array
    {
        $files ??= new Filesystem;

        return (new ArchitectureImpact($files, $this->tempPath, new AuditScope(['app', 'tests']), $cache ? new ProjectGraphCache($files, $this->tempPath) : null))->inspect($subject, limit: $limit, change: 'move', targetClass: $class, targetPath: $path);
    }

    private function fixture(): void
    {
        $this->composer();
        $this->write('app/Target.php', 'namespace App; class Target { public static function run() {} public function selfUse() { new Target; new Helper; } } class Helper {}');
        $this->write('app/Caller.php', 'namespace App; class Caller { public function make() { new Target; Target::run(); } public function typed(Target $value): Target { return $value; } public function reference() { return Target::class; } } class Child extends Target {}');
    }

    private function kinds(array $result, string $group): array
    {
        return array_column($result['move'][$group], 'kind');
    }

    public function test_inspect_and_compare_never_edit_or_execute_sources(): void
    {
        $this->fixture();
        $marker = $this->tempPath.'/executed';
        $this->write('app/Poison.php', 'namespace App; file_put_contents('.var_export($marker, true).', "bad"); class Poison {}');
        $hash = hash_file('sha256', $this->tempPath.'/app/Target.php');
        $inspect = $this->query();
        $this->assertTrue($inspect['ok']);
        $this->assertSame('inspect', $inspect['move']['mode']);
        $this->assertSame([], $inspect['move']['breaking']);
        $this->assertFalse($inspect['move']['safe_to_change']);
        $this->assertTrue($this->query('Poison', 'App\\Renamed', 'app/Renamed.php')['ok']);
        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist($this->tempPath.'/app/Renamed.php');
        $this->assertSame($hash, hash_file('sha256', $this->tempPath.'/app/Target.php'));
    }

    public function test_rename_breaks_old_strong_uses_but_checks_types_references_and_self(): void
    {
        $this->fixture();
        $result = $this->query(class: 'App\\Billing\\Renamed', path: 'app/Billing/Renamed.php');
        $this->assertTrue($result['ok']);
        foreach (['new', 'static', 'extends'] as $kind) {
            $this->assertContains($kind, $this->kinds($result, 'breaking'));
        }
        $this->assertContains('App\\Caller', array_column($result['move']['check'], 'symbol'));
        $this->assertContains('namespace_dependency', $this->kinds($result, 'check'));
        $this->assertContains('multiple_declarations', $this->kinds($result, 'check'));
        $this->assertNotContains('App\\Target', array_column($result['move']['breaking'], 'symbol'));
        $this->assertContains('autoload', $this->kinds($result, 'compatible'));
        $this->assertStringContainsString('Unused imports', json_encode($result['move']));
    }

    public function test_path_only_keeps_all_names_and_separates_autoload_mismatch(): void
    {
        $this->fixture();
        $result = $this->query('app/Target.php', path: 'app/Elsewhere.php');
        $this->assertEqualsCanonicalizing(['App\\Target', 'App\\Helper'], $result['move']['source']['classes']);
        $this->assertNull($result['move']['target']['class']);
        $this->assertContains('new', $this->kinds($result, 'compatible'));
        $this->assertSame(['autoload'], array_values(array_unique($this->kinds($result, 'breaking'))));
        $this->composer(['autoload' => ['psr-4' => ['App\\' => ['app/', 'relocated/']]]]);
        $this->write('app/Target.php', 'namespace App; class Target {}');
        $matched = $this->query('app/Target.php', path: 'relocated/Target.php');
        $this->assertSame([], $matched['move']['breaking']);
        $this->assertContains('scope', $this->kinds($matched, 'check'));
    }

    public function test_class_only_rename_uses_current_path_and_multi_class_path_is_ambiguous(): void
    {
        $this->fixture();
        $this->assertContains('autoload', $this->kinds($this->query(class: 'App\\Renamed'), 'breaking'));
        $this->assertSame('E_IMPACT_SUBJECT_AMBIGUOUS', $this->query('app/Target.php', class: 'App\\Renamed')['m']);
        $result = $this->query('Target', class: 'App\\Renamed', path: 'app/Renamed.php');
        $this->assertSame('App\\Target', $result['move']['source']['class']);
        $this->assertCount(2, $result['move']['source']['classes']);
        $this->assertStringContainsString('App\\\\Helper', json_encode($result['move']['breaking']));
    }

    public function test_class_and_path_collisions_and_case_only_changes_are_explicit(): void
    {
        $this->fixture();
        $this->assertContains('collision', $this->kinds($this->query(class: 'App\\Caller', path: 'app/Caller.php'), 'breaking'));
        $case = $this->query(class: 'App\\target', path: 'app/target.php');
        $this->assertNotContains('collision', $this->kinds($case, 'breaking'));
        $this->assertContains('casing', $this->kinds($case, 'check'));
    }

    public function test_interfaces_traits_and_file_scope_have_evidence(): void
    {
        $this->composer();
        $this->write('app/Contract.php', 'namespace App; interface Contract {} trait Runs {} class User implements Contract { use Runs; }');
        $this->assertContains('implements', $this->kinds($this->query('Contract', 'App\\Renamed'), 'breaking'));
        $this->assertContains('trait', $this->kinds($this->query('Runs', 'App\\Renamed'), 'breaking'));
        $this->write('app/Target.php', 'namespace App; class Target { public static function run() {} }');
        $this->write('app/script.php', 'namespace App; new Target; Target::run(); $ref = [Target::class, "run"];');
        $report = $this->query(class: 'App\\Renamed', path: 'app/Renamed.php');
        $this->assertContains('(file) app/script.php', array_column($report['move']['breaking'], 'symbol'));
        $this->assertContains('(file) app/script.php', array_column($report['move']['check'], 'symbol'));
        $keys = array_map(fn ($r) => $r['path'].'|'.$r['line'].'|'.$r['kind'].'|'.($r['target'] ?? $r['symbol']), $report['move']['breaking']);
        $this->assertSame(array_values(array_unique($keys)), $keys);
    }

    public function test_classless_path_can_move_but_cannot_be_renamed(): void
    {
        $this->composer();
        $this->write('app/script.php', 'function work() {} const VALUE = 1; require "else.php";');
        $result = $this->query('app/script.php', path: 'app/other.php');
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['move']['source']['classes']);
        $this->assertContains('file', $this->kinds($result, 'check'));
        $this->assertSame('E_IMPACT_MOVE_TARGET_INVALID', $this->query('app/script.php', class: 'App\\Work')['m']);
    }

    public function test_invalid_targets_and_mode_combinations_are_errors(): void
    {
        $this->fixture();
        foreach (['', 'App\\class', 'App\\Bad-name', str_repeat('a', 513)] as $class) {
            $this->assertSame('E_IMPACT_MOVE_TARGET_INVALID', $this->query(class: $class)['m'], $class);
        }
        foreach (['', '/tmp/A.php', '../A.php', 'app/../A.php', 'C:\\A.php', 'app/A.txt', "app/\0A.php"] as $path) {
            $this->assertSame('E_IMPACT_MOVE_TARGET_INVALID', $this->query(path: $path)['m'], $path);
        }
        symlink(sys_get_temp_dir(), $this->tempPath.'/outside');
        $this->assertSame('E_IMPACT_MOVE_TARGET_INVALID', $this->query(path: 'outside/A.php')['m']);
        unlink($this->tempPath.'/outside');
        $this->assertSame('E_IMPACT_CHANGE_INVALID', $this->query('Target::run')['m']);
        $impact = new ArchitectureImpact(new Filesystem, $this->tempPath);
        $this->assertSame('E_IMPACT_CHANGE_INVALID', $impact->inspect('Target', targetClass: 'App\\NewName')['m']);
        $this->assertSame('E_IMPACT_CHANGE_INVALID', $impact->inspect('Target', change: 'move', signature: 'run()')['m']);
        $this->assertSame('E_IMPACT_SUBJECT_NOT_FOUND', $this->query('Absent')['m']);
    }

    public function test_psr4_lists_prefix_fallback_dev_and_case_rules(): void
    {
        $reader = new MoveAutoload(new Filesystem, $this->tempPath);
        $this->composer(['autoload' => ['psr-4' => ['App\\Billing\\' => 'special/', 'App\\' => ['app/', 'second/'], '' => 'fallback/']], 'autoload-dev' => ['psr-4' => ['App\\' => 'app/']]]);
        $config = $reader->read();
        foreach ([['App\\Billing\\Target', 'app/Billing/Target.php'], ['App\\Target', 'second/Target.php'], ['Other\\Target', 'fallback/Other/Target.php'], ['App\\Target', 'app/Target.php']] as [$class, $path]) {
            $this->assertSame('compatible', $reader->assess($config, $class, $path)['group']);
        }
        $this->assertSame('check', $reader->assess($config, 'App\\Target', 'app/target.php')['group']);
        $this->composer(['autoload-dev' => ['psr-4' => ['App\\' => 'app/']]]);
        $this->assertSame('check', $reader->assess($reader->read(), 'App\\Target', 'app/Target.php')['group']);
    }

    public function test_complex_invalid_missing_or_large_composer_never_proves_mismatch(): void
    {
        $reader = new MoveAutoload(new Filesystem, $this->tempPath);
        foreach (['classmap', 'files', 'exclude-from-classmap', 'psr-0'] as $key) {
            $this->composer(['autoload' => ['psr-4' => ['App\\' => 'app/'], $key => ['other/']]]);
            $this->assertSame('check', $reader->assess($reader->read(), 'App\\Target', 'else/Target.php')['group']);
        }
        foreach ([['config' => ['classmap-authoritative' => true]], ['config' => ['optimize-autoloader' => true]], ['autoload' => 'bad'], ['autoload' => ['psr-4' => ['App\\' => ['../outside/']]]], ['config' => 'bad']] as $config) {
            $this->composer($config);
            $this->assertNotEmpty($reader->read()['uncertain']);
        }
        file_put_contents($this->tempPath.'/composer.json', '{bad');
        $this->assertSame('unreadable', $reader->read()['state']);
        unlink($this->tempPath.'/composer.json');
        $this->assertSame('missing', $reader->read()['state']);
        file_put_contents($this->tempPath.'/composer.json', str_repeat(' ', 1_000_001));
        $this->assertTrue($reader->read()['limited']);
    }

    public function test_expected_paths_and_mapping_counts_are_bounded(): void
    {
        $reader = new MoveAutoload(new Filesystem, $this->tempPath);
        $this->composer(['autoload' => ['psr-4' => ['App\\' => array_map(fn ($i) => 'root'.$i, range(1, 128)), '' => array_map(fn ($i) => 'fallback'.$i, range(1, 128))]], 'autoload-dev' => ['psr-4' => ['App\\' => 'dev/']]]);
        $result = $reader->assess($reader->read(), 'App\\Target', 'unknown.php');
        $this->assertSame('check', $result['group']);
        $this->assertCount(256, $result['expected']);
        $mappings = [];
        foreach (range(0, 1000) as $i) {
            $mappings['Name'.$i.'\\'] = 'app/';
        }
        $this->composer(['autoload' => ['psr-4' => $mappings]]);
        $this->assertTrue($reader->read()['limited']);
    }

    public function test_composer_changes_have_fresh_hash_outside_graph_cache_and_final_read(): void
    {
        $this->fixture();
        $cold = $this->query(class: 'App\\Renamed', path: 'app/Renamed.php', cache: true);
        $warm = $this->query(class: 'App\\Renamed', path: 'app/Renamed.php', cache: true);
        $this->assertSame('fresh', $warm['cache']);
        $this->assertSame($cold['move'], $warm['move']);
        $this->composer(['autoload' => ['psr-4' => ['App\\' => 'other/']]]);
        $changed = $this->query(class: 'App\\Renamed', path: 'app/Renamed.php', cache: true);
        $this->assertSame('fresh', $changed['cache']);
        $this->assertNotSame($warm['snapshot'], $changed['snapshot']);
        $this->assertNotSame($warm['move']['autoload']['hash'], $changed['move']['autoload']['hash']);
        $files = new class extends Filesystem
        {
            private int $reads = 0;

            public function get($path, $lock = false)
            {
                if (str_ends_with($path, '/composer.json') && ++$this->reads === 2) {
                    file_put_contents($path, '{"autoload":{"psr-4":{"App\\\\":"changed/"}}}');
                }

                return parent::get($path, $lock);
            }
        };
        $stale = $this->query(class: 'App\\Renamed', files: $files);
        $this->assertFalse($stale['move']['autoload']['fresh']);
        $this->assertStringContainsString('Composer configuration changed', json_encode($stale['analysis']['notices']));
    }

    public function test_file_namespace_dependencies_and_external_autoload_directories_are_checks(): void
    {
        $this->composer();
        $this->write('app/Target.php', 'namespace App; class Target {} new Other;');
        $result = $this->query(class: 'App\\Billing\\Target', path: 'app/Billing/Target.php');
        $rows = array_filter($result['move']['check'], fn ($row) => $row['kind'] === 'namespace_dependency');
        $this->assertContains('(file) app/Target.php', array_column($rows, 'symbol'));
        symlink(sys_get_temp_dir(), $this->tempPath.'/outside');
        $this->composer(['autoload' => ['psr-4' => ['App\\' => 'outside/']]]);
        $reader = new MoveAutoload(new Filesystem, $this->tempPath);
        $this->assertSame('check', $reader->assess($reader->read(), 'App\\Target', 'app/Target.php')['group']);
        unlink($this->tempPath.'/outside');
        $this->composer(['autoload' => ['psr-4' => ['App\\' => [str_repeat('x', 2001), '\\external', "app/\n"]]]]);
        $this->assertNotEmpty($reader->read()['uncertain']);
    }

    public function test_self_new_and_static_uses_have_check_evidence_for_class_and_file_modes(): void
    {
        $this->composer();
        $this->write('app/Target.php', "namespace App;\nclass Target {\n public function make() {\n  new Target;\n  Target::run();\n }\n public static function run() {}\n}");
        foreach ([$this->query(), $this->query(class: 'App\\Billing\\Renamed', path: 'app/Billing/Renamed.php'), $this->query('app/Target.php'), $this->query('app/Target.php', path: 'app/relocated/Target.php')] as $result) {
            $rows = array_values(array_filter($result['move']['check'], fn ($row) => $row['symbol'] === 'App\\Target::make' && ($row['target'] ?? null) === 'App\\Target'));
            $this->assertCount(2, $rows);
            $this->assertSame(['new', 'static'], array_column($rows, 'kind'));
            $this->assertSame([4, 5], array_column($rows, 'line'));
            foreach ($rows as $row) {
                $this->assertSame('app/Target.php', $row['path']);
                $this->assertStringContainsString('inside the subject', implode(' ', $row['reasons']));
            }
            $this->assertNotContains('App\\Target::make', array_column($result['move']['breaking'], 'symbol'));
        }
    }

    public function test_limits_legacy_and_cli_mcp_schema_parity(): void
    {
        $this->fixture();
        $limited = $this->query(class: 'App\\Renamed', limit: 0);
        $this->assertSame('limit', $limited['move']['status']);
        $this->assertSame([], $limited['move']['breaking']);
        $impact = new ArchitectureImpact(new Filesystem, $this->tempPath);
        $this->assertArrayNotHasKey('move', $impact->inspect('Target'));
        $this->assertArrayHasKey('delete', $impact->inspect('Target', change: 'delete'));
        $this->assertArrayHasKey('signature', $impact->inspect('Target::run', signature: 'run()'));
        $this->write('config/architectures.php', "return ['enabled' => ['actions'], 'audit' => ['cache' => false]];");
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Target', '--change' => 'move', '--target-class' => 'App\\Renamed', '--target-path' => 'app/Renamed.php', '--agent' => true, '--limit' => 100]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Target', 'change' => 'move', 'target_class' => 'App\\Renamed', 'target_path' => 'app/Renamed.php', 'limit' => 100])->assertOk()->assertStructuredContent(fn ($json) => $json->where('move', $cli['move'])->etc());
        $this->assertArrayHasKey('move', ImpactSchema::get()['oneOf'][0]['properties']);
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Target', '--change' => 'move']));
        $this->assertStringContainsString('Move inspect:', Artisan::output());
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Target', 'change' => 'move', 'target_path' => 12])->assertStructuredContent(fn ($json) => $json->where('m', 'E_INVALID_TOOL_INPUT')->etc());
    }
}
