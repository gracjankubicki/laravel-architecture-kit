<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class GraphSnapshotIntegrityTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    private function state(): array
    {
        $builder = new ProjectGraphBuilder(catalog: true);
        $builder->add(new FileContext('app/A.php', '<?php namespace App; class A extends External { function go(Missing $x) { $x->execute(); } }'));

        return (new CatalogIndex($builder->finish()->catalogFacts))->exportState();
    }

    private function store(?\Closure $barrier = null, ?int $limit = null): GraphSegmentStore
    {
        return new GraphSegmentStore(new Filesystem, (string) realpath($this->tempPath).'/segments', $limit, $barrier);
    }

    /** @return array{string, array<string, mixed>, string, array<string, mixed>} */
    private function manifest(): array
    {
        $pointerPath = $this->store()->directory.'/active-'.str_repeat('a', 64).'.json';
        $pointer = json_decode((string) file_get_contents($pointerPath), true, 32, JSON_THROW_ON_ERROR);
        $manifestPath = $this->store()->directory.'/'.$pointer['generation'].'/manifest.json';

        return [$pointerPath, $pointer, $manifestPath, json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR)];
    }

    /** @param \Closure(array<string, mixed>&): void $change */
    private function rewrite(\Closure $change, bool $rehash = true): void
    {
        [$pointerPath, $pointer, $manifestPath, $manifest] = $this->manifest();
        $change($manifest);
        if ($rehash) {
            foreach ($manifest['fields'] as &$field) {
                $field['canonical_digest'] = GraphSegmentStore::digest($field['segments']);
            }
            unset($field);
            $manifest['state_digest'] = GraphSegmentStore::digest($manifest['fields']);
        }
        $raw = GraphSegmentStore::json($manifest);
        file_put_contents($manifestPath, $raw);
        $pointer['manifest_digest'] = hash('sha256', $raw);
        file_put_contents($pointerPath, GraphSegmentStore::json($pointer));
    }

    public function test_all_fields_and_empty_fields_roundtrip_without_composition(): void
    {
        $state = $this->state();
        $store = $this->store();
        $this->assertSame('success', $store->write(str_repeat('a', 64), $state, ['repository_id' => 'fixture'])['status']);
        $restored = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
        $this->assertSame('hit', $restored['status']);
        $index = CatalogIndex::fromState($restored['state']);
        $this->assertSame($state, $index->exportState());
        $empty = array_fill_keys(CatalogState::FIELDS, []);
        $this->assertSame('success', $store->write(str_repeat('b', 64), $empty, [])['status']);
        $this->assertSame($empty, $store->read(str_repeat('b', 64), CatalogState::FIELDS)['state']);
    }

    /** @return iterable<string, array{string}> */
    public static function corruptions(): iterable
    {
        foreach (['missing-field', 'extra-field', 'encoding', 'missing-segment', 'duplicate-segment', 'ordinal', 'bytes', 'count', 'digest', 'state', 'descriptor-loss', 'traversal', 'oversize', 'generation', 'identity'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_manifest_is_rejected_with_a_reason(string $case): void
    {
        $store = $this->store();
        $this->assertSame('success', $store->write(str_repeat('a', 64), $this->state(), [])['status']);
        $this->rewrite(static function (array &$manifest) use ($case): void {
            switch ($case) {
                case 'missing-field': unset($manifest['fields']['names']);
                    break;
                case 'extra-field': $manifest['fields']['extra'] = $manifest['fields']['names'];
                    break;
                case 'encoding': $manifest['fields']['elements']['encoding'] = 'php-executable';
                    break;
                case 'missing-segment': $manifest['fields']['elements']['segments'] = [];
                    break;
                case 'duplicate-segment': $manifest['fields']['elements']['segments'][] = $manifest['fields']['elements']['segments'][0];
                    break;
                case 'ordinal': $manifest['fields']['elements']['segments'][0]['ordinal'] = 1;
                    break;
                case 'bytes': $manifest['fields']['elements']['segments'][0]['bytes']++;
                    break;
                case 'count': $manifest['fields']['elements']['records']++;
                    break;
                case 'digest': $manifest['fields']['elements']['segments'][0]['digest'] = str_repeat('0', 64);
                    break;
                case 'state': $manifest['state_digest'] = str_repeat('0', 64);
                    break;
                case 'descriptor-loss': unset($manifest['fields']['descriptorReceivers']);
                    break;
                case 'traversal': $manifest['fields']['elements']['segments'][0]['file'] = '../secret.json';
                    break;
                case 'oversize': $manifest['fields']['elements']['segments'][0]['bytes'] = GraphSegmentStore::SEGMENT_BYTES + 1;
                    break;
                case 'generation': $manifest['generation_id'] = str_repeat('b', 32);
                    break;
                case 'identity': $manifest['identity'] = str_repeat('b', 64);
                    break;
            }
        }, $case !== 'state');
        $result = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
        $this->assertSame('error', $result['status'], $case);
        $this->assertNotEmpty($result['reason']);
        $this->assertArrayNotHasKey('state', $result);
    }

    public function test_active_anchor_rejects_modified_manifest_with_valid_internal_digests(): void
    {
        $store = $this->store();
        $store->write(str_repeat('a', 64), $this->state(), []);
        [, , $path, $manifest] = $this->manifest();
        $manifest['metadata']['repository_id'] = 'another-repo';
        file_put_contents($path, GraphSegmentStore::json($manifest));
        $this->assertSame('manifest_digest', $store->read(str_repeat('a', 64), CatalogState::FIELDS)['reason']);
    }

    /** @return iterable<string, array{string}> */
    public static function semanticCorruptions(): iterable
    {
        foreach (['missing-name', 'extra-name', 'wrong-name', 'parent', 'missing-out', 'extra-out', 'wrong-out', 'missing-in', 'extra-in', 'wrong-in', 'hierarchy', 'descriptor', 'owner', 'target'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('semanticCorruptions')]
    public function test_semantic_references_fail_even_with_recomputed_hashes(string $case): void
    {
        $state = $this->state();
        $id = array_key_first($state['elements']);
        $out = array_key_first($state['out']);
        $in = array_key_first($state['in']);
        switch ($case) {
            case 'missing-name': array_pop($state['names']);
                break;
            case 'extra-name': $state['names']['extra'] = [$id];
                break;
            case 'wrong-name': $state['names'][array_key_first($state['names'])] = ['absent'];
                break;
            case 'parent': $state['elements'][$id]['parent'] = 'absent';
                break;
            case 'missing-out': array_pop($state['out'][$out]);
                break;
            case 'extra-out': $state['out'][$out][] = 0;
                break;
            case 'wrong-out': $state['out'][$out][0] = 99999;
                break;
            case 'missing-in': array_pop($state['in'][$in]);
                break;
            case 'extra-in': $state['in'][$in][] = 0;
                break;
            case 'wrong-in': $state['in'][$in][0] = 99999;
                break;
            case 'hierarchy': $state['hierarchyLimits']['absent'] = true;
                break;
            case 'descriptor': $state['descriptorReceivers']['absent'] = '@return:[]';
                break;
            case 'owner': $state['relations'][0]['from'] = 'absent';
                break;
            case 'target': $state['relations'][0]['to'] = 'absent';
                break;
        }
        $store = $this->store();
        $this->assertSame('success', $store->write(str_repeat('a', 64), $state, [])['status']);
        $result = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
        $this->assertSame('hit', $result['status']);
        $this->expectException(\InvalidArgumentException::class);
        CatalogIndex::fromState($result['state']);
    }

    public function test_failed_publication_preserves_previous_generation_and_budget_is_explicit(): void
    {
        $store = $this->store();
        $state = $this->state();
        $first = $store->write(str_repeat('a', 64), $state, []);
        $this->assertSame('success', $first['status']);
        $crashing = $this->store(static function (string $stage): void {
            if ($stage === 'before_publish') {
                throw new \RuntimeException('simulated_crash');
            }
        });
        $this->assertSame('error', $crashing->write(str_repeat('a', 64), array_fill_keys(CatalogState::FIELDS, []), [])['status']);
        $result = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
        $this->assertSame($first['generation'], $result['generation']);
        $this->assertSame($state, $result['state']);
        $this->assertSame('budget', $this->store(limit: 1)->write(str_repeat('a', 64), $state, [])['reason']);
        $this->assertSame('skip', $this->store(limit: 1)->read(str_repeat('a', 64), CatalogState::FIELDS)['status']);
        $this->assertSame('changed_inputs', $store->write(str_repeat('a', 64), $state, [], static fn (): bool => false)['reason']);
        $this->assertSame($first['generation'], $store->read(str_repeat('a', 64), CatalogState::FIELDS)['generation']);
    }

    public function test_linked_segment_and_executable_payload_are_never_read_or_run(): void
    {
        $store = $this->store();
        $store->write(str_repeat('a', 64), $this->state(), []);
        [, , $path, $manifest] = $this->manifest();
        $segment = dirname($path).'/'.$manifest['fields']['elements']['segments'][0]['file'];
        unlink($segment);
        symlink(__FILE__, $segment);
        $this->assertSame('unsafe_store', $store->read(str_repeat('a', 64), CatalogState::FIELDS)['reason']);
        unlink($segment);
        file_put_contents($segment, '<?php throw new Exception("executed");');
        $this->rewrite(static function (array &$manifest) use ($segment): void {
            $manifest['fields']['elements']['segments'][0]['bytes'] = filesize($segment);
            $manifest['fields']['elements']['segments'][0]['digest'] = hash_file('sha256', $segment);
        });
        $this->assertSame('error', $store->read(str_repeat('a', 64), CatalogState::FIELDS)['status']);
    }

    public function test_killed_own_writer_leaves_only_a_complete_old_or_new_generation(): void
    {
        $store = $this->store();
        $old = $this->state();
        $new = $old;
        $new['diagnostics'][] = ['code' => 'new_generation', 'message' => 'new generation marker'];
        $input = $this->tempPath.'/writer-input.json';
        file_put_contents($input, GraphSegmentStore::json($new));
        foreach (['segment', 'before_publish', 'after_publish'] as $stage) {
            $this->assertSame('success', $store->write(str_repeat('a', 64), $old, [])['status']);
            $writer = new Process([PHP_BINARY, '-d', 'memory_limit=1G',
                dirname(__DIR__).'/Fixtures/Graph/store-writer.php', $store->directory, $stage, $input]);
            $inputStream = new InputStream;
            $writer->setInput($inputStream);
            $writer->start();
            $this->assertTrue($writer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'BARRIER')), $writer->getErrorOutput());
            $writer->signal(9);
            $writer->wait();
            $inputStream->close();
            $this->assertSame(9, $writer->getTermSignal());
            $actual = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
            $this->assertSame('hit', $actual['status']);
            $this->assertSame($stage === 'after_publish' ? $new : $old, CatalogIndex::fromState($actual['state'])->exportState());
        }
    }

    public function test_invalid_decoded_size_and_decompression_bomb_are_rejected(): void
    {
        foreach (['too-small', 'over-limit', 'bomb'] as $case) {
            $store = $this->store();
            $store->write(str_repeat('a', 64), $this->state(), []);
            $this->rewrite(static function (array &$manifest) use ($case, $store): void {
                $segment = &$manifest['fields']['elements']['segments'][0];
                if ($case === 'too-small') {
                    $segment['decoded_bytes']--;
                } elseif ($case === 'over-limit') {
                    $segment['decoded_bytes'] = GraphSegmentStore::SEGMENT_BYTES + 1;
                } else {
                    $raw = gzdeflate(str_repeat('x', GraphSegmentStore::SEGMENT_BYTES + 1), 1);
                    $path = $store->directory.'/'.$manifest['generation_id'].'/'.$segment['file'];
                    file_put_contents($path, $raw);
                    $segment['bytes'] = strlen($raw);
                    $segment['decoded_bytes'] = GraphSegmentStore::SEGMENT_BYTES;
                    $segment['digest'] = hash('sha256', $raw);
                }
            });
            $result = $store->read(str_repeat('a', 64), CatalogState::FIELDS);
            $this->assertSame('error', $result['status'], $case);
            $this->assertArrayNotHasKey('state', $result);
        }
    }

    public function test_real_process_without_zlib_has_positive_json_roundtrip(): void
    {
        $directory = (string) realpath($this->tempPath).'/without-zlib';
        foreach (['write', 'read'] as $action) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G',
                '-d', 'disable_functions=gzdeflate,gzinflate', dirname(__DIR__).'/Fixtures/Graph/plain-store-worker.php', $directory, $action]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertFalse(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['zlib_available']);
        }
        $pointer = json_decode((string) file_get_contents($directory.'/active-'.str_repeat('a', 64).'.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) file_get_contents($directory.'/'.$pointer['generation'].'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(GraphSegmentStore::ENCODING, $manifest['fields']['diagnostics']['encoding']);
    }

    public function test_top_level_map_preserves_numeric_keys_order_and_rejects_cross_segment_duplicates(): void
    {
        $rows = [0 => 'zero', '01' => 'leading', '+1' => 'signed', '1.0' => 'decimal'];
        for ($i = 1; $i <= 8193; $i++) {
            $rows[$i] = 'value-'.$i;
        }
        $store = $this->store();
        $store->write(str_repeat('a', 64), ['rows' => $rows], []);
        $this->assertSame($rows, $store->read(str_repeat('a', 64), ['rows'])['state']['rows']);
        $this->rewrite(static function (array &$manifest) use ($store): void {
            $segment = &$manifest['fields']['rows']['segments'][1];
            $path = $store->directory.'/'.$manifest['generation_id'].'/'.$segment['file'];
            $records = json_decode(gzinflate((string) file_get_contents($path)), true, 32, JSON_THROW_ON_ERROR);
            $first = array_key_first($records);
            $value = $records[$first];
            unset($records[$first]);
            $records = [0 => $value] + $records;
            $raw = gzdeflate(json_encode((object) $records, JSON_THROW_ON_ERROR), 1);
            file_put_contents($path, $raw);
            $segment['bytes'] = strlen($raw);
            $segment['decoded_bytes'] = strlen(gzinflate($raw));
            $segment['digest'] = hash('sha256', $raw);
        });
        $this->assertSame('duplicate_key:rows', $store->read(str_repeat('a', 64), ['rows'])['reason']);
    }

    public function test_serialized_enum_and_reference_payloads_never_trigger_autoload(): void
    {
        $calls = 0;
        $autoload = static function (string $class) use (&$calls): void {
            if ($class === 'CacheProbeEnum') {
                $calls++;
            }
        };
        spl_autoload_register($autoload);
        try {
            foreach (['E:22:"CacheProbeEnum:Example";', 'O:8:"stdClass":0:{}', 'a:1:{i:0;R:1;}'] as $payload) {
                $store = $this->store();
                $store->write(str_repeat('a', 64), $this->state(), []);
                $this->rewrite(static function (array &$manifest) use ($payload, $store): void {
                    $segment = &$manifest['fields']['elements']['segments'][0];
                    $raw = gzdeflate($payload, 1);
                    file_put_contents($store->directory.'/'.$manifest['generation_id'].'/'.$segment['file'], $raw);
                    $segment['bytes'] = strlen($raw);
                    $segment['decoded_bytes'] = strlen($payload);
                    $segment['digest'] = hash('sha256', $raw);
                });
                $this->assertSame('error', $store->read(str_repeat('a', 64), CatalogState::FIELDS)['status']);
            }
        } finally {
            spl_autoload_unregister($autoload);
        }
        $this->assertSame(0, $calls);
    }
}
