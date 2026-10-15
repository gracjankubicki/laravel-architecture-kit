<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use RuntimeException;
use Throwable;

/** Explicit application bootstrap, isolated from the tool's autoloader. */
final class RuntimeRouteMap
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private float $startedAt = 0;

    private ?RouteMap $result = null;

    private string $driver = 'php';

    public static function fresh(RepositoryContext $context): RouteMap
    {
        return self::start($context)->finish();
    }

    /** Start a fresh registration read while independent source checks run. */
    public static function start(RepositoryContext $context): self
    {
        $pending = new self;
        if (! $context->runtimeRoutes) {
            $pending->result = new RouteMap(unavailable: 'Runtime routes require explicit --runtime-routes.');

            return $pending;
        }
        try {
            $settings = $context->settings['runtime']['routes'] ?? [];
            if (! is_array($settings)) {
                throw new RuntimeException('Invalid runtime route settings.');
            }
            $driver = $settings['driver'] ?? 'php';
            $project = $context->rootPath;
            $command = [PHP_BINARY];
            if ($driver === 'docker-compose') {
                $service = $settings['service'] ?? 'laravel.test';
                $user = $settings['user'] ?? 'glengarry';
                $project = $settings['project_path'] ?? '/home/glengarry/app';
                foreach ([$service, $user] as $value) {
                    if (! is_string($value) || ! preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]*$/D', $value)) {
                        throw new RuntimeException('Invalid runtime container service or user.');
                    }
                }
                $command = ['docker', 'compose', '-f', 'docker-compose.yml', 'exec', '-T', '-u', $user, $service, 'php'];
            } elseif ($driver !== 'php') {
                throw new RuntimeException('Unsupported runtime route driver.');
            }
            if (! is_string($project) || ! str_starts_with($project, '/') || preg_match('~(^|/)\.\.?(/|$)~', $project)) {
                throw new RuntimeException('Invalid runtime project path.');
            }
            array_push($command, '-d', 'pcov.enabled=0', '-d', 'memory_limit='.ini_get('memory_limit'));
            $script = '<?php $_SERVER["argv"] = ["architecture-kit-routes", '.var_export($project, true).'];'
                .' $cache = sys_get_temp_dir()."/architecture-kit-routes-".bin2hex(random_bytes(12)); mkdir($cache, 0700);'
                .' foreach (["APP_ROUTES_CACHE" => "routes", "APP_PACKAGES_CACHE" => "packages", "APP_SERVICES_CACHE" => "services"] as $key => $name) { putenv($key."=".$cache."/".$name.".php"); $_ENV[$key] = $_SERVER[$key] = $cache."/".$name.".php"; }'
                .' register_shutdown_function(static function () use ($cache) { foreach (glob($cache."/*.php") ?: [] as $file) { unlink($file); } rmdir($cache); });'
                .' require '.var_export($project.'/vendor/autoload.php', true).';';
            foreach (['RouteEntry', 'RouteMap'] as $name) {
                $source = file_get_contents(__DIR__.'/../Audit/ReadSide/'.$name.'.php');
                if ($source === false) {
                    throw new RuntimeException('Runtime worker source unavailable.');
                }
                $class = 'GracjanKubicki\\ArchitectureKit\\Audit\\ReadSide\\'.$name;
                $script .= ' if (!class_exists('.var_export($class, true).', false)) { eval('.var_export(substr($source, 5), true).'); }';
            }
            $worker = file_get_contents(__DIR__.'/../Audit/ReadSide/route-snapshot.php');
            if ($worker === false) {
                throw new RuntimeException('Runtime worker unavailable.');
            }
            $script .= ' eval('.var_export(substr($worker, 5), true).');';
            $input = tmpfile();
            if ($input === false) {
                throw new RuntimeException('Runtime worker input unavailable.');
            }
            try {
                if (fwrite($input, $script) !== strlen($script) || ! rewind($input)) {
                    throw new RuntimeException('Runtime worker input could not be prepared.');
                }
                $pending->startedAt = microtime(true);
                $process = proc_open($command, [0 => $input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $context->rootPath);
                if (! is_resource($process)) {
                    throw new RuntimeException('Runtime worker unavailable.');
                }
                $pending->process = $process;
                $pending->pipes = $pipes;
                foreach ($pipes as $pipe) {
                    stream_set_blocking($pipe, false);
                }
            } finally {
                fclose($input);
            }
            $pending->driver = $driver;
        } catch (Throwable) {
            $pending->result = self::unavailable();
            $pending->stop();
        }

        return $pending;
    }

    /** Await the full result before endpoint analysis; no cached runtime payload. */
    public function finish(): RouteMap
    {
        if ($this->result !== null) {
            return $this->result;
        }
        try {
            [$output, $exitCode] = $this->readOutput();
            $marker = strrpos($output, 'ARCHITECTURE_KIT_ROUTES=');
            if ($exitCode !== 0 || $marker === false) {
                throw new RuntimeException('Application route bootstrap failed.');
            }
            $map = RouteMap::fromSnapshot(json_decode(substr($output, $marker + strlen('ARCHITECTURE_KIT_ROUTES=')), true, 64, JSON_THROW_ON_ERROR));
            $this->result = new RouteMap($map->methods, entries: $map->entries, context: [...($map->context ?? []),
                'runtime_routes' => ['knowledge' => 'runtime_registration', 'registration_observed' => true,
                    'execution_proven' => false, 'driver' => $this->driver, 'scope' => 'selected application environment'],
            ], notices: $map->notices);
        } catch (Throwable) {
            $this->result = self::unavailable();
        } finally {
            $this->stop();
        }

        return $this->result;
    }

    private static function unavailable(): RouteMap
    {
        $message = 'Runtime route discovery failed, exceeded its 30 second/output budget, or has invalid settings. Check the selected local application/container separately; verification remains blocked.';

        return new RouteMap(unavailable: $message, notices: [new AnalysisNotice('A_ROUTE_CONTEXT_UNAVAILABLE', 'runtime_routes_unavailable', 'routes', 1, $message)]);
    }

    /** @return array{string, int} */
    private function readOutput(): array
    {
        if (! is_resource($this->process)) {
            throw new RuntimeException('Runtime worker unavailable.');
        }
        $output = '';
        $bytes = 0;
        $exitCode = -1;
        do {
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
            } elseif (microtime(true) - $this->startedAt > 30) {
                throw new RuntimeException('Runtime route discovery timed out.');
            }
            $read = array_filter($this->pipes, static fn ($pipe): bool => ! feof($pipe));
            if ($read !== []) {
                $write = $except = [];
                $ready = stream_select($read, $write, $except, 0, 10_000);
                if ($ready === false) {
                    throw new RuntimeException('Runtime worker output unavailable.');
                }
                if ($ready === 0 && microtime(true) - $this->startedAt > 30) {
                    throw new RuntimeException('Runtime route discovery timed out.');
                }
                foreach ($read as $index => $pipe) {
                    // Bound each read before accumulating it. Some process wrappers
                    // drain a continuously writable pipe before invoking callbacks.
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false || ($bytes += strlen($chunk)) > 8_000_000) {
                        throw new RuntimeException('Runtime route output exceeds its budget.');
                    }
                    if ($index === 1) {
                        $output .= $chunk;
                    }
                }
            } elseif ($status['running']) {
                usleep(1000);
            }
        } while ($status['running'] || array_filter($this->pipes, static fn ($pipe): bool => ! feof($pipe)) !== []);

        return [$output, $exitCode];
    }

    private function stop(): void
    {
        $process = $this->process;
        $this->process = null;
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        try {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
        } catch (Throwable) {
            // Cleanup must not replace an unavailable result or throw on destruction.
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
