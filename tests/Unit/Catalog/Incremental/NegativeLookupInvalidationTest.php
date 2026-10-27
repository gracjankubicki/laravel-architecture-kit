<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

final class NegativeLookupInvalidationTest extends IncrementalFixture
{
    public function test_missing_known_ambiguous_known_missing_type_and_method(): void
    {
        $caller = '<?php namespace App; class Caller { public function run(Target $t) { $t->act(); } }';
        $files = ['app/Caller.php' => $caller];
        $generation = $this->prepare($files);
        $states = [
            ['app/Target.php' => '<?php namespace App; class Target { public function act() {} }'],
            ['app/Duplicate.php' => '<?php namespace App; class Target { public function act() {} }'],
            ['app/Duplicate.php' => null],
            ['app/Target.php' => '<?php namespace App; class Target { public function other() {} }'],
            ['app/Target.php' => '<?php namespace App; class Target { public function act() {} }'],
            ['app/Target.php' => null],
        ];
        $this->assertOracle($generation, $files, ['App\\Target', 'App\\Target::act', 'App\\Caller::run']);
        $reads = $generation->operations['1:app/Caller.php:0']['reads'];
        $this->assertArrayHasKey('name:app\\target', $reads);
        $targets = [];
        foreach ($states as $change) {
            $upserts = $deleted = [];
            foreach ($change as $path => $contents) {
                if ($contents === null) {
                    $deleted[] = $path;
                    unset($files[$path]);
                } else {
                    $files[$path] = $upserts[$path] = $contents;
                }
            }
            $generation = $this->update($generation, $files, $upserts, $deleted);
            $this->assertOracle($generation, $files, ['App\\Target', 'App\\Target::act', 'App\\Caller::run']);
            $this->assertSame('resolve', $generation->measurements['trace'][0]['action']);
            $targets[] = array_column($generation->operations['1:app/Caller.php:0']['relations'], 'to');
        }
        $this->assertStringStartsWith('element:', $targets[0][0]);
        $this->assertStringStartsWith('dispatch:', $targets[1][0]);
        $this->assertSame($targets[0], $targets[2]);
        $this->assertStringStartsWith('dispatch:', $targets[3][0]);
        $this->assertSame($targets[0], $targets[4]);
        $this->assertStringStartsWith('dispatch:', $targets[5][0]);
    }

    public function test_namespace_shadow_add_delete_tracks_empty_earlier_function_candidate(): void
    {
        $files = ['app/Caller.php' => '<?php namespace App; function caller() { helper(); helper(); }',
            'app/Global.php' => '<?php function helper() {}'];
        $generation = $this->prepare($files);
        $fragment = $generation->operations['1:app/Caller.php:0'];
        $this->assertArrayHasKey('name:app\\helper', $fragment['reads']);
        $this->assertArrayHasKey('name:helper', $fragment['reads']);
        $globalTarget = $fragment['relations'][0]['to'];
        $this->assertOracle($generation, $files, ['helper', 'App\\caller']);
        $files['app/Shadow.php'] = '<?php namespace App; function helper() {}';
        $generation = $this->update($generation, $files, ['app/Shadow.php' => $files['app/Shadow.php']]);
        $this->assertOracle($generation, $files, ['App\\helper', 'App\\caller']);
        $this->assertNotSame($globalTarget, $generation->operations['1:app/Caller.php:0']['relations'][0]['to']);
        unset($files['app/Shadow.php']);
        $generation = $this->update($generation, $files, [], ['app/Shadow.php']);
        $this->assertOracle($generation, $files, ['helper', 'App\\caller']);
        $this->assertSame($globalTarget, $generation->operations['1:app/Caller.php:0']['relations'][0]['to']);
    }

    public function test_missing_known_ambiguous_known_missing_function(): void
    {
        $files = ['app/Caller.php' => '<?php namespace App; function caller() { missing(); }'];
        $generation = $this->prepare($files);
        foreach ([['app/One.php', '<?php namespace App; function missing() {}'], ['app/Two.php', '<?php namespace App; function missing() {}'], ['app/Two.php', null], ['app/One.php', null]] as [$path, $source]) {
            if ($source === null) {
                unset($files[$path]);
            } else {
                $files[$path] = $source;
            }
            $generation = $this->update($generation, $files, $source === null ? [] : [$path => $source], $source === null ? [$path] : []);
            $this->assertOracle($generation, $files, ['App\\missing', 'App\\caller']);
            $this->assertSame('resolve', $generation->measurements['trace'][0]['action']);
        }
    }
}
