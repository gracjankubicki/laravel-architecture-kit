<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Classification\DeclaredConfiguration;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\Runner;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch\RuntimeFixture;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Actual Runner/stdio clients. Measurement helpers never load application PHP. */
final class GraphPerformanceSmoke
{
    private string $profile;

    private array $scenario;

    private array $samples = [];

    private array $commands = [];

    private array $knownPids = [];

    private int $lastSample = 0;

    private array $summary = ['status' => 'OPEN'];

    public function __construct(private string $project, private string $home, private string $output, private string $backend, private string $case)
    {
        foreach ([$project, $home, $output] as $path) {
            if (realpath($path) !== $path || ! is_dir($path)) {
                throw new RuntimeException('Provide existing canonical absolute project, private home and output directories.');
            }
        }
        if (is_file($output.'/summary.json')) {
            throw new RuntimeException('Refusing to overwrite a previous result');
        }
        $this->scenario = self::json($output.'/scenario.json');
        if (! in_array($backend, ['php', 'mago'], true) || ! in_array($case, ['cold', 'warm', 'edit', 'add', 'delete', 'move', 'major', 'query', 'recovery'], true)) {
            throw new RuntimeException('Unsupported explicit backend or case');
        }
        if (! in_array($this->scenario['transport'] ?? null, ['cli', 'mcp'], true) || ! is_string($this->scenario['query'] ?? null) || trim($this->scenario['query']) === '') {
            throw new RuntimeException('scenario.json requires a transport and a frozen nonempty literal query');
        }
        $this->profile = 'performance-'.$backend;
        $this->summary += ['project' => $project, 'tool_home' => $home, 'output' => $output, 'case' => $case, 'backend' => $backend,
            'transport' => $this->scenario['transport'], 'query' => $this->scenario['query'], 'start_utc' => gmdate('c'),
            'memory_limit' => $this->scenario['memory_limit'] ?? '1G', 'php' => PHP_VERSION, 'os' => php_uname(), 'seed' => null,
            'sampling' => ['interval_ms' => 50, 'metric' => 'sum of simultaneous RSS for traced processes and descendants',
                'limitations' => 'Sampling can miss short peaks/workers and can count shared pages more than once. Not PHP used/allocated. Instrumentation overhead remains in product timings.'],
            'scope' => 'Static Basic; no application bootstrap, vendor PHP analysis, network or global installation'];
    }

