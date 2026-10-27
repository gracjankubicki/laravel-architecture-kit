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
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativePages;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeReplay;

final class NativePublicPageBudgetTest extends NativeBasicFixture
{
    public function test_one_oversized_public_record_is_explicit_without_an_empty_continuation_loop(): void
    {
        $facts = new CatalogFacts('app/Large.php', [new CatalogElement('large-symbol', 'Oversized'.str_repeat('X', 70000), 'class', 1, 1, 0)], []);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($facts->path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        [$generation] = $this->loadRecords([$record], new CatalogIndex([$facts]), $options);
        $page = (new NativePages($this->client))->page($generation, $this->identity, ['type' => 'search', 'query' => 'Oversized'], ['query' => 'Oversized', 'limit' => 1], ['app']);
        self::assertFalse($page['ok']);
        self::assertSame('unavailable', $page['status']);
        self::assertNull($page['next']);
        self::assertSame([], $page['result']['candidates']);
        self::assertContains('oversized_record', array_column($page['diagnostics']['details'], 'code'));
        self::assertLessThanOrEqual(65536, strlen(json_encode($page, JSON_THROW_ON_ERROR)));
        $narrow = (new NativePages($this->client))->page($generation, $this->identity, ['type' => 'search', 'query' => 'missing'], ['query' => 'missing', 'limit' => 1], ['app']);
        self::assertTrue($narrow['ok']);
        self::assertSame([], $narrow['result']['candidates']);
    }

    public function test_large_scalar_json_punctuation_and_escaped_quotes_do_not_become_container_reservations(): void
    {
        $options = new BasicFactsOptions;
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput('empty.php', '', '8.3')), $options,
            new FileGraphEntry([], [], syntaxError: str_repeat('[]{},:\\"', 250000)));
        $scope = [array_intersect_key($record, array_flip(['owner', 'sha256', 'kind', 'php_version']))];
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $record]);
        $generation = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        $rows = iterator_to_array((new NativeReplay($this->client))->records($generation, $this->identity));
        self::assertSame([$record], $rows);
        self::assertTrue($this->client->healthy());
    }
}
