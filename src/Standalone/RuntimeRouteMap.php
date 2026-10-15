<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\WorkerOutputGate;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Explicit application bootstrap, isolated from the tool's autoloader. */
final class RuntimeRouteMap
{
    private ?Process $process = null;

    private ?WorkerOutputGate $outputGate = null;

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
            $process = new Process($command, $context->rootPath);
            $process->setInput($script);
            $process->setTimeout(30);
            $pending->process = $process;
            $pending->driver = $driver;
            $bytes = 0;
            $gate = $pending->outputGate = new WorkerOutputGate;
            $process->start(static function (string $type, string $output) use (&$bytes, $gate): void {
                if (! $gate->accepting) {
                    return;
                }
                if (($bytes += strlen($output)) > 8_000_000) {
                    throw new RuntimeException('Runtime route output exceeds its budget.');
                }
            });
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
            $process = $this->process;
            if ($process === null) {
                throw new RuntimeException('Runtime worker unavailable.');
            }
            // Poll first, so independent checks taking longer than the runtime
            // timeout do not mark an already completed registration read as failed.
            if ($process->isRunning()) {
                $process->wait();
            }
            $output = $process->getOutput();
            $marker = strrpos($output, 'ARCHITECTURE_KIT_ROUTES=');
            if (! $process->isSuccessful() || $marker === false) {
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

    private function stop(): void
    {
        if ($this->outputGate !== null) {
            $this->outputGate->accepting = false;
            $this->outputGate = null;
        }
        $process = $this->process;
        $this->process = null;
        try {
            $process?->stop(0);
        } catch (Throwable) {
            // Cleanup must not replace an unavailable result or throw on destruction.
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
