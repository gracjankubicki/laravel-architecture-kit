<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\Distribution\EngineInstallation;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use Illuminate\Console\Application;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;

final class Runner
{
    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        try {
            [$args, $options] = self::options($argv);
            $home = $options['tool-home'] ?? getenv('ARCHITECTURE_KIT_HOME') ?: (getenv('HOME').'/'.'.architecture-kit');
            if (! is_string($home) || ! str_starts_with($home, '/')) {
                throw new RuntimeException('Tool home must be an absolute external directory.');
            }
            $command = $args[1] ?? 'help';
            if ($command === 'engine' && ($args[2] ?? '') !== 'describe') {
                if (isset($options['project'])) {
                    self::externalHome($home, Projects::discover($options['project']));
                }
                $installation = new EngineInstallation($home);
                if (($args[2] ?? '') === 'install') {
                    self::output($installation->install());
                } elseif (($args[2] ?? '') === 'status') {
                    $binary = $installation->binary();
                    $descriptor = (new MagoAnalysisEngine($binary))->descriptor();
                    self::output(['ok' => true, 'engine' => $descriptor->engine, 'version' => $descriptor->version,
                        'schema' => $descriptor->schema, 'capabilities' => $descriptor->capabilities, 'binary' => $binary]);
                } else {
                    throw new RuntimeException('Use architecture-kit engine install or engine status.');
                }

                return 0;
            }
            if ($command === 'hook') {
                $body = stream_get_contents(STDIN, 100001);
                if ($body === false || strlen($body) > 100000) {
                    throw new RuntimeException('Hook input exceeds its limit.');
                }
                $input = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($input)) {
                    throw new RuntimeException('Hook input must be an object.');
                }
                Host::create($home);
                self::output((new AgentHooks($home, $options['profile'] ?? null, $options['project'] ?? null, isset($options['runtime-routes'])))->handle($args[2] ?? '', $input));

                return 0;
            }
            if ($command === 'help') {
                fwrite(STDOUT, "architecture-kit [--project PATH] [--tool-home PATH] [--profile NAME] COMMAND\nengine install|status|describe\ninit [architecture ...] [--shared --allow-write]\ntask begin|check|status|skip|bind TASK [--confirm] [--client codex|claude --session ID]\nintegration export|status codex|claude\nmcp | audit | guard | doctor | search | impact | path | reach | file-rules | guidelines | make | sync\nDefault: source-only, external state. --runtime-routes explicitly boots the application to read routes. --allow-write authorizes repository mutations. --allow-execution enables broader application/custom-rule execution.\n");

                return 0;
            }
            $root = Projects::discover($options['project'] ?? getcwd());
            // Validate external home before registering or generating any state.
            self::externalHome($home, $root);
            if ($command === 'init') {
                if (isset($options['shared']) && ! isset($options['allow-write'])) {
                    throw new RuntimeException('Shared settings require --allow-write.');
                }
                self::output((new Projects($home))->register($root, array_slice($args, 2), $options['profile'] ?? null, isset($options['shared'])));

                return 0;
            }
            $context = new RepositoryContext($root, $home, $options['profile'] ?? null, isset($options['allow-execution']), isset($options['allow-write']), isset($options['runtime-routes']));
            RepositoryContext::activate($context);
            $host = Host::create($home);
            if ($command === 'task') {
                $task = $args[3] ?? '';
                $tasks = new TaskControl($context);
                $result = match ($args[2] ?? '') {
                    'begin' => $tasks->begin($task),
                    'check' => $tasks->check($task),
                    'status' => $tasks->status($task),
                    'skip' => $tasks->skip($task, isset($options['confirm'])),
                    'bind' => SessionBinding::bind($context, $options['client'] ?? null, $options['session'] ?? null, $task),
                    default => throw new RuntimeException('Unknown task action.'),
                };
                self::output($result);

                return in_array($result['status'] ?? 'bound', ['pass', 'started', 'bound', 'skipped'], true) ? 0 : 1;
            }
            if ($command === 'integration') {
                $hooks = new AgentHooks($home, $context->profile, runtimeRoutes: $context->runtimeRoutes);
                $client = $args[3] ?? '';
                self::output(match ($args[2] ?? '') {
                    'export' => $hooks->export($client, realpath($argv[0]) ?: throw new RuntimeException('Executable path is unavailable.'), $root),
                    'status' => $hooks->status($client, $context),
                    default => throw new RuntimeException('Unknown integration action.'),
                });

                return 0;
            }
            if ($command === 'mcp') {
                $transportClass = new \ReflectionClass(ReportStdioTransport::class);
                $arguments = ($transportClass->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0 ? [bin2hex(random_bytes(16))] : [];
                $transport = $transportClass->newInstanceArgs($arguments);
                $server = new StandaloneServer($transport);
                $server->start();
                $transport->run();

                return 0;
            }
            $console = new Application($host, $host['events'], 'Architecture Kit');
            foreach (['Engine', 'Audit', 'Guard', 'Doctor', 'ArchitectureContext', 'Search', 'Impact', 'Path', 'Reach', 'PublicApi', 'RevisionDiff', 'Target', 'FileRules', 'Guidelines', 'Explain', 'Plan', 'UpgradePlan', 'Make', 'Sync', 'CacheClear'] as $name) {
                $class = 'GracjanKubicki\\ArchitectureKit\\Commands\\'.$name.'Command';
                $instance = new $class;
                $instance->setName(str_replace('architecture-kit:', '', $instance->getName()));
                $console->add($instance);
            }
            $console->setAutoExit(false);

            return $console->run(new ArgvInput($args));
        } catch (Throwable $error) {
            fwrite(STDERR, json_encode(['ok' => false, 'status' => 'blocked', 'reason' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return 2;
        } finally {
            RepositoryContext::activate(null);
        }
    }

    public static function externalHome(string $home, string $root): void
    {
        if (! str_starts_with($home, '/') || preg_match('~/\.\.?(/|$)~', $home)) {
            throw new RuntimeException('Use an absolute tool home without traversal.');
        }
        $ancestor = $home;
        while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $canonical = realpath($ancestor);
        if ($canonical === false || $canonical === $root || str_starts_with($canonical, $root.'/')) {
            throw new RuntimeException('Tool home must be outside the repository, including symlink parents.');
        }
    }

    /** @param list<string> $argv
     * @return array{list<string>, array<string, string|true>}
     */
    private static function options(array $argv): array
    {
        $args = [];
        $options = [];
        $values = ['project', 'tool-home', 'profile', 'client', 'session'];
        $flags = ['allow-write', 'allow-execution', 'runtime-routes', 'confirm', 'shared'];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            $parts = explode('=', ltrim($arg, '-'), 2);
            if ($parts[0] === 'runtime-routes' && isset($parts[1])) {
                throw new RuntimeException('Use the bare --runtime-routes flag to explicitly enable application route bootstrap.');
            }
            if (str_starts_with($arg, '--') && in_array($parts[0], $values, true)) {
                $options[$parts[0]] = $parts[1] ?? ($argv[++$i] ?? throw new RuntimeException('Missing option value.'));
            } elseif (str_starts_with($arg, '--') && in_array($parts[0], $flags, true)) {
                $options[$parts[0]] = true;
            } else {
                $args[] = $arg;
            }
        }

        return [$args, $options];
    }

    /** @param array<string, mixed> $value */
    private static function output(array $value): void
    {
        fwrite(STDOUT, json_encode($value === [] ? (object) [] : $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
