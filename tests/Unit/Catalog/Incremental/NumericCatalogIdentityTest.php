<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogDependencyState;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogGeneration;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\SemanticVersions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Typed CatalogFacts boundary: parser-generated IDs need not be numeric for the public input to allow them. */
final class NumericCatalogIdentityTest extends IncrementalFixture
{
    /** @return iterable<string, array{string}> */
    public static function textualIds(): iterable
    {
        foreach (['0', '123', '-1', '00', '1.0'] as $id) {
            yield 'id-'.$id => [$id];
        }
    }

    private function source(string $id, string $path, int $line): CatalogContribution
    {
        $child = 'child:'.$id;
        $facts = new CatalogFacts($path, [
            new CatalogElement($id, 'needle', 'queue', $line, $line, 0, metadata: ['textual_id' => $id]),
            new CatalogElement($child, 'child', 'queue', $line, $line, 0, $id),
        ], [new CatalogRelation($id, $child, 'contains', $line, $line), new CatalogRelation($child, $id, 'extends', $line, $line)],
            [new CatalogDiagnostic('numeric_fixture', 'Numeric textual ID fixture.', $line, $id)]);

        return new CatalogContribution($facts, hash('sha256', $this->bytes($id, $line)));
    }

    private function bytes(string $id, int $line): string
    {
        return '<?php // typed-input fixture '.$id.' at '.$line;
    }

