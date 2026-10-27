<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Integration\Runtime;

use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeBinary;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeManifest;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchBoundary;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchClient;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch\RuntimeFixture;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch\WatchProtocolTest;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchBootstrapTest extends TestCase
{
    private string $root;

    /** @var list<WatchClient> */
    private array $clients = [];

    private int $eventCount = 0;

    /** @var resource */
    private $trace;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/kit-watch-native-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/repo/app', 0700, true);
        $logDirectory = getenv('ARCHITECTURE_KIT_WATCH_TEST_LOGS') ?: dirname(__DIR__, 3).'/runtime/evidence';
        if (! is_dir($logDirectory)) {
            mkdir($logDirectory, 0700, true);
        }
        $this->trace = fopen($logDirectory.'/native-'.basename($this->root).'.jsonl', 'w');
        $this->record(['test' => $this->name(), 'root' => $this->root, 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m'), 'php' => PHP_VERSION]);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $manifest = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && ! $entry->isLink()) {
                $manifest[] = ['path_base64' => base64_encode(substr($entry->getPathname(), strlen($this->root) + 1)), 'bytes' => $entry->getSize(), 'sha256' => hash_file('sha256', $entry->getPathname())];
            }
        }
        $this->record(['final_fixture_manifest_independent_read' => $manifest]);
        $this->record(['cleanup' => 'all owned client handles closed/reaped']);
        fclose($this->trace);
        (new Filesystem)->deleteDirectory($this->root);
    }

    /** @param array<string, mixed> $value */
    private function record(array $value): void
    {
        fwrite($this->trace, json_encode(['at' => microtime(true), ...$value], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
    }

    /** @param non-empty-list<string> $roots
     * @param  array<string, string>  $environment
     */
    private function client(array $roots, array $environment = []): WatchClient
    {
        $path = RuntimeFixture::native();
        $body = file_get_contents($path);
        self::assertIsString($body);
        $binary = new RuntimeBinary($path, dirname($path), new RuntimeManifest(RuntimeFixture::manifest($body)), RuntimePlatform::current());
        $client = new WatchClient($binary, new WatchBoundary($roots), $environment);
        $session = $client->start();
        $this->clients[] = $client;
        $this->record(['ready' => $session->ready, 'session_digest' => hash('sha256', $session->id), 'binary_sha256' => hash('sha256', $body)]);
        foreach ($client->bootstrapEvents() as $event) {
            $this->record(['bootstrap_sequence' => $event->sequence, 'kind' => $event->kind, 'reason' => $event->reason, 'paths_base64' => array_map(base64_encode(...), $event->paths)]);
        }
        self::assertTrue($session->ready);
        self::assertTrue($session->rescanRequired);

        return $client;
    }

    /** @return list<string> */
    private function collect(WatchClient $client, float $seconds = 1.1): array
    {
        $deadline = microtime(true) + $seconds;
        $paths = [];
        do {
            $event = $client->next(0.05);
            if ($event !== null) {
                $this->eventCount++;
                $this->record(['sequence' => $event->sequence, 'kind' => $event->kind, 'paths_base64' => array_map(base64_encode(...), $event->paths), 'health' => $event->health, 'reason' => $event->reason]);
                array_push($paths, ...$event->paths);
            }
        } while (microtime(true) < $deadline);

        return $paths;
    }

    public function test_watch_is_ready_before_scan_and_retains_changes_during_scan_and_restore_without_mago(): void
    {
        $app = $this->root.'/repo/app';
        file_put_contents($app.'/before.php', '<?php class Before {}');
        $client = $this->client([$app]);
        $engine = new PhpAnalysisEngine;
        $marker = $this->root.'/source-was-executed';
        file_put_contents($app.'/before.php', '<?php file_put_contents('.var_export($marker, true).', "booted"); class Before {}');
        $document = $engine->analyze(new SourceInput($app.'/before.php', file_get_contents($app.'/before.php'), '8.3'));
        self::assertSame('php', $document->engine->engine);
        self::assertContains('Before', array_column($document->facts, 'name'));
        self::assertFileDoesNotExist($marker);
        self::assertDirectoryDoesNotExist($this->root.'/engines');
        $this->record(['scan' => 'begin after watch-ready']);
        $before = hash_file('sha256', $app.'/before.php');
        file_put_contents($app.'/before.php', '<?php class After {}');
        file_put_contents($app.'/added.php', '<?php class Added {}');
        $this->record(['mutation' => 'during scan', 'sha256' => hash_file('sha256', $app.'/before.php')]);
        $paths = $this->collect($client);
        self::assertContains($app.'/before.php', $paths);
        self::assertContains($app.'/added.php', $paths);
        self::assertNotSame($before, hash_file('sha256', $app.'/before.php'));
        $client->close();
        $restored = $this->client([$app]);
        $this->record(['restore' => 'begin after new watch-ready']);
        file_put_contents($app.'/restored.php', '<?php class Restored {}');
        self::assertContains($app.'/restored.php', $this->collect($restored));
        self::assertNotSame($client->session->id, $restored->session->id);
    }

    public function test_native_edit_add_delete_move_atomic_replace_burst_and_boundary_isolation(): void
    {
        $app = $this->root.'/repo/app';
        file_put_contents($app.'/old.php', 'old');
        mkdir($this->root.'/other', 0700);
        $first = $this->client([$app]);
        $second = $this->client([$this->root.'/other']);
        file_put_contents($app.'/old.php', 'edited');
        self::assertContains($app.'/old.php', $this->collect($first));
        file_put_contents($app.'/tmp.php', 'replacement');
        rename($app.'/tmp.php', $app.'/old.php');
        self::assertContains($app.'/old.php', $this->collect($first));
        self::assertSame('replacement', file_get_contents($app.'/old.php'));
        rename($app.'/old.php', $app.'/moved.php');
        $paths = $this->collect($first);
        self::assertTrue(in_array($app.'/old.php', $paths, true) || in_array($app.'/moved.php', $paths, true));
        rename($app.'/moved.php', $this->root.'/other/escaped.php');
        self::assertContains($app.'/moved.php', $this->collect($first));
        self::assertContains($this->root.'/other/escaped.php', $this->collect($second));
        unlink($this->root.'/other/escaped.php');
        self::assertContains($this->root.'/other/escaped.php', $this->collect($second));
        $name = $app."/space ę\n.php";
        file_put_contents($name, 'bytes');
        self::assertContains($name, $this->collect($first));
        if (PHP_OS_FAMILY === 'Linux') {
            $bytesName = $app."/invalid-\xff.php";
            file_put_contents($bytesName, 'raw bytes');
            self::assertContains($bytesName, $this->collect($first));
        } else {
            $this->record(['raw_non_utf8_filename' => 'OS filesystem rejects illegal byte sequences; byte protocol covered separately']);
        }
        file_put_contents($this->root.'/other/isolated.php', 'other');
        self::assertContains($this->root.'/other/isolated.php', $this->collect($second));
        self::assertSame([], $this->collect($first, 0.15));
        for ($i = 0; $i < 12; $i++) {
            file_put_contents($app.'/wave-'.$i.'.php', (string) $i);
        }
        for ($i = 0; $i < 12; $i++) {
            if ($i % 2 === 0) {
                unlink($app.'/wave-'.$i.'.php');
            } else {
                rename($app.'/wave-'.$i.'.php', $app.'/wave-renamed-'.$i.'.php');
            }
        }
        $paths = $this->collect($first);
        self::assertNotEmpty($paths);
        file_put_contents($app.'/last.php', 'last');
        self::assertContains($app.'/last.php', $this->collect($first));
    }

    public function test_missing_multilevel_root_delete_and_recreate_are_observed(): void
    {
        $missing = $this->root.'/repo/missing/deep';
        $client = $this->client([$missing]);
        mkdir($missing, 0700, true);
        file_put_contents($missing.'/appeared.php', 'appeared');
        $observed = $this->eventCount;
        $this->collect($client);
        self::assertGreaterThan($observed, $this->eventCount, 'Path changes or explicit scope-change rescan must be observed.');
        file_put_contents($missing.'/after-registration.php', 'after');
        self::assertContains($missing.'/after-registration.php', $this->collect($client));
        (new Filesystem)->deleteDirectory($this->root.'/repo/missing');
        $observed = $this->eventCount;
        $this->collect($client);
        self::assertGreaterThan($observed, $this->eventCount, 'Path changes or explicit scope-change rescan must be observed.');
        mkdir($missing, 0700, true);
        $observed = $this->eventCount;
        $this->collect($client);
        self::assertGreaterThan($observed, $this->eventCount, 'Path changes or explicit scope-change rescan must be observed.');
        file_put_contents($missing.'/recreated.php', 'new');
        self::assertContains($missing.'/recreated.php', $this->collect($client));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function queueLimits(): iterable
    {
        yield 'retained paths' => ['1', '1048576', 'queue_paths_overflow'];
        yield 'retained bytes' => ['64', '512', 'queue_bytes_overflow'];
    }

    #[DataProvider('queueLimits')]
    public function test_real_production_queue_overflow_with_slow_consumer_is_explicit(string $events, string $bytes, string $expectedReason): void
    {
        $app = $this->root.'/repo/app';
        $client = $this->client([$app], ['ARCHITECTURE_KIT_WATCH_QUEUE_EVENTS' => $events, 'ARCHITECTURE_KIT_WATCH_QUEUE_BYTES' => $bytes]);
        // The reader really stops while OS notifications and stdout backpressure fill the actual helper queue.
        for ($i = 0; $i < 2000; $i++) {
            file_put_contents($app.'/'.str_repeat('long', 20).$i.'.php', 'burst');
        }
        $reasons = [];
        $deadline = microtime(true) + 3;
        do {
            $event = $client->next(0.05);
            if ($event !== null) {
                $reasons[] = $event->reason;
                $this->record(['sequence' => $event->sequence, 'kind' => $event->kind, 'reason' => $event->reason]);
            }
        } while (microtime(true) < $deadline);
        self::assertContains($expectedReason, $reasons);
        self::assertTrue($client->session->rescanRequired);
    }

    public function test_same_path_directory_replacement_rearms_watches_and_symlinks_do_not_follow_other_repo(): void
    {
        $app = $this->root.'/repo/app';
        $client = $this->client([$app]);
        rename($app, $this->root.'/repo/previous-app');
        mkdir($app, 0700);
        $this->collect($client);
        file_put_contents($app.'/new-inode.php', 'replacement root');
        self::assertContains($app.'/new-inode.php', $this->collect($client));
        mkdir($this->root.'/other', 0700);
        symlink($this->root.'/other', $app.'/escape');
        $this->collect($client);
        self::assertTrue($client->session->rescanRequired);
        file_put_contents($this->root.'/other/outside.php', 'never followed');
        $paths = $this->collect($client);
        self::assertNotContains($app.'/escape/outside.php', $paths);
        self::assertNotContains($this->root.'/other/outside.php', $paths);
        self::assertSame('unverified', $client->session->health);
    }

    public function test_explicitly_synthetic_os_loss_reaches_client_and_real_events_continue(): void
    {
        $app = $this->root.'/repo/app';
        $client = $this->client([$app], ['ARCHITECTURE_KIT_WATCH_TEST_MODE' => '1']);
        $client->syntheticLoss();
        $event = $client->next(2);
        self::assertNotNull($event);
        self::assertSame('need_rescan', $event->reason);
        self::assertTrue($event->synthetic);
        self::assertTrue($event->rescanRequired);
        $this->record(['synthetic_os_loss' => true, 'reason' => $event->reason, 'not_real_os_overflow' => true]);
        file_put_contents($app.'/after-loss.php', 'after loss');
        self::assertContains($app.'/after-loss.php', $this->collect($client));
        self::assertSame('unverified', $client->session->health);
    }

    public function test_restart_old_session_rejected_and_second_owned_instance_survives_close(): void
    {
        $app = $this->root.'/repo/app';
        $first = $this->client([$app]);
        $second = $this->client([$app]);
        $previous = $first->session->id;
        $process = (new \ReflectionProperty(WatchClient::class, 'process'))->getValue($first);
        self::assertIsResource($process);
        proc_terminate($process, 9); // Only the handle created by this test's own client.
        try {
            $first->next(2);
            self::fail('Helper death was not reported.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('helper_exited', $error->getMessage());
            self::assertTrue($first->session->closed);
            self::assertTrue($first->session->rescanRequired);
            $this->record(['owned_helper_crash' => true, 'reason' => $error->getMessage()]);
        }
        $first->close();
        file_put_contents($app.'/downtime.php', 'downtime');
        self::assertContains($app.'/downtime.php', $this->collect($second));
        $restarted = $this->client([$app]);
        self::assertNotSame($previous, $restarted->session->id);
        $event = WatchProtocolTest::event();
        $event['session'] = $previous;
        try {
            $restarted->session->accept(json_encode($event, JSON_THROW_ON_ERROR)."\n");
            self::fail('Stale session accepted.');
        } catch (\RuntimeException) {
            self::assertSame('unverified', $restarted->session->health);
        }
    }
}
