<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\ReceiverDescriptors;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReceiverDescriptorsTest extends TestCase
{
    public function test_legacy_and_linked_descriptors_select_the_same_receiver_without_expanding_history(): void
    {
        $previous = '@return:'.json_encode(['App\\Builder', 'step', true], JSON_THROW_ON_ERROR);
        $legacy = json_encode([$previous, 'step', false], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $previous);
        $linked = json_encode([ReceiverDescriptors::PREFIX.$hash, 'step', false], JSON_THROW_ON_ERROR);
        $this->assertSame(ReceiverDescriptors::decode($legacy), ReceiverDescriptors::decode($linked, [$hash => $previous]));
        $this->assertNull(ReceiverDescriptors::decode($linked));
        $property = '@property:'.$previous.'#next';
        $this->assertSame(ReceiverDescriptors::property($property), ReceiverDescriptors::property('@property:'.ReceiverDescriptors::PREFIX.$hash.'#next', [$hash => $previous]));
        $this->assertNull(ReceiverDescriptors::property('@property:'.ReceiverDescriptors::PREFIX.$hash.'#next'));
    }

    public function test_catalog_cache_rejects_a_missing_link_or_changed_descriptor(): void
    {
        $facts = $this->facts();
        $this->assertNotEmpty($facts->descriptorReceivers);
        foreach (['missing', 'changed'] as $variant) {
            $data = $facts->toArray();
            if ($variant === 'missing') {
                $data['descriptor_receivers'] = [];
            } else {
                $key = array_key_first($data['descriptor_receivers']);
                $data['descriptor_receivers'][$key] .= 'changed';
            }
            try {
                CatalogFacts::fromArray($facts->path, $data);
                $this->fail('Malformed descriptor table was accepted: '.$variant);
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('descriptor', $exception->getMessage());
            }
        }
    }

    public function test_cached_links_resolve_in_a_new_process(): void
    {
        $facts = $this->facts();
        $path = tempnam(sys_get_temp_dir(), 'kit-descriptors-');
        $this->assertIsString($path);
        try {
            file_put_contents($path, json_encode($facts->toArray(), JSON_THROW_ON_ERROR));
            $code = <<<'PHP'
require $argv[1];
$facts = \GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts::fromArray('app/Example.php', json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR));
$index = new \GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex([$facts], mode: \GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode::Deep);
$targets = [];
foreach ($index->relations as $row) {
    if ($row['kind'] === 'calls') {
        $targets[] = $index->elements[$row['to']]['name'] ?? '';
    }
}
echo json_encode($targets, JSON_THROW_ON_ERROR);
PHP;
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code, dirname(__DIR__, 3).'/vendor/autoload.php', $path]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertContains('App\\Builder::finish', json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('chainLengths')]
    public function test_long_chains_fit_a_fixed_memory_budget(int $length, bool $properties = false): void
    {
        $code = <<<'PHP'
require $argv[1];
$n = (int) $argv[2];
$source = '<?php namespace Probe; class Chain { public function handle(Builder $b) { return $b'.str_repeat($argv[3] === 'properties' ? '->step()->next' : '->step()', $n).'; } }';
$extractor = new \GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
$facts = $extractor->extract(new \GracjanKubicki\ArchitectureKit\Audit\FileContext('app/Probe.php', $source), true, \GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode::Deep);
echo json_encode(['calls' => count($facts->calls), 'bytes' => strlen(json_encode($facts->toArray(), JSON_THROW_ON_ERROR)) + strlen(json_encode($extractor->descriptorReceivers(), JSON_THROW_ON_ERROR)), 'notices' => $facts->notices, 'peak' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $code, dirname(__DIR__, 2).'/bootstrap.php', (string) $length, $properties ? 'properties' : 'methods']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($length, $result['calls']);
        if ($properties) {
            $this->assertSame([], $result['notices']);
        } else {
            $this->assertSame([], array_values(array_filter($result['notices'], fn ($notice) => $notice['reason'] !== 'Factory receiver context depth limit reached.')));
        }
        $this->assertLessThan($length * 5000, $result['bytes']);
        $this->assertLessThan(48 * 1024 * 1024, $result['peak']);
    }

    public static function chainLengths(): iterable
    {
        foreach ([28, 100, 200, 500] as $length) {
            yield (string) $length => [$length];
            yield $length.' properties' => [$length, true];
        }
    }

    public function test_structure_limit_retains_earlier_calls_and_the_limit_location(): void
    {
        $source = '<?php namespace Probe; class Chain { public function handle(Builder $b) { $b->start();'."\n".str_repeat('$b->step();'."\n", 7000).'} }';
        $facts = (new ImpactExtractor)->extract(new FileContext('app/Probe.php', $source), true, AnalysisMode::Deep);
        $this->assertSame('start', $facts->calls[0]['method']);
        $this->assertGreaterThan(1, count($facts->calls));
        $this->assertLessThan(7001, count($facts->calls));
        $limits = array_values(array_filter($facts->notices, fn ($row) => str_contains($row['reason'], 'AST limit')));
        $this->assertNotEmpty($limits);
        $this->assertGreaterThan(1, $limits[0]['line']);
    }

    private function facts(): CatalogFacts
    {
        $source = '<?php namespace App; class Builder { public self $next; public function step(): self { return $this; } public function finish() {} } class Caller { public function run(Builder $b) { $b->step()->next->step()->next->finish(); } }';

        return array_values((new ProjectGraphBuilder(catalog: true, mode: AnalysisMode::Deep))->build([new FileContext('app/Example.php', $source)])->catalogFacts)[0];
    }
}
