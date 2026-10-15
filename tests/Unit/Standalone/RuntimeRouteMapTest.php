<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\RuntimeRouteMap;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RuntimeRouteMapTest extends TestCase
{
    private string $directory;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/runtime-route-map-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/repo', 0700, true);
        mkdir($this->directory.'/bin', 0700, true);
        file_put_contents($this->directory.'/repo/composer.json', '{}');
        $this->originalPath = getenv('PATH') ?: '';
        putenv('PATH='.$this->directory.'/bin:'.$this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_compose_uses_an_argv_command_and_streams_a_self_contained_worker(): void
    {
        $map = new RouteMap(entries: [new RouteEntry(['GET'], 'known', class: 'App\\Controller', method: 'index')]);
        $this->docker('echo '.var_export('ARCHITECTURE_KIT_ROUTES='.json_encode($map->snapshot()), true).';');
        $result = RuntimeRouteMap::fresh($this->context());
        self::assertNull($result->unavailable);
        self::assertSame(['GET'], $result->verbs('App\\Controller', 'index'));
        self::assertFalse($result->context['runtime_routes']['execution_proven']);
        self::assertTrue($result->context['runtime_routes']['registration_observed']);
        $argv = json_decode(file_get_contents($this->directory.'/arguments'), true);
        self::assertSame(['compose', '-f', 'docker-compose.yml', 'exec', '-T', '-u', 'glengarry', 'laravel.test', 'php', '-d', 'pcov.enabled=0', '-d', 'memory_limit='.ini_get('memory_limit')], array_slice($argv, 1));
        $input = file_get_contents($this->directory.'/input');
        self::assertStringContainsString('APP_PACKAGES_CACHE', $input);
        self::assertStringContainsString('APP_SERVICES_CACHE', $input);
        self::assertStringContainsString('/home/glengarry/app/vendor/autoload.php', $input);
        self::assertStringContainsString('getRoutes()', $input);
        self::assertStringNotContainsString('->dispatch(', $input);
    }

    public function test_php_driver_inherits_the_parent_memory_limit(): void
    {
        $context = $this->context(driver: 'php');
        mkdir($this->directory.'/repo/vendor');
        $map = new RouteMap(entries: [new RouteEntry(['GET'], 'known', class: 'App\\Controller', method: 'index')]);
        file_put_contents($this->directory.'/repo/vendor/autoload.php', '<?php file_put_contents('.var_export($this->directory.'/runtime-limit', true).', ini_get("memory_limit")); echo '.var_export('ARCHITECTURE_KIT_ROUTES='.json_encode($map->snapshot()), true).'; exit;');

        $result = RuntimeRouteMap::fresh($context);

        self::assertNull($result->unavailable);
        self::assertSame(ini_get('memory_limit'), file_get_contents($this->directory.'/runtime-limit'));
        self::assertSame(['GET'], $result->verbs('App\\Controller', 'index'));
    }

    public function test_registration_runs_while_the_caller_does_independent_work(): void
    {
        $started = $this->directory.'/started';
        $release = $this->directory.'/release';
        $map = new RouteMap(entries: [new RouteEntry(['GET'], 'known', class: 'App\\Controller', method: 'index')]);
        $this->docker('file_put_contents('.var_export($started, true).', "started"); while (! is_file('.var_export($release, true).')) { usleep(1000); } echo '.var_export('ARCHITECTURE_KIT_ROUTES='.json_encode($map->snapshot()), true).';');

        $pending = RuntimeRouteMap::start($this->context());
        $deadline = microtime(true) + 5;
        while (! is_file($started) && microtime(true) < $deadline) {
            usleep(1000);
        }
        try {
            self::assertFileExists($started);
            self::assertFileDoesNotExist($release);
        } finally {
            file_put_contents($release, 'independent work finished');
        }
        $result = $pending->finish();
        self::assertNull($result->unavailable);
        self::assertSame(['GET'], $result->verbs('App\\Controller', 'index'));
        self::assertSame($result, $pending->finish());
    }

    public function test_missing_marker_failed_process_and_oversized_output_remain_unavailable(): void
    {
        foreach (['echo "not a snapshot";', 'exit(2);', 'echo str_repeat("x", 8_000_001);'] as $code) {
            $this->docker($code);
            $map = RuntimeRouteMap::fresh($this->context());
            self::assertNotNull($map->unavailable);
            self::assertNotEmpty($map->notices);
        }
    }

    public function test_no_opt_in_never_starts_the_runtime_driver(): void
    {
        $this->docker('echo "unexpected";');
        $map = RuntimeRouteMap::fresh($this->context(false));
        self::assertNotNull($map->unavailable);
        self::assertFileDoesNotExist($this->directory.'/arguments');
    }

    public function test_continuous_oversized_output_returns_unavailable_and_stops_the_process(): void
    {
        foreach (['echo str_repeat("x", 65536);', 'fwrite(STDERR, str_repeat("x", 65536));'] as $write) {
            $this->docker('while (true) { '.$write.' }');
            $pending = RuntimeRouteMap::start($this->context());
            $process = (new ReflectionProperty(RuntimeRouteMap::class, 'process'))->getValue($pending);
            self::assertIsResource($process);

            $map = $pending->finish();

            self::assertNotNull($map->unavailable);
            self::assertNotEmpty($map->notices);
            self::assertFalse(is_resource($process));
            self::assertSame($map, $pending->finish());
            unset($pending);
            self::assertFalse(is_resource($process));
        }
    }

    public function test_completed_registration_is_read_after_independent_work_outlasts_timeout(): void
    {
        $map = new RouteMap(entries: [new RouteEntry(['GET'], 'known', class: 'App\\Controller', method: 'index')]);
        $this->docker('echo '.var_export('ARCHITECTURE_KIT_ROUTES='.json_encode($map->snapshot()), true).';');
        $pending = RuntimeRouteMap::start($this->context());
        $process = (new ReflectionProperty(RuntimeRouteMap::class, 'process'))->getValue($pending);
        $deadline = microtime(true) + 5;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(1000);
        }
        self::assertFalse(proc_get_status($process)['running']);
        (new ReflectionProperty(RuntimeRouteMap::class, 'startedAt'))->setValue($pending, microtime(true) - 31);

        $result = $pending->finish();

        self::assertNull($result->unavailable);
        self::assertSame(['GET'], $result->verbs('App\\Controller', 'index'));
    }

    public function test_running_registration_past_timeout_stops_without_returning_a_partial_snapshot(): void
    {
        $this->docker('sleep(60);');
        $pending = RuntimeRouteMap::start($this->context());
        $process = (new ReflectionProperty(RuntimeRouteMap::class, 'process'))->getValue($pending);
        self::assertTrue(proc_get_status($process)['running']);
        (new ReflectionProperty(RuntimeRouteMap::class, 'startedAt'))->setValue($pending, microtime(true) - 31);

        $result = $pending->finish();

        self::assertNotNull($result->unavailable);
        self::assertFalse(is_resource($process));
    }

    public function test_abandoning_pending_registration_stops_a_noisy_process_without_throwing(): void
    {
        $this->docker('while (true) { echo str_repeat("x", 65536); }');
        $pending = RuntimeRouteMap::start($this->context());
        $process = (new ReflectionProperty(RuntimeRouteMap::class, 'process'))->getValue($pending);
        self::assertIsResource($process);
        self::assertTrue(proc_get_status($process)['running']);

        unset($pending);

        self::assertFalse(is_resource($process));
    }

    private function context(bool $enabled = true, string $driver = 'docker-compose'): RepositoryContext
    {
        $registration = (new Projects($this->directory.'/home'))->register($this->directory.'/repo', ['actions']);
        file_put_contents($registration['config'], json_encode(['enabled' => ['actions'], 'runtime' => ['routes' => ['driver' => $driver]]]));

        return new RepositoryContext(realpath($this->directory.'/repo'), $this->directory.'/home', runtimeRoutes: $enabled);
    }

    private function docker(string $code): void
    {
        $script = "#!/usr/bin/env php\n<?php file_put_contents(".var_export($this->directory.'/arguments', true).', json_encode($argv)); file_put_contents('.var_export($this->directory.'/input', true).', file_get_contents("php://stdin")); '.$code;
        file_put_contents($this->directory.'/bin/docker', $script);
        chmod($this->directory.'/bin/docker', 0700);
    }
}
