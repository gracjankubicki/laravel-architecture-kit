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
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;

final class NativeSearchParityTest extends NativeBasicFixture
{
    public function test_numeric_id_name_stable_ties_ascii_utf8_filters_and_invalid_input(): void
    {
        $elements = [];
        foreach (['123', '0', '-1', '00', '1.0', '10', '2', 'ÄBC', 'Foo\\Service'] as $id) {
            $elements[] = new CatalogElement($id, $id, 'method', 1, 1, 0, roles: ['service'], metadata: ['uri' => '/NUM/0', 'table' => 'invoice', 'route_name' => 'Same', 'scalar' => 1.0]);
        }
        $facts = new CatalogFacts('app/numbers.php', $elements);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($facts->path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        [$generation, $oracle] = $this->loadRecords([$record], new CatalogIndex([$facts]), $options);
        $search = new GraphSearch($oracle);
        foreach (['method', 'service', '0', '1', '00', '-', 'NUM', 'invoice', 'Same', 'Ä', 'ä', 'SERVICE', 'foo', '\\', '', "\t", 'missing'] as $query) {
            foreach ([[null, null], ['service', null], ['method', null], [null, 'app'], [null, 'app/numbers.php'], [null, 'apps'], [null, '../app'], [null, 'vendor'], ['CLASS', null]] as [$kind, $path]) {
                $this->assertSame($search->find($query, $kind, $path), $this->basic->query($generation, $this->identity, ['type' => 'search', 'query' => $query, 'kind' => $kind, 'path' => $path]), json_encode([$query, $kind, $path]));
            }
        }
    }

    public function test_shared_sources_roles_duplicates_and_empty_typed_values_are_preserved(): void
    {
        $options = new BasicFactsOptions(false, true, false);
        $facts = $records = [];
        foreach (['app/One.php', 'Modules/Billing/Two.php'] as $i => $path) {
            $facts[] = $fact = new CatalogFacts($path, [new CatalogElement('00', 'Shared', 'table', 1, 1, 0, roles: ['dto', 'dto'], metadata: ['empty' => [], 'null' => null, 'float' => 1.0, 'bool' => false])]);
            $records[] = CaptureRecord::create($i, 'php', BasicSource::plain(new SourceInput($path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $fact));
        }
        [$generation, $oracle] = $this->loadRecords($records, new CatalogIndex($facts), $options);
        foreach (['app', 'Modules', 'Modules/Billing', 'Module'] as $path) {
            $this->assertSame((new GraphSearch($oracle))->find('Shared', path: $path), $this->basic->query($generation, $this->identity, ['type' => 'search', 'query' => 'Shared', 'path' => $path]));
        }
        $target = $this->identity;
        $target['capture'] = TypedValue::digest([$records[0]['sha256'], 'one-owner']);
        $scope = [array_intersect_key($records[0], array_flip(['owner', 'sha256', 'kind', 'php_version']))];
        $next = $this->basic->patch($generation, $this->identity, $target, $scope, []);
        $this->identity = $target;
        $one = new CatalogIndex([$facts[0]]);
        $this->assertSame($one->exportState(), $this->basic->state($next, $target));
        $this->assertQueries($next, $one);
        $emptyIdentity = $target;
        $emptyIdentity['capture'] = TypedValue::digest([]);
        $empty = $this->basic->patch($next, $target, $emptyIdentity, [], []);
        $this->identity = $emptyIdentity;
        $this->assertSame((new CatalogIndex([]))->exportState(), $this->basic->state($empty, $emptyIdentity));
        $this->assertAccounting($empty);

    }

    public function test_numeric_ties_after_partial_element_upsert_match_fresh_php_query(): void
    {
        $options = new BasicFactsOptions(false, true, false);
        $path = 'app/Numeric.php';
        $make = static function (int $version) use ($options, $path): array {
            $facts = new CatalogFacts($path, [new CatalogElement('0', '0', 'method', 1, 1, 0, metadata: ['version' => $version]), new CatalogElement('00', '00', 'method', 1, 1, 0)]);
            $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($path, '<?php /*'.$version.'*/', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));

            return [$record, new CatalogIndex([$facts])];
        };
        [$before, $oracle] = $make(0);
        [$generation] = $this->loadRecords([$before], $oracle, $options);
        [$after, $reference] = $make(1);
        $target = $this->identity;
        $target['capture'] = TypedValue::digest([$after['sha256']]);
        $scope = [array_intersect_key($after, array_flip(['owner', 'sha256', 'kind', 'php_version']))];
        $next = $this->basic->patch($generation, $this->identity, $target, $scope, [$after]);
        $this->identity = $target;
        $this->assertSame($reference->exportState(), $this->basic->state($next, $target));
        $this->assertSame((new GraphSearch($reference))->find('method'), $this->basic->query($next, $target, ['type' => 'search', 'query' => 'method']));
        $directory = realpath(sys_get_temp_dir()).'/ak-r03-numeric-restore-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $pin = $this->client->request(['op' => 'pin', 'generation' => $next, 'identity' => $target])['lease'];
        $this->client->request(['op' => 'snapshot', 'generation' => $next, 'identity' => $target, 'lease' => $pin, 'directory' => $directory]);
        $this->client->request(['op' => 'release', 'lease' => $pin]);
        $this->assertSame($next, $this->basic->restore($directory, $target)['generation']);
        $this->assertSame($reference->exportState(), $this->basic->state($next, $target));
        $this->assertSame((new GraphSearch($reference))->find('method'), $this->basic->query($next, $target, ['type' => 'search', 'query' => 'method']));
        [$again, $finalReference] = $make(2);
        $last = $target;
        $last['capture'] = TypedValue::digest([$again['sha256']]);
        $final = $this->basic->patch($next, $target, $last, [array_intersect_key($again, array_flip(['owner', 'sha256', 'kind', 'php_version']))], [$again]);
        $this->identity = $last;
        $this->assertSame($finalReference->exportState(), $this->basic->state($final, $last));
        $this->assertSame((new GraphSearch($finalReference))->find('method'), $this->basic->query($final, $last, ['type' => 'search', 'query' => 'method']));
        $this->assertAccounting($final);

    }

    public function test_mixed_numeric_strings_keep_php_comparison_for_larger_result_sets(): void
    {
        $names = ['2', '10', '1a', '01', '0', '00', '1.0', '1b', '9', '99', '100', '100a', '9223372036854775807', '9223372036854775808', '9223372036854775809', '-9223372036854775809', '1e999', '2e999', '-1e999', 'INF', 'NaN', "\u{00a0}2", " 2\t", '.5', '+2', '1e-999'];
        $order = range(0, 79);
        usort($order, fn ($a, $b) => strcmp(hash('sha256', 'numeric-v1-'.$a), hash('sha256', 'numeric-v1-'.$b)));
        $elements = [];
        foreach ($order as $i) {
            $elements[] = new CatalogElement('id:'.$i, $names[$i % count($names)], 'method', 1, 1, 0);
        }
        $facts = new CatalogFacts('app/Mixed.php', $elements);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($facts->path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        [$generation, $oracle] = $this->loadRecords([$record], new CatalogIndex([$facts]), $options);
        $this->assertSame((new GraphSearch($oracle))->find('method'), $this->basic->query($generation, $this->identity, ['type' => 'search', 'query' => 'method']));
    }
}