    /** Exact eight-field oracle and deterministic dependency roundtrip, deliberately no GraphSearch/GraphQuery.
     * @param  list<CatalogContribution>  $sources
     */
    private function oracle(CatalogGeneration $generation, array $sources, string $phase): CatalogGeneration
    {
        usort($sources, fn ($left, $right) => strcmp($left->facts->path, $right->facts->path));
        $expected = (new CatalogIndex(array_map(fn ($source) => $source->facts, $sources)))->exportState();
        $this->assertSame(CatalogState::FIELDS, array_keys($generation->exportState()));
        $this->assertSame($expected, $generation->exportState());
        $this->assertTrue($generation->index()->checksComplete());
        $codec = new CatalogDependencyState;
        $bytes = $codec->encode($generation);
        $restored = $codec->decode($bytes, $this->identity());
        $this->assertSame($expected, $restored->exportState());
        $this->assertSame($generation->id(), $restored->id());
        $this->assertSame($bytes, $codec->encode($restored));
        $this->assertSame(0, $restored->measurements['executed_calls']);
        $this->assertSame($generation->operations, $restored->operations);
        $directory = getenv('W02_EVIDENCE_DIR');
        if ($directory !== false && $directory !== '') {
            $directory .= '/'.$this->nameWithDataSet();
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$phase.'.json', json_encode(['expected' => $expected, 'actual' => $generation->exportState(),
                'restored' => $restored->exportState(), 'generation' => $generation->id(), 'trace' => $generation->measurements['trace'] ?? [],
                'operations' => $generation->operations], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            file_put_contents($directory.'/'.$phase.'-dependency.json', $bytes);
        }

        return $restored;
    }

    #[DataProvider('textualIds')]
    public function test_prepare_add_update_shared_owner_delete_and_restore_keep_exact_string_ids(string $id): void
    {
        $updater = new CatalogUpdater;
        $first = $this->source($id, 'app/Owner.php', 1);
        $direct = $updater->prepare([$first], $this->identity(), 'numeric');
        $this->oracle($direct, [$first], 'prepare');
        $old = $direct->exportState();
        $this->assertSame($id, $old['elements'][$id]['id']);
        $this->assertSame($id, $old['elements']['child:'.$id]['parent']);
        $this->assertSame([$id], $old['names']['needle']);
        $this->assertSame($id, $old['relations'][1]['to']);
        $generation = $updater->prepare([], $this->identity(), 'numeric');
        $sources = $files = [];
        foreach ([['add', $first, []], ['update', $this->source($id, 'app/Owner.php', 4), []],
            ['share', $this->source($id, 'app/Second.php', 7), []], ['delete-first', null, ['app/Owner.php']], ['delete-last', null, ['app/Second.php']]] as [$phase, $source, $deleted]) {
            foreach ($deleted as $path) {
                unset($sources[$path], $files[$path]);
            }
            if ($source !== null) {
                $sources[$source->facts->path] = $source;
                $files[$source->facts->path] = $this->bytes($id, $source->facts->elements[0]->line);
            }
            $sequence = CatalogSequence::next($generation->sequence);
            $reader = fn ($path) => $files[$path] ?? null;
            $patch = $updater->patch($generation, $source === null ? [] : [$source], $deleted, $this->identity(), 'numeric', $sequence, $sequence, $reader);
            $generation = $this->oracle($patch->apply($generation, 'numeric', $reader), array_values($sources), $phase);
            $this->assertSame($old, $direct->exportState());
            if ($sources !== []) {
                $this->assertSame($id, $generation->exportState()['elements'][$id]['id']);
            }
        }
        $this->assertSame([], $generation->exportState()['elements']);
        $this->assertSame([], $generation->exportState()['names']);
        $this->assertSame([], $generation->exportState()['in']);
        $this->assertSame([], $generation->exportState()['out']);
    }

    #[DataProvider('textualIds')]
    public function test_function_lookup_fragments_and_negative_lookup_roundtrip_numeric_targets(string $id): void
    {
        $caller = $this->collect(['app/Caller.php' => '<?php function caller() { needle(); }'])[0];
        $callee = fn (int $line) => new CatalogContribution(new CatalogFacts('app/Target.php', [
            new CatalogElement($id, 'needle', 'function', $line, $line, 0, metadata: ['fixture_version' => $line]),
        ]), hash('sha256', $this->bytes($id, $line)));
        $updater = new CatalogUpdater;
        $first = $callee(1);
        $generation = $updater->prepare([$caller, $first], $this->identity(), 'numeric');
        $generation = $this->oracle($generation, [$caller, $first], 'function-prepare');
        $this->assertSame([$id], $generation->operations['1:app/Caller.php:0']['lookups']['name:needle']);
        $this->assertSame($id, $generation->operations['1:app/Caller.php:0']['relations'][0]['to']);
        $second = $callee(2);
        $files = ['app/Caller.php' => '<?php function caller() { needle(); }', 'app/Target.php' => $this->bytes($id, 2)];
        $reader = fn ($path) => $files[$path] ?? null;
        $patch = $updater->patch($generation, [$second], [], $this->identity(), 'numeric', '1', '1', $reader);
        $generation = $this->oracle($patch->apply($generation, 'numeric', $reader), [$caller, $second], 'function-update');
        $this->assertSame(1, $patch->target->measurements['executed_calls']);
        $this->assertSame($id, $generation->operations['1:app/Caller.php:0']['relations'][0]['to']);
        unset($files['app/Target.php']);
        $reader = fn ($path) => $files[$path] ?? null;
        $patch = $updater->patch($generation, [], ['app/Target.php'], $this->identity(), 'numeric', '2', '2', $reader);
        $generation = $this->oracle($patch->apply($generation, 'numeric', $reader), [$caller], 'function-delete');
        $this->assertSame([], $generation->operations['1:app/Caller.php:0']['lookups']['name:needle']);
        $this->assertStringStartsWith('dispatch:', $generation->operations['1:app/Caller.php:0']['relations'][0]['to']);
    }

    public function test_single_zero_id_roundtrips_when_php_encodes_the_element_map_as_a_list(): void
    {
        $source = new CatalogContribution(new CatalogFacts('app/Zero.php', [new CatalogElement('0', 'needle', 'queue', 1, 1, 0)]), hash('sha256', 'zero'));
        $generation = (new CatalogUpdater)->prepare([$source], $this->identity(), 'numeric');
        $this->assertSame([0], array_keys($generation->exportState()['elements']));
        $this->assertTrue(array_is_list($generation->exportState()['elements']));
        $restored = $this->oracle($generation, [$source], 'single-zero');
        $this->assertSame('0', $restored->exportState()['elements'][0]['id']);
    }

    public function test_validator_rejects_mismatched_keys_non_string_ids_and_broken_references(): void
    {
        $state = (new CatalogIndex([$this->source('0', 'app/Owner.php', 1)->facts]))->exportState();
        $corruptions = [
            'record-type' => function (array $s) {
                $s['elements'][0]['id'] = 0;

                return $s;
            },
            'record-key' => function (array $s) {
                $s['elements'][0]['id'] = '00';

                return $s;
            },
            'record-foreign-key' => function (array $s) {
                $s['elements'][123] = $s['elements'][0];
                unset($s['elements'][0]);

                return $s;
            },
            'self-parent' => function (array $s) {
                $s['elements'][0]['parent'] = '0';

                return $s;
            },
            'integer-parent' => function (array $s) {
                $s['elements']['child:0']['parent'] = 0;

                return $s;
            },
            'missing-parent' => function (array $s) {
                $s['elements']['child:0']['parent'] = 'missing';

                return $s;
            },
            'integer-name-reference' => function (array $s) {
                $s['names']['needle'][0] = 0;

                return $s;
            },
            'missing-name-reference' => function (array $s) {
                $s['names']['needle'][0] = 'missing';

                return $s;
            },
            'integer-relation-endpoint' => function (array $s) {
                $s['relations'][1]['to'] = 0;

                return $s;
            },
            'missing-relation-target' => function (array $s) {
                $s['relations'][1]['to'] = 'missing';

                return $s;
            },
            'missing-relation-owner' => function (array $s) {
                $s['relations'][1]['from'] = 'missing';

                return $s;
            },
            'wrong-out-key' => function (array $s) {
                $s['out']['00'] = $s['out'][0];
                unset($s['out'][0]);

                return $s;
            },
            'wrong-in-key' => function (array $s) {
                $s['in']['00'] = $s['in'][0];
                unset($s['in'][0]);

                return $s;
            },
            'wrong-adjacency-position' => function (array $s) {
                $s['in'][0] = [0];

                return $s;
            },
            'missing-hierarchy-id' => function (array $s) {
                $s['hierarchyLimits']['missing'] = true;

                return $s;
            },
        ];
        CatalogState::validate($state);
        $codec = new CatalogDependencyState;
        $generation = (new CatalogUpdater)->prepare([$this->source('0', 'app/Owner.php', 1)], $this->identity(), 'numeric');
        $envelope = json_decode($codec->encode($generation), true, flags: JSON_THROW_ON_ERROR);
        foreach ($corruptions as $label => $mutate) {
            $invalid = $mutate($state);
            $this->assertInvalidRestore($codec, $envelope, $invalid, $label);
            try {
                CatalogState::validate($invalid);
            } catch (InvalidArgumentException $error) {
                $this->assertStringStartsWith('Invalid ready catalog state:', $error->getMessage(), $label);

                continue;
            }
            $this->fail('Validator accepted corruption: '.$label);
        }
        $state['hierarchyLimits'][0] = true;
        CatalogState::validate($state);
        $this->assertSame('0', $state['elements'][0]['id']);
    }

    /** Recompute the outer digest so validation, rather than checksum failure, rejects the bad identity/reference.
     * @param  array<string, mixed>  $envelope
     * @param  array<string, array<mixed>>  $invalid
     */
    private function assertInvalidRestore(CatalogDependencyState $codec, array $envelope, array $invalid, string $label): void
    {
        $envelope['payload']['state'] = $invalid;
        $envelope['digest'] = SemanticVersions::digest($envelope['payload']);
        try {
            $codec->decode(json_encode($envelope, JSON_THROW_ON_ERROR), $this->identity());
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('preparation_required:dependency_state_invalid:Invalid ready catalog state:', $error->getMessage(), $label);

            return;
        }
        $this->fail('Restore accepted corruption: '.$label);
    }
}
