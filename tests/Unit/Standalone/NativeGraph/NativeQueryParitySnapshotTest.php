<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use RuntimeException;

final class NativeQueryParitySnapshotTest extends NativeBasicFixture
{
    public function test_rehashed_canonical_remapping_is_rejected_before_install_and_current_survives(): void
    {
        $path = 'app/Other.php';
        $outside = 'outside.php';
        $id = CatalogElement::identity($outside, 'file', $outside);
        $facts = new CatalogFacts($path, [new CatalogElement($id, $path, 'file', 1, 1, 0)], [new CatalogRelation($id, 'file:'.$outside, 'includes', 1, 1)]);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        [$generation, $oracle] = $this->loadRecords([$record], new CatalogIndex([$facts]), $options);
        $directory = realpath(sys_get_temp_dir()).'/ak-r03-corruption-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        $this->client->request(['op' => 'snapshot', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease, 'directory' => $directory]);
        $this->client->request(['op' => 'release', 'lease' => $lease]);
        $current = $this->basic->compose($generation, $this->identity);
        $before = $this->client->request(['op' => 'status']);
        $pointer = json_decode(file_get_contents($directory.'/CURRENT'), true, 512, JSON_THROW_ON_ERROR);
        $manifestPath = $directory.'/'.$pointer['generation'].'/manifest.json';
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $partPath = dirname($manifestPath).'/'.$manifest['canonical_files'][0]['file'];
        $originalPart = file_get_contents($partPath);
        $originalManifest = file_get_contents($manifestPath);
        $originalPointer = file_get_contents($directory.'/CURRENT');
        foreach (['wrong_id', 'missing_path', 'duplicate_path', 'mixed_generation'] as $corruption) {
            $part = json_decode($originalPart, true, 512, JSON_THROW_ON_ERROR);
            if ($corruption === 'wrong_id') {
                $part['paths'][0][1] = 'element:'.str_repeat('a', 32);
            } elseif ($corruption === 'missing_path') {
                $part['paths'][0][0] = 'other-path.php';
            } elseif ($corruption === 'duplicate_path') {
                $part['paths'][] = $part['paths'][0];
            } else {
                $part['generation'] = str_repeat('b', 64);
            }
            file_put_contents($partPath, TypedValue::json($part));
            $mutated = $manifest;
            clearstatcache(true, $partPath);
            $mutated['canonical_files'][0]['bytes'] = filesize($partPath);
            $mutated['canonical_files'][0]['sha256'] = hash_file('sha256', $partPath);
            file_put_contents($manifestPath, TypedValue::json($mutated));
            $updatedPointer = $pointer;
            $updatedPointer['manifest_sha256'] = hash_file('sha256', $manifestPath);
            file_put_contents($directory.'/CURRENT', TypedValue::json($updatedPointer));
            try {
                $this->basic->restore($directory, $this->identity);
                $this->fail('Corrupt canonical map was published: '.$corruption);
            } catch (RuntimeException $error) {
                if ($corruption === 'wrong_id') {
                    $this->assertSame('native_basic_canonical_id_mismatch', $error->getMessage());
                } else {
                    $this->assertStringStartsWith('native_graph_', $error->getMessage());
                }
            }
            $after = $this->client->request(['op' => 'status']);
            $this->assertSame($current, $after['generation']);
            $this->assertSame($before['owned_capacity_bytes'], $after['owned_capacity_bytes']);
            $this->assertSame($oracle->exportState(), $this->basic->state($current, $this->identity));
            file_put_contents($partPath, $originalPart);
            file_put_contents($manifestPath, $originalManifest);
            file_put_contents($directory.'/CURRENT', $originalPointer);
        }
        try {
            $this->client->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $directory]);
            $this->fail('Basic restore bypassed helper validation.');
        } catch (RuntimeException $error) {
            $this->assertSame('native_graph_canonical_validation_required', $error->getMessage());
        }
        $staged = $this->client->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $directory, 'validate_canonical' => true]);
        $this->assertFalse($staged['ready_ram']);
        $this->assertSame($current, $this->client->request(['op' => 'status'])['generation']);
        try {
            $this->client->request(['op' => 'query_page', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $staged['validation_lease'], 'query' => ['type' => 'context', 'subject' => $id], 'start' => 0, 'limit' => 1]);
            $this->fail('Validation lease authorized public query.');
        } catch (RuntimeException $error) {
            $this->assertSame('native_graph_canonical_validation_required', $error->getMessage());
        }
        $this->assertSame($before['owned_capacity_bytes'], $this->client->request(['op' => 'status'])['owned_capacity_bytes']);
        $restored = $this->basic->restore($directory, $this->identity);
        $this->assertSame($generation, $restored['generation']);
        $this->assertAccounting($generation);
        $this->assertQueries($generation, $oracle);
    }
}