    public function run(): int
    {
        try {
            $this->save('source-manifest-before.json', $this->sources());
            $this->setupHome($this->home);
            $context = $this->context($this->home);
            $this->summary['engine'] = (array) $context->analysis()->engine;
            $settings = CatalogSettings::load(new Filesystem, $this->project);
            $before = $this->inputs($settings);
            $this->save('inputs-before.json', $before);
            if ($this->case === 'cold' && (is_dir($context->storage.'/runtime') || is_dir($context->storage.'/graph'))) {
                throw new RuntimeException('Cold requires no prior map/runtime products in this home');
            }
            if ($this->case === 'warm') {
                $this->restart($context);
            }
            if (in_array($this->case, ['edit', 'add', 'delete', 'move', 'major'], true)) {
                $this->mutate();
            }
            if ($this->case === 'recovery') {
                throw new RuntimeException('Recovery requires a separately admitted concrete fault scenario; no simulated acceptance');
            }
            $this->summary['measurement_started_ns'] = hrtime(true);
            $ready = $this->client('prepare', 'prepare');
            $this->summary['initial_prepare'] = $ready;
            $deadline = microtime(true) + ($this->scenario['collection_timeout_s'] ?? 180);
            $poll = 0;
            while (($ready['availability'] ?? '') === 'building' && microtime(true) < $deadline) {
                usleep(100000);
                $ready = $this->client('collect-'.++$poll, 'result', $ready['job_id']);
            }
            $elapsed = (hrtime(true) - $this->summary['measurement_started_ns']) / 1e9;
            $this->summary['whole_call_to_current_s'] = $elapsed;
            $this->summary['ready'] = $ready;
            $threshold = match ($this->case) {
                'warm' => 2, 'edit', 'add', 'delete', 'move' => 3, default => 20
            };
            $current = ($ready['availability'] ?? '') === 'ready' && ($ready['freshness'] ?? '') === 'last_confirmed';
            $this->summary['threshold_s'] = $threshold;
            $this->summary['time_within_threshold'] = $current && $elapsed <= $threshold && ! ($this->summary['initial_prepare']['request_deadline_exceeded'] ?? false);
            if (! $current) {
                throw new RuntimeException('No current generation reference was collected');
            }
            $offset = count($this->events());
            $query = $this->client('query', 'query');
            $this->summary['public_query'] = $query;
            if (! ($query['ok'] ?? false) || ($query['candidates'] ?? $query['result']['candidates'] ?? []) === [] || ($query['runtime']['freshness'] ?? '') !== 'last_confirmed') {
                throw new RuntimeException('Expected a nonempty current public query result');
            }
            $markers = array_slice($this->events(), $offset);
            $this->save('query-markers.json', $markers);
            $start = null;
            foreach ($markers as $event) {
                if ($event['stage'] === 'query.before') {
                    $start = $event;
                }
                if ($event['stage'] === 'query.after' && $event['operation'] === ($this->scenario['transport'] === 'cli' ? 'discovery' : 'search') && $start !== null && $start['pid'] === $event['pid']) {
                    $this->summary['worker_dispatch_s'] = ($event['monotonic_ns'] - $start['monotonic_ns']) / 1e9;
                }
            }
            $this->summary['worker_dispatch_within_100ms'] = isset($this->summary['worker_dispatch_s']) && $this->summary['worker_dispatch_s'] <= 0.1;
            $resident = $this->residentRequest($ready);
            $this->summary['resident_public_query'] = $resident;
            $this->summary['resident_query_within_threshold'] = ($this->summary['resident_request_response_s'] ?? INF) <= 0.1;
            // All full-state comparisons and oracle work happen outside product timing.
            $actual = $this->state('actual', 'inspect', $this->home);
            $reusedSegments = ($ready['execution_mode'] ?? null) === 'restore'
                && ($ready['cache']['write']['reason'] ?? null) === 'unchanged_product_set'
                && isset($actual['storage_generation'], $ready['cache']['write']['generation'])
                && $actual['storage_generation'] === $ready['cache']['write']['generation'];
            if (($actual['snapshot_id'] ?? null) !== ($ready['snapshot_id'] ?? null) && ! $reusedSegments) {
                throw new RuntimeException('Stored state is not the measured generation');
            }
            $this->summary['stored_generation_reused'] = $reusedSegments;
            if (isset($this->scenario['oracle_file'])) {
                $oracle = self::json($this->scenario['oracle_file']);
                if (($oracle['provenance']['input_digest'] ?? null) !== $this->inputDigest($this->inputs($settings))
                    || ($oracle['provenance']['source_digest'] ?? null) !== $this->sourceDigest() || ($oracle['provenance']['query'] ?? null) !== $this->scenario['query']
                    || ($oracle['provenance']['backend'] ?? null) !== $this->backend || ($oracle['provenance']['project'] ?? null) !== $this->project) {
                    throw new RuntimeException('Saved oracle provenance does not match this candidate/inputs/query/backend/root');
                }
            } else {
                $oracleHome = $this->home.'-oracle-'.basename($this->output);
                if (file_exists($oracleHome)) {
                    throw new RuntimeException('Oracle requires a fresh private home');
                }
                mkdir($oracleHome, 0700, true);
                $oracleHome = realpath($oracleHome);
                $this->setupHome($oracleHome);
                $oracle = $this->state('oracle', 'oracle', $oracleHome);
                $oracle['provenance'] = ['input_digest' => $this->inputDigest($this->inputs($settings)), 'source_digest' => $this->sourceDigest(),
                    'query' => $this->scenario['query'], 'backend' => $this->backend, 'project' => $this->project, 'home' => $oracleHome];
            }
            $this->save('oracle.json', $oracle);
            $semantic = $actual['full_record_digests'] === $oracle['full_record_digests'] && $actual['counts'] === $oracle['counts'];
            $semantic = $semantic && self::domainAnswer($query) === self::domainAnswer($oracle['queries'][$this->scenario['transport']])
                && self::domainAnswer($resident) === self::domainAnswer($oracle['queries']['mcp']);
            $this->summary['full_state_and_query_equal'] = $semantic;
            $this->summary['negative_comparator_controls'] = self::negativeControls($actual, $oracle);
            $after = $this->inputs($settings);
            $this->save('inputs-after.json', $after);
            $this->summary['source_version_stable'] = self::json($this->output.'/source-manifest-before.json') === $this->sources();
            $this->summary['inputs_stable_during_measurement'] = $this->inputDigest($after) === $this->inputDigest($this->inputsAtMeasurement ?? $before);
            $accepted = $semantic && $this->summary['negative_comparator_controls'] && $this->summary['inputs_stable_during_measurement'] && $this->summary['source_version_stable']
                && ($this->case === 'query' ? $this->summary['resident_query_within_threshold'] : $this->summary['time_within_threshold']);
            $this->summary['checks_satisfied'] = $accepted;

            return $accepted ? 0 : 1;
        } catch (Throwable $error) {
            $this->summary['error'] = get_class($error).': '.$error->getMessage();

            return 2;
        } finally {
            $this->summary['end_utc'] = gmdate('c');
            $this->summary['max_simultaneous_rss_kib'] = $this->samples === [] ? null : max(array_column($this->samples, 'sum_rss_kib'));
            $this->save('summary.json', $this->summary);
            $this->save('commands.json', $this->commands);
            $this->save('rss-samples.json', $this->samples);
            $this->save('events.json', $this->events());
            $this->save('source-manifest.json', $this->sources());
            echo json_encode($this->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
    }

    /** Normalize lifecycle only; records, positions, counts, diagnostics and selectors remain exact.
     * @param array<string, mixed> $answer
     * @return array<string, mixed> */
    private static function domainAnswer(array $answer): array
    {
        foreach (['runtime', 'runtime_semantics', 'confirmation', 'freshness', 'fresh'] as $key) {
            unset($answer[$key]);
        }
        if (isset($answer['snapshot'])) {
            $answer['snapshot'] = 'lifecycle-generation';
        }
        if (isset($answer['next']['arguments']['snapshot'])) {
            $answer['next']['arguments']['snapshot'] = 'lifecycle-generation';
        }
        foreach (['analysis', 'result'] as $container) {
            if (isset($answer[$container]) && is_array($answer[$container])) {
                foreach ($container === 'analysis' ? ['runtime_semantics', 'freshness', 'source_signature'] : ['execution'] as $key) {
                    unset($answer[$container][$key]);
                }
                if (isset($answer[$container]['analysis'])) {
                    foreach (['runtime_semantics', 'freshness', 'source_signature'] as $key) {
                        unset($answer[$container]['analysis'][$key]);
                    }
                }
            }
        }

        return $answer;
    }

    private function setupHome(string $home): void
    {
        Runner::externalHome($home, $this->project);
        for ($ancestor = $home; dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (is_file($ancestor.'/composer.json')) {
                throw new RuntimeException('Test home must be outside every Composer repository');
            }
        }
        $provenance = $home.'/performance-provenance.json';
        $expected = ['project' => $this->project, 'backend' => $this->backend, 'purpose' => 'T04 isolated static performance proof'];
        if (is_file($provenance)) {
            if (self::json($provenance) !== $expected) {
                throw new RuntimeException('Private home provenance mismatch');
            }
        } elseif (array_diff(scandir($home), ['.', '..']) !== []) {
            throw new RuntimeException('Refusing an existing nonempty home without own provenance');
        } else {
            file_put_contents($provenance, json_encode($expected, JSON_THROW_ON_ERROR));
        }
        $source = is_file($this->project.'/.architecture-kit/settings.json') ? $this->project.'/.architecture-kit/settings.json' : $this->project.'/config/architectures.php';
        if (is_file($source)) {
            $settings = str_ends_with($source, '.json') ? self::json($source) : DeclaredConfiguration::readSource(file_get_contents($source));
        } elseif (is_array($this->scenario['settings'] ?? null)) {
            $settings = $this->scenario['settings'];
            $this->summary['configuration_origin'] = 'explicit scenario settings for an unconfigured static copy';
        } else {
            throw new RuntimeException('Unconfigured static copy requires explicit scenario settings, without implicit defaults');
        }
        if (is_string($native = getenv('ARCHITECTURE_KIT_TEST_RUNTIME')) && is_file($native)) {
            $platform = RuntimePlatform::current();
            $store = new StateStore($home.'/runtimes/0.2.0/'.$platform, $home);
            copy($native, $store->directory.'/architecture-kit-runtime');
            chmod($store->directory.'/architecture-kit-runtime', 0700);
            $store->update('installation', static fn () => ['platform' => $platform,
                'manifest' => RuntimeFixture::manifest(file_get_contents($native))]);
        }
        $settings['analysis']['engine'] = $this->backend;
        $directory = $home.'/profiles/'.hash('sha256', $this->project);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.$this->profile.'.json';
        $body = json_encode($settings, JSON_THROW_ON_ERROR);
        if (is_file($path) && file_get_contents($path) !== $body) {
            throw new RuntimeException('Profile changed between measurements');
        }
        file_put_contents($path, $body);
        if ($this->backend === 'mago' && (glob($home.'/engines/*/*/installation.json') ?: []) === []) {
            $binary = $this->scenario['engine_binary'] ?? null;
            if (! is_string($binary) || ! is_file($binary)) {
                throw new RuntimeException('Mago requires an explicit existing approved engine_binary; downloads are forbidden');
            }
            putenv('ARCHITECTURE_KIT_TEST_ENGINE='.$binary);
            EngineFixture::install($home);
        }
    }

    private function context(string $home): RepositoryContext
    {
        $context = new RepositoryContext($this->project, $home, $this->profile);
        RepositoryContext::activate($context);
        Host::create($home);

        return $context;
    }

    private function client(string $name, string $action, ?string $job = null): array
    {
        $args = $action === 'query' ? ['search', $this->scenario['query'], '--agent'] : ['map', $action, ...($job === null ? [] : [$job])];
        $input = null;
        if ($this->scenario['transport'] === 'mcp') {
            $args = ['mcp'];
            $messages = [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'graph-performance', 'version' => '1']]],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $action === 'query' ? 'architecture-search' : 'architecture-map',
                    'arguments' => $action === 'query' ? ['query' => $this->scenario['query']] : ['action' => $action, ...($job === null ? [] : ['job_id' => $job])]]],
            ];
            $input = implode("\n", array_map(static fn ($message) => json_encode($message, JSON_THROW_ON_ERROR), $messages))."\n";
        }
        $raw = $this->execute($name, [PHP_BINARY, '-d', 'memory_limit='.$this->summary['memory_limit'], __DIR__.'/graph-performance-entrypoint.php',
            '--project', $this->project, '--tool-home', $this->home, '--profile', $this->profile, ...$args], $input);
        if ($this->scenario['transport'] === 'cli') {
            return json_decode(trim($raw), true, flags: JSON_THROW_ON_ERROR);
        }
        foreach (explode("\n", trim($raw)) as $line) {
            $response = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($response['id'] ?? null) === 2) {
                return json_decode($response['result']['content'][0]['text'] ?? throw new RuntimeException('MCP tool failed: '.$line), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        throw new RuntimeException('Missing MCP tool response');
    }

    private function residentRequest(array $ready): array
    {
        $command = [PHP_BINARY, '-d', 'memory_limit='.$this->summary['memory_limit'], __DIR__.'/graph-performance-entrypoint.php',
            '--project', $this->project, '--tool-home', $this->home, '--profile', $this->profile, 'mcp'];
        $input = new InputStream;
        $process = new Process($command, dirname(__DIR__, 2), timeout: 40);
        $process->setInput($input);
        $process->start();
        $this->knownPids[$process->getPid()] = true;
        $requests = [];
        $send = static function (array $message) use ($input, &$requests): void {
            $requests[] = ['monotonic_ns' => hrtime(true), 'message' => $message];
            $input->write(json_encode($message, JSON_THROW_ON_ERROR)."\n");
        };
        $buffer = '';
        $responses = [];
        $receive = function (int $id) use ($process, &$buffer, &$responses): array {
            $until = microtime(true) + 30;
            while (microtime(true) < $until) {
                $process->checkTimeout();
                $buffer .= $process->getIncrementalOutput();
                while (($end = strpos($buffer, "\n")) !== false) {
                    $message = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                    $buffer = substr($buffer, $end + 1);
                    if (isset($message['id'])) {
                        $responses[$message['id']] = $message;
                    }
                }
                if (isset($responses[$id])) {
                    return $responses[$id];
                }
                if (! $process->isRunning()) {
                    throw new RuntimeException('Resident MCP session exited before response');
                }
                $this->sample();
                usleep(1000);
            }
            throw new RuntimeException('Resident MCP response timeout');
        };
        try {
            $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26',
                'capabilities' => new stdClass, 'clientInfo' => ['name' => 'resident-graph-performance', 'version' => '1']]]);
            $receive(1);
            $send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
            $send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'architecture-map', 'arguments' => ['action' => 'prepare']]]);
            $prepared = $receive(2);
            $prepared = json_decode($prepared['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            $this->summary['resident_prepared'] = $prepared;
            if (($prepared['job_id'] ?? null) !== $ready['job_id'] || ($prepared['freshness'] ?? null) !== 'last_confirmed') {
                throw new RuntimeException('Resident session is not pinned to the already prepared current generation');
            }
            $this->summary['resident_preparation_completed_ns'] = hrtime(true);
            $offset = count($this->events());
            $start = hrtime(true);
            $send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'architecture-search', 'arguments' => ['query' => $this->scenario['query']]]]);
            $response = $receive(3);
            $this->summary['resident_request_response_s'] = (hrtime(true) - $start) / 1e9;
            $this->summary['resident_timing_scope'] = 'Initialized live MCP client: request write to full response, including IPC, freshness and decoding; no costs subtracted. Process startup, initialization and explicit preparation are separate.';
            $result = json_decode($response['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            if (! ($result['ok'] ?? false) || ($result['result']['candidates'] ?? []) === []
                || ($result['runtime']['freshness'] ?? '') !== 'last_confirmed' || ($result['runtime']['job_id'] ?? '') !== $ready['job_id']) {
                throw new RuntimeException('Resident request did not return nonempty current facts for the existing generation');
            }
            $this->save('resident-query-markers.json', array_slice($this->events(), $offset));

            return $result;
        } finally {
            $input->close();
            $process->wait();
            file_put_contents($this->output.'/resident-session.stdout', $process->getOutput());
            file_put_contents($this->output.'/resident-session.stderr', $process->getErrorOutput());
            $this->save('resident-session.command.json', ['command' => $command, 'requests' => $requests, 'responses' => $responses, 'exit' => $process->getExitCode()]);
        }
    }

    private function execute(string $name, array $command, ?string $input = null): string
    {
        $process = new Process($command, dirname(__DIR__, 2), timeout: $this->scenario['process_timeout_s'] ?? 240);
        $process->setInput($input);
        $start = hrtime(true);
        $utc = gmdate('c');
        $process->start();
        $this->knownPids[$process->getPid()] = true;
        try {
            do {
                $process->checkTimeout();
                $this->sample();
                usleep(1000);
            } while ($process->isRunning());
        } finally {
            $record = ['command' => $command, 'input' => $input, 'start_utc' => $utc, 'end_utc' => gmdate('c'), 'start_ns' => $start,
                'elapsed_s' => (hrtime(true) - $start) / 1e9, 'exit' => $process->getExitCode(),
                'stdout_sha256' => hash('sha256', $process->getOutput()), 'stderr_sha256' => hash('sha256', $process->getErrorOutput())];
            file_put_contents($this->output.'/'.$name.'.stdout', $process->getOutput());
            file_put_contents($this->output.'/'.$name.'.stderr', $process->getErrorOutput());
            $this->save($name.'.command.json', $record);
            $this->commands[$name] = $record;
        }
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('Command '.$name.' failed, exit '.var_export($process->getExitCode(), true));
        }

        return $process->getOutput();
    }

    private function state(string $name, string $mode, string $home): array
    {
        return json_decode($this->execute($name, [PHP_BINARY, '-d', 'memory_limit='.$this->summary['memory_limit'], __DIR__.'/graph-performance-state.php',
            $mode, $this->project, $home, $this->profile, $this->scenario['query'], $this->output.'/'.$name.'-diagnostics.jsonl', $this->summary['ready']['snapshot_id']]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sample(): void
    {
        if (hrtime(true) - $this->lastSample < 50000000) {
            return;
        }
        $this->lastSample = hrtime(true);
        foreach ($this->events() as $event) {
            if ($event['stage'] === 'process.start') {
                $this->knownPids[$event['pid']] = true;
            }
        }
        $ps = new Process(['ps', '-axo', 'pid=,ppid=,rss=,%cpu=,command=']);
        $ps->run();
        $rows = [];
        foreach (explode("\n", $ps->getOutput()) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+([\d.]+)\s+(.*)$/', $line, $match)) {
                $rows[(int) $match[1]] = ['pid' => (int) $match[1], 'ppid' => (int) $match[2], 'rss_kib' => (int) $match[3], 'cpu_percent' => (float) $match[4], 'command' => $match[5]];
            }
        }
        $own = [];
        foreach ($rows as $pid => $row) {
            if (isset($this->knownPids[$pid]) && (str_contains($row['command'], __DIR__.'/graph-performance-entrypoint.php') || str_contains($row['command'], __DIR__.'/graph-performance-state.php')) && str_contains($row['command'], $this->home)) {
                $own[$pid] = $row;
            }
        }
        do {
            $count = count($own);
            foreach ($rows as $pid => $row) {
                if (isset($own[$row['ppid']])) {
                    $own[$pid] = $row;
                }
            }
        } while (count($own) !== $count);
        $this->samples[] = ['monotonic_ns' => $this->lastSample, 'utc' => gmdate('c'), 'processes' => array_values($own), 'sum_rss_kib' => array_sum(array_column($own, 'rss_kib'))];
    }

    private function events(): array
    {
        $events = [];
        foreach (is_file($this->home.'/performance-events.jsonl') ? file($this->home.'/performance-events.jsonl', FILE_IGNORE_NEW_LINES) : [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $events[] = $row;
            }
        }

        return $events;
    }

    private function restart(RepositoryContext $context): void
    {
        $records = glob($context->storage.'/runtime/*/coordinator.json') ?: [];
        if ($records === []) {
            throw new RuntimeException('Warm requires a previous measured generation, never a hidden build');
        }
        foreach ($records as $path) {
            $record = self::json($path);
            $socket = GraphProtocol::socket(dirname($path), $record['endpoint']);
            GraphProtocol::send($socket, [...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
            fclose($socket);
        }
        $until = microtime(true) + 10;
        do {
            $this->sample();
            $last = end($this->samples);
            if (($last['processes'] ?? []) === []) {
                $this->summary['warm_start'] = 'new_process_after_controlled_shutdown';

                return;
            }
            usleep(50000);
        } while (microtime(true) < $until);
        throw new RuntimeException('Own runtime did not terminate before restart');
    }

    private ?array $inputsAtMeasurement = null;

    private function mutate(): void
    {
        $ownedRoot = dirname(__DIR__, 2).'/build/graph-performance-implementation/W04/legacy-smoke/';
        if (! str_starts_with($this->project, $ownedRoot)) {
            throw new RuntimeException('Mutations are restricted to new isolated W04 legacy-smoke copies, never graph-baseline');
        }
        $changes = $this->scenario['changes'] ?? [];
        if ($changes === []) {
            throw new RuntimeException('A frozen explicit source change is required');
        }
        foreach ($changes as $change) {
            $path = $change['path'];
            if (! DiscoverySettings::safe($this->project, $path) || str_starts_with($path, 'vendor/')) {
                throw new RuntimeException('Unsafe source mutation');
            }
            $absolute = $this->project.'/'.$path;
            if ($this->case !== 'add' && (! is_file($absolute) || hash_file('sha256', $absolute) !== ($change['before_sha256'] ?? null))) {
                throw new RuntimeException('Mutation preimage does not match');
            }
            if ($this->case === 'delete') {
                unlink($absolute);
            } elseif ($this->case === 'move') {
                $to = $change['to'];
                if (! DiscoverySettings::safe($this->project, $to) || file_exists($this->project.'/'.$to) || ! is_dir(dirname($this->project.'/'.$to))) {
                    throw new RuntimeException('Unsafe or occupied move destination');
                }
                rename($absolute, $this->project.'/'.$to);
            } else {
                if ($this->case === 'add' && file_exists($absolute)) {
                    throw new RuntimeException('Add must not overwrite a source');
                }
                if (! is_string($change['contents'] ?? null)) {
                    throw new RuntimeException('Explicit replacement contents required');
                }
                file_put_contents($absolute, $change['contents']);
            }
        }
        $this->inputsAtMeasurement = $this->inputs(CatalogSettings::load(new Filesystem, $this->project));
        $this->save('inputs-mutated.json', $this->inputsAtMeasurement);
    }

    private function inputs(CatalogSettings $settings): array
    {
        return ['scope' => $settings->scope->directories, 'exclude' => $settings->exclude,
            'root_state' => SourceManifest::scopeState($this->project, $settings->scope->directories),
            'files' => SourceManifest::scan(new Filesystem, $this->project, $settings->scope->directories, $settings->exclude)];
    }

    private function inputDigest(array $inputs): string
    {
        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    private function sources(): array
    {
        $hashes = [];
        foreach (['src', 'tests/Smoke'] as $directory) {
            foreach ((new Filesystem)->allFiles(dirname(__DIR__, 2).'/'.$directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $hashes[substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($hashes);

        return $hashes;
    }

    private function sourceDigest(): string
    {
        return hash('sha256', json_encode($this->sources(), JSON_THROW_ON_ERROR));
    }

    public static function negativeControls(array $actual, array $oracle): bool
    {
        $controls = $actual['negative_full_record_controls'] ?? [];
        if (count($controls) !== 3) {
            return false;
        }
        foreach ($controls as $control) {
            if ($control['digest'] === ($oracle['full_record_digests'][$control['field']] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function save(string $name, array $data): void
    {
        file_put_contents($this->output.'/'.$name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private static function json(string $path): array
    {
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}

if (realpath($argv[0]) === __FILE__) {
    try {
        $options = getopt('', ['project:', 'tool-home:', 'output:', 'backend:', 'case:']);
        foreach (['project', 'tool-home', 'output', 'backend', 'case'] as $required) {
            if (! is_string($options[$required] ?? null)) {
                throw new RuntimeException('Required --'.$required);
            }
        }
        exit((new GraphPerformanceSmoke($options['project'], $options['tool-home'], $options['output'], $options['backend'], $options['case']))->run());
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(2);
    }
}
