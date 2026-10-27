<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogDependencyState;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogIdentity;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\SemanticVersions;
use InvalidArgumentException;
use RuntimeException;

final class CatalogPatchPublicationTest extends IncrementalFixture
{
    public function test_base_session_sequences_immutability_and_complete_record_delta(): void
    {
        $files = ['app/A.php' => '<?php class A { public function a() {} }',
            'app/Caller.php' => '<?php function caller(A $a) { $a->a(); }'];
        $base = $this->prepare($files);
        $before = $base->exportState();
        $beforeId = $base->id();
        $files['app/A.php'] = '<?php class A { public function b() {} }';
        $reader = fn ($path) => $files[$path] ?? null;
        $patch = (new CatalogUpdater)->patch($base, $this->collect(['app/A.php' => $files['app/A.php']]), [], $this->identity(), 'session', '1', '2', $reader);
        $this->assertSame($before, $base->exportState());
        $this->assertSame($beforeId, $base->id());
        $next = $patch->apply($base, 'session', $reader);
        $this->assertSame($next, $patch->apply($base, 'session', $reader));
        $this->assertOracle($next, $files, ['A', 'caller']);
        $rebuilt = $before;
        foreach ($patch->changes as $field => $delta) {
            foreach ($delta['removed'] as $key) {
                unset($rebuilt[$field][$key]);
            }
            foreach ($delta['upserted'] as $key => $row) {
                $rebuilt[$field][$key] = $row;
            }
            // Ordering of map keys is part of the canonical export, not of a sparse record delta.
            $this->assertEquals($next->exportState()[$field], $rebuilt[$field]);
        }
        foreach ([[$next, 'session'], [$base, 'foreign'], [(new CatalogUpdater)->prepare($this->collect($files), $this->identity(), 'other'), 'other']] as [$wrong, $session]) {
            try {
                $patch->apply($wrong, $session, $reader);
                $this->fail('Mismatched patch accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('patch_', $error->getMessage());
            }
        }
        foreach ([['2', '2', 'session'], ['1', '0', 'session'], ['1', '1', 'other']] as [$first, $last, $session]) {
            try {
                (new CatalogUpdater)->patch($base, [], [], $this->identity(), $session, $first, $last);
                $this->fail('Sequence gap/session accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('session_or_sequence', $error->getMessage());
            }
        }
        // Mutating an independently materialized legacy query view cannot mutate either generation.
        $view = $base->index();
        $view->elements = [];
        $view->relations = [];
        $this->assertSame($before, $base->exportState());
    }

    public function test_changed_bytes_and_deleted_file_reject_prepared_candidate_without_touching_base(): void
    {
        $files = ['app/A.php' => '<?php class A { public function a() {} }'];
        $base = $this->prepare($files);
        $before = $base->id();
        $source = '<?php class A { public function b() {} }';
        $files['app/A.php'] = $source;
        $reader = function ($path) use (&$files) {
            return $files[$path] ?? null;
        };
        $patch = (new CatalogUpdater)->patch($base, $this->collect(['app/A.php' => $source]), [], $this->identity(), 'session', '1', '1', $reader);
        foreach (['<?php class A { public function c() {} }', null] as $raced) {
            // Equal-length replacement deliberately defeats a size-only source guard.
            $this->assertSame(strlen($source), strlen($raced ?? $source));
            $files['app/A.php'] = $raced;
            try {
                $patch->apply($base, 'session', $reader);
                $this->fail('Changed byte identity accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('changed_inputs:app/A.php', $error->getMessage());
                $this->assertSame($before, $base->id());
            }
        }
        // Controlled analysis/publication boundary: sources were pinned before this callback.
        $files['app/A.php'] = $source;
        $checks = 0;
        $during = function ($path) use (&$files, &$checks) {
            $checks++;
            $files[$path] = '<?php class A { public function c() {} }';

            return $files[$path];
        };
        try {
            (new CatalogUpdater)->patch($base, $this->collect(['app/A.php' => $source]), [], $this->identity(), 'session', '1', '1', $during);
            $this->fail('Changed analysis bytes accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('changed_inputs:app/A.php', $error->getMessage());
            $this->assertSame(1, $checks);
        }
        $next = $this->update($base, $files, ['app/A.php' => $files['app/A.php']]);
        $this->assertOracle($next, $files, ['A']);
    }

    public function test_every_global_identity_change_requires_explicit_preparation(): void
    {
        $files = ['app/A.php' => '<?php class A { public function a() {} public function run() { $this->a(); } }'];
        $base = $this->prepare($files);
        foreach (CatalogIdentity::FIELDS as $field) {
            $values = $this->identity()->values;
            $values[$field] .= '-changed';
            $identity = new CatalogIdentity($values);
            try {
                (new CatalogUpdater)->patch($base, [], [], $identity, 'session', '1', '1');
                $this->fail('Foreign identity reused.');
            } catch (RuntimeException $error) {
                $this->assertSame('preparation_required:identity_changed', $error->getMessage());
            }
            $prepared = (new CatalogUpdater)->prepare($this->collect($files), $identity, 'new-session');
            $this->assertSame($identity->values, $prepared->identity->values);
            $this->assertSame(1, $prepared->measurements['executed_calls']);
            $this->assertOracle($prepared, $files, ['A']);
        }
    }

    public function test_dependency_state_roundtrip_retains_negative_lookups_without_composition(): void
    {
        $files = ['app/Caller.php' => '<?php function caller(Missing $m) { $m->act(); }'];
        $base = $this->prepare($files);
        $codec = new CatalogDependencyState;
        $bytes = $codec->encode($base);
        $restored = $codec->decode($bytes, $this->identity());
        $this->assertSame($base->id(), $restored->id());
        $this->assertSame($bytes, $codec->encode($restored));
        $this->assertSame(0, $restored->measurements['executed_calls']);
        $this->assertSame($base->operations, $restored->operations);
        $files['app/Missing.php'] = '<?php class Missing { public function act() {} }';
        $next = $this->update($restored, $files, ['app/Missing.php' => $files['app/Missing.php']]);
        $this->assertOracle($next, $files, ['Missing', 'caller']);
        $this->assertSame(1, $next->measurements['executed_calls']);
    }

    public function test_missing_old_corrupt_truncated_foreign_and_oversized_dependency_state_rejected(): void
    {
        $base = $this->prepare(['app/A.php' => '<?php class A {}']);
        $codec = new CatalogDependencyState;
        $bytes = $codec->encode($base);
        $variants = [null, substr($bytes, 0, -1), str_replace('"digest":"', '"digest":"0', $bytes)];
        foreach (['schema', 'resolver', 'state', 'sources', 'identity'] as $field) {
            $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            unset($data['payload'][$field]);
            $data['digest'] = SemanticVersions::digest($data['payload']);
            $variants[] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        foreach ($variants as $variant) {
            try {
                $codec->decode($variant, $this->identity());
                $this->fail('Invalid product restored.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringStartsWith('preparation_required:', $error->getMessage());
            }
        }
        $values = $this->identity()->values;
        $values['repository'] = '/foreign';
        try {
            $codec->decode($bytes, new CatalogIdentity($values));
            $this->fail('Foreign product restored.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('identity', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        (new CatalogDependencyState(maxBytes: 16))->encode($base);
    }

    public function test_real_same_stat_file_change_and_generation_bound_source_reader(): void
    {
        $directory = sys_get_temp_dir().'/w02-bytes-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory.'/A.php';
        $original = '<?php class A { public function a() {} }';
        $source = '<?php class A { public function b() {} }';
        file_put_contents($path, $original);
        $reader = fn (string $relative): ?string => $relative === 'app/A.php' && is_file($path) ? file_get_contents($path) : null;
        try {
            $base = (new CatalogUpdater)->prepare($this->collect(['app/A.php' => $reader('app/A.php')]), $this->identity(), 'session', contents: $reader);
            $view = $base->index($reader);
            $this->assertSame($original, ($view->sourceContents)('app/A.php'));
            file_put_contents($path, $source);
            $mtime = filemtime($path);
            $patch = (new CatalogUpdater)->patch($base, $this->collect(['app/A.php' => $reader('app/A.php')]), [], $this->identity(), 'session', '1', '1', $reader);
            file_put_contents($path, $original);
            touch($path, $mtime);
            clearstatcache(true, $path);
            $this->assertSame(strlen($source), filesize($path));
            $this->assertSame($mtime, filemtime($path));
            try {
                $patch->apply($base, 'session', $reader);
                $this->fail('Same-stat changed source accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('changed_inputs:app/A.php', $error->getMessage());
            }
            file_put_contents($path, $source);
            try {
                ($view->sourceContents)('app/A.php');
                $this->fail('New bytes returned through old source reader.');
            } catch (RuntimeException $error) {
                $this->assertSame('changed_inputs:app/A.php', $error->getMessage());
            }
            unlink($path);
            $this->expectExceptionMessage('changed_inputs:app/A.php');
            $patch->apply($base, 'session', $reader);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
