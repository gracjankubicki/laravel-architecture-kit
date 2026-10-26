<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
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
        GraphRuntime::beginRequest();
        try {
            [$args, $options] = self::options($argv);
            $home = $options['tool-home'] ?? getenv('ARCHITECTURE_KIT_HOME') ?: (getenv('HOME').'/'.'.architecture-kit');
            if (! is_string($home) || ! str_starts_with($home, '/')) {
                throw new RuntimeException('Tool home must be an absolute external directory.');
            }
            $command = $args[1] ?? 'help';
            if (isset($options['family']) && (isset($options['project']) || ! in_array($command, ['mcp', 'integration'], true))) {
                throw new RuntimeException('--family is only available for mcp or integration and cannot be combined with --project.');
            }
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
                self::output((new AgentHooks($home, $options['profile'] ?? null, $options['project'] ?? null, isset($options['runtime-routes']), isset($options['enforce']) ? ControlPolicy::Enforce : null))->handle($args[2] ?? '', $input));

                return 0;
            }
            if ($command === 'help') {
                fwrite(STDOUT, "architecture-kit [--project PATH | --family PATH (mcp/integration)] [--tool-home PATH] [--profile NAME] COMMAND\nengine install|status|describe\nmap prepare|status JOB_ID|result JOB_ID\ninit [architecture ...] [--shared --allow-write]\nanalysis-consent grant-deep TASK [--confirm]\ntask begin|check|status|skip|bind TASK [--confirm] [--client codex|claude --session ID]\nintegration export|status codex|claude\nmcp | audit | guard | doctor | search | impact | path | reach | file-rules | guidelines | make | sync\nDefault: source-only, external state. --runtime-routes explicitly boots the application to read routes. --allow-write authorizes repository mutations. --allow-execution enables broader application/custom-rule execution.\n");

                return 0;
            }
            if (isset($options['family'])) {
                $family = $options['family'];
                if (! is_string($family) || ! str_starts_with($family, '/')) {
                    throw new RuntimeException('--family requires an absolute Git family directory.');
                }
                $family = GitFamily::inspect($family)->codeRoot;
                if ($command === 'integration') {
                    self::externalHome($home, $family);
                    $hooks = new AgentHooks($home, $options['profile'] ?? null);
                    $client = $args[3] ?? '';
                    self::output(match ($args[2] ?? '') {
                        'export' => $hooks->exportFamily($client, realpath($argv[0]) ?: throw new RuntimeException('Executable path is unavailable.'), $family),
                        'status' => $hooks->familyStatus($client, $family),
                        default => throw new RuntimeException('Unknown integration action.'),
                    });

                    return 0;
                }
                RepositoryContext::activate(null);
                RepositoryBinding::activate(new RepositoryBinding($family, $home, $options['profile'] ?? null,
                    isset($options['allow-execution']), isset($options['allow-write']), isset($options['runtime-routes'])));
                Host::create($home);
                GraphRuntime::enable();
                self::mcp();

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
            if ($command === '__graph-runtime') {
                $directory = $args[3] ?? '';
                StateStore::assertBoundary($directory, $context->home);
                $spec = (new StateStore($directory, $context->home))->read($args[4] ?? '');
                if (($spec['advisory_only'] ?? false) === true) {
                    $context = new RepositoryContext($root, $home, $context->profile, $context->execute, $context->write, $context->runtimeRoutes, advisoryOnly: true);
                    Host::refresh($context);
                }
                if (isset($spec['configuration_fingerprint']) && $spec['configuration_fingerprint'] !== $context->fingerprint()) {
                    throw new RuntimeException('stale_snapshot: graph launch configuration changed.');
                }
                if (($args[2] ?? '') === 'coordinator') {
                    (new GraphCoordinator($context, $directory, $spec['token'], $spec['endpoint']))->run();
                } elseif (($args[2] ?? '') === 'worker') {
                    (new GraphWorker($context, $directory, $spec))->run();
                } else {
                    throw new RuntimeException('Unknown graph process role.');
                }

                return 0;
            }
            if ($command === 'map') {
                self::output((new GraphRuntime($context))->map($args[2] ?? 'prepare', $args[3] ?? null));

                return 0;
            }
            GraphRuntime::enable();
            if ($command === 'task') {
                $task = $args[3] ?? '';
                $tasks = new TaskControl($context, isset($options['enforce']) ? ControlPolicy::Enforce : null, strict: isset($options['strict']));
                $result = match ($args[2] ?? '') {
                    'begin' => $tasks->begin($task),
                    'check' => $tasks->check($task),
                    'status' => $tasks->status($task),
                    'skip' => $tasks->skip($task, isset($options['confirm'])),
                    'bind' => SessionBinding::bind($context, $options['client'] ?? null, $options['session'] ?? null, $task),
                    default => throw new RuntimeException('Unknown task action.'),
                };
                self::output($result);

                return ($result['status'] ?? '') === 'skipped' ? 0 : (($result['control']['blocking'] ?? false) ? 1 : 0);
            }
            if ($command === 'analysis-consent') {
                if (($args[2] ?? '') !== 'grant-deep' || ! isset($options['confirm'])) {
                    throw new RuntimeException('Record previously obtained human consent with analysis-consent grant-deep TASK --confirm.');
                }
                self::output(['ok' => true, 'consent' => (new AnalysisConsent($context))->grantDeep($args[3] ?? '')]);

                return 0;
            }
            if ($command === 'integration') {
                $hooks = new AgentHooks($home, $context->profile, runtimeRoutes: $context->runtimeRoutes, policy: isset($options['enforce']) ? ControlPolicy::Enforce : null);
                $client = $args[3] ?? '';
                self::output(match ($args[2] ?? '') {
                    'export' => $hooks->export($client, realpath($argv[0]) ?: throw new RuntimeException('Executable path is unavailable.'), $root),
                    'status' => $hooks->status($client, $context),
                    default => throw new RuntimeException('Unknown integration action.'),
                });

                return 0;
            }
            if ($command === 'mcp') {
                self::mcp();

                return 0;
            }
            $console = new Application($host, $host['events'], 'Architecture Kit');
            foreach (['Engine', 'Audit', 'Guard', 'Doctor', 'ArchitectureContext', 'Search', 'Impact', 'Path', 'Reach', 'PublicApi', 'RevisionDiff', 'Target', 'FileRules', 'Guidelines', 'Explain', 'Plan', 'UpgradePlan', 'Make', 'Sync', 'CacheClear'] as $name) {
                $class = 'GracjanKubicki\\ArchitectureKit\\Commands\\'.$name.'Command';
                $instance = new $class;
                $instance->setName(str_replace('architecture-kit:', '', $instance->getName()));
                $console->add($instance);
            }
            if (isset($options['enforce']) && in_array($command, ['audit', 'guard'], true)) {
                $args[] = '--enforce';
            }
            if (isset($options['strict']) && in_array($command, ['audit', 'guard'], true)) {
                $args[] = '--strict';
            }
            $console->setAutoExit(false);

            return $console->run(new ArgvInput($args));
        } catch (Throwable $error) {
            $policy = isset($options['enforce']) ? ControlPolicy::Enforce : ControlPolicy::Advisory;
            try {
                $policy = isset($options['enforce']) ? ControlPolicy::Enforce : ($context ?? null)?->controlPolicy() ?? ControlPolicy::Advisory;
            } catch (Throwable) {
                // Invalid policy is itself unverified; explicit enforce still applies.
            }
            $failure = ['ok' => false, 'status' => 'blocked', 'reason' => $error->getMessage(), 'analysis' => ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false], 'control' => $policy->result(false)];
            if (($command ?? null) === 'hook') {
                $message = 'Architecture Kit unverified: '.$error->getMessage().'. Continue with source inspection and report this gap in the handoff.';
                self::output($policy === ControlPolicy::Enforce ? ['decision' => 'block', 'reason' => $message] : ['systemMessage' => $message]);

                return 0;
            }
            fwrite(STDERR, json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return $policy === ControlPolicy::Enforce ? 2 : 0;
        } finally {
            GraphRuntime::enable(false);
            RepositoryContext::activate(null);
            RepositoryBinding::activate(null);
        }
    }

    private static function mcp(): void
    {
        $transportClass = new \ReflectionClass(ReportStdioTransport::class);
        $arguments = ($transportClass->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0 ? [bin2hex(random_bytes(16))] : [];
        $transport = $transportClass->newInstanceArgs($arguments);
        $server = new StandaloneServer($transport);
        $server->start();
        $transport->run();
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
        $values = ['family', 'project', 'tool-home', 'profile', 'client', 'session'];
        $flags = ['allow-write', 'allow-execution', 'runtime-routes', 'confirm', 'shared', 'enforce', 'strict'];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            $parts = explode('=', ltrim($arg, '-'), 2);
            if (in_array($parts[0], ['enforce', 'strict'], true) && isset($parts[1])) {
                throw new RuntimeException('Use the bare --'.$parts[0].' flag.');
            }
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
