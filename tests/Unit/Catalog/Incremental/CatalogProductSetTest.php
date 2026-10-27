<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogProductSet;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\SemanticVersions;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Standalone\GraphAdmissionRefused;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CatalogProductSetTest extends IncrementalFixture
{
    public function test_segmented_set_restores_every_product_without_parsing_or_resolving(): void
    {
        $directory = realpath(sys_get_temp_dir()).'/kit-w04-set-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        try {
            $generation = $this->prepare(['app/A.php' => '<?php class A { function run() { $this->run(); } }']);
            $search = SearchState::fromGeneration($generation);
            $set = new CatalogProductSet(new GraphSegmentStore($files, $directory));
            $key = hash('sha256', 'set');
            $written = $set->write($key, $generation, $search, []);
            $this->assertSame('success', $written['status'], $written['reason'] ?? '');
            $restored = $set->read($key, $this->identity());
            $this->assertSame('hit', $restored['status'], $restored['reason'] ?? '');
            $this->assertSame($generation->id(), $restored['catalog']->id());
            $this->assertSame($generation->exportState(), $restored['catalog']->exportState());
            $this->assertSame($generation->operations, $restored['catalog']->operations);
            $this->assertSame($search->export(), $restored['search']->export());
            $this->assertSame(0, $restored['catalog']->measurements['executed_calls']);
            $a = $restored['catalog']->index();
            $a->attachSearchState($restored['search']);
            $this->assertSame((new GraphSearch($generation->index()))->find('run'), (new GraphSearch($a))->find('run'));
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function test_partial_write_keeps_the_complete_previous_set(): void
    {
        $directory = realpath(sys_get_temp_dir()).'/kit-w04-set-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        try {
            $a = $this->prepare(['app/A.php' => '<?php class A {}']);
            $key = hash('sha256', 'set');
            $set = new CatalogProductSet(new GraphSegmentStore($files, $directory));
            $this->assertSame('success', $set->write($key, $a, SearchState::fromGeneration($a), [])['status']);
            $b = (new CatalogUpdater)->prepare($this->collect(['app/B.php' => '<?php class B {}']), $this->identity(), 'session');
            foreach (['dependencySources', 'searchRecords'] as $interruptedField) {
                $writer = new CatalogProductSet(new GraphSegmentStore($files, $directory, barrier: static function (string $stage, ?string $field = null) use ($interruptedField): void {
                    if ($stage === 'segment' && $field === $interruptedField) {
                        throw new RuntimeException('injected_write_interruption:'.$field);
                    }
                }));
                $this->assertSame('error', $writer->write($key, $b, SearchState::fromGeneration($b), [])['status']);
                $this->assertSame($a->id(), $set->read($key, $this->identity())['catalog']->id());
            }
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function test_streaming_identity_matches_the_existing_ordered_json(): void
    {
        foreach ([[], ['a' => [1, 1.0, false, null, 'ü/x']], [3 => ['0' => 'x'], '00' => ['😀']]] as $value) {
            $this->assertSame(hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), SemanticVersions::digest($value));
        }
    }

    public function test_large_record_write_budget_skips_before_serializing_under_real_low_memory(): void
    {
        $directory = realpath(sys_get_temp_dir()).'/kit-large-record-'.bin2hex(random_bytes(8));
        $code = <<<'PHP'
require $argv[1];
$record = new class implements JsonSerializable {
    public bool $serialized = false;
    public function jsonSerialize(): mixed {
        $this->serialized = true;
        return ['value' => str_repeat('x', 8 * 1024 * 1024)];
    }
};
$store = new GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore(new Illuminate\Filesystem\Filesystem, $argv[2]);
$result = $store->write(hash('sha256', 'large-record'), ['elements' => [$record]], []);
echo json_encode(['result' => $result, 'serialized' => $record->serialized], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code,
            dirname(__DIR__, 4).'/vendor/autoload.php', $directory]);
        try {
            $this->assertSame(0, $process->run(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['status' => 'skip', 'reason' => 'budget'], $result['result']);
            $this->assertFalse($result['serialized']);
            $this->assertDirectoryDoesNotExist($directory);
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function test_restore_admission_callback_runs_before_any_product_field_is_hydrated(): void
    {
        $directory = realpath(sys_get_temp_dir()).'/kit-pre-hydration-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        try {
            $generation = $this->prepare(['app/A.php' => '<?php class A {}']);
            $key = hash('sha256', 'pre-hydration');
            $store = new GraphSegmentStore($files, $directory);
            $this->assertSame('success', (new CatalogProductSet($store))->write($key, $generation, SearchState::fromGeneration($generation), [])['status']);
            $hydrated = false;
            $reader = new CatalogProductSet($store, static function () use (&$hydrated): void {
                $hydrated = true;
            });
            $refusal = new GraphAdmissionRefused('fixture_refusal', []);
            try {
                $reader->read($key, $this->identity(), beforeDecode: static function (int $bytes) use ($refusal): void {
                    self::assertGreaterThan(0, $bytes);
                    throw $refusal;
                });
                $this->fail('Admission must stop decoding.');
            } catch (GraphAdmissionRefused $error) {
                $this->assertSame($refusal, $error);
                $this->assertFalse($hydrated);
            }
        } finally {
            $files->deleteDirectory($directory);
        }
    }
}
