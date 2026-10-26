<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\BasicRecordIdentities;
use GracjanKubicki\ArchitectureKit\Analysis\BasicRecordStrings;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BasicRecordIdentitiesTest extends TestCase
{
    public function test_shared_json_records_keep_all_values_with_lower_retention_and_bounded_dictionary(): void
    {
        $source = new SourceInput('app/Example.php', '<?php class Example {}', '8.3');
        $record = ['metadata' => ['receiver' => str_repeat('Receiver', 20), 'method' => 'send', 'offset' => 12],
            'parameters' => ['$identity' => 'literal-source-value'], 'exact' => false, 'nullable' => null, 'line' => 1];
        $json = json_encode(array_fill(0, 4096, $record), JSON_THROW_ON_ERROR);
        $start = memory_get_usage(false);
        $raw = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $unsharedBytes = memory_get_usage(false) - $start;
        unset($raw);
        $start = memory_get_usage(false);
        $strings = new BasicRecordStrings;
        $shared = (new BasicRecordIdentities($source, [], $strings))->materialize(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $sharedBytes = memory_get_usage(false) - $start;
        self::assertSame(array_fill(0, 4096, $record), $shared);
        self::assertLessThan($unsharedBytes * 0.8, $sharedBytes);
        unset($shared, $strings);

        $strings = new BasicRecordStrings;
        $start = memory_get_usage(false);
        for ($i = 0; $i < BasicRecordStrings::MAX_STRINGS * 2; $i++) {
            $value = $i.str_repeat('x', 512);
            self::assertSame($value, $strings->share($value));
        }
        $boundedBytes = memory_get_usage(false) - $start;
        self::assertLessThan(BasicRecordStrings::MAX_BYTES * 2, $boundedBytes);
        unset($strings);
        self::assertLessThan(16384, memory_get_usage(false) - $start);
    }

    public function test_native_tuple_order_and_duplicates_preserve_existing_php_identities(): void
    {
        $source = new SourceInput('app/Example.php', '<?php class Example {}', '8.3');
        $tuples = [['element', 'class', 'Example', 6], ['resource', 'view', 'example'], ['dispatch', 'Example', 'run', true],
            ['include', ['app/Other.php', 'Other.php']], ['element', 'class', 'Example', 6]];
        $expected = [CatalogElement::identity($source->path, 'class', 'Example', 6), CatalogElement::resourceIdentity('view', 'example'),
            'dispatch:'.hash('xxh128', serialize(['Example', 'run', true])), 'include:'.hash('xxh128', serialize(['app/Other.php', 'Other.php']))];
        $identities = new BasicRecordIdentities($source, $tuples);
        self::assertSame($expected, $identities->materialize(array_map(static fn ($i) => ['$identity' => $i], [0, 1, 2, 3])));
        self::assertSame($expected[0], $identities->materialize(['$identity' => 4]));
        foreach (array_reverse($tuples) as $tuple) {
            $local = new BasicRecordIdentities($source, [$tuple]);
            $index = array_search($tuple, $tuples, true);
            self::assertSame($identities->materialize(['$identity' => $index]), $local->materialize(['$identity' => 0]));
        }
        $reordered = new BasicRecordIdentities($source, array_reverse($tuples));
        self::assertSame($expected, $reordered->materialize(array_map(static fn ($i) => ['$identity' => $i], [4, 3, 2, 1])));
    }

    public function test_value_hash_materializes_nested_local_references_before_php_serialization(): void
    {
        $source = new SourceInput('app/Example.php', '<?php class Example {}', '8.3');
        $metadata = ['receiver' => 'Example', 'bound_callable' => ['creator' => ['$identity' => 1]], 'offset' => 6];
        $identities = new BasicRecordIdentities($source, [['value', $metadata], ['element', 'class', 'Example', 6]]);
        $metadata['bound_callable']['creator'] = CatalogElement::identity($source->path, 'class', 'Example', 6);
        self::assertSame('value:'.hash('xxh128', serialize($metadata)), $identities->materialize(['$identity' => 0]));
        self::assertSame(['parameters' => ['$identity' => 'literal-source-value']], $identities->materialize(['parameters' => ['$identity' => 'literal-source-value']]));
    }

    public function test_bad_missing_and_cyclic_references_are_rejected_before_a_consumer(): void
    {
        $source = new SourceInput('app/Example.php', '<?php class Example {}', '8.3');
        foreach ([
            [['unknown']], [['element', 'class', 'Example', 1000]], [['element', 'class', 'Example', -1]],
            [['dispatch', 'Example', 'run', 1]], [['value', ['creator' => ['$identity' => 1]]]],
            [['value', ['creator' => ['$identity' => 0]]]],
            [['value', ['creator' => ['$identity' => 1]]], ['value', ['creator' => ['$identity' => 0]]]],
        ] as $tuples) {
            try {
                new BasicRecordIdentities($source, $tuples);
                self::fail('Invalid native identity table was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $identities = new BasicRecordIdentities($source, [['element', 'class', 'Example', 6]]);
        foreach ([['$identity' => -1], ['$identity' => 1], ['$identity' => '0'], ['$identity' => 0, 'other' => true]] as $reference) {
            try {
                $identities->materialize($reference);
                self::fail('Invalid native identity reference was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
