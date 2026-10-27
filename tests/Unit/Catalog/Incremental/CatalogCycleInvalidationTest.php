<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

final class CatalogCycleInvalidationTest extends IncrementalFixture
{
    public function test_call_and_hierarchy_cycles_terminate_without_recomputing_independent_group(): void
    {
        $files = ['app/A.php' => '<?php namespace App; class A extends B { public function a(C $c) { $c->c($this); } }',
            'app/B.php' => '<?php namespace App; class B extends C { public function b(A $a) { $a->a(new C); } }',
            'app/C.php' => '<?php namespace App; class C extends A { public function c(B $b) { $b->b(new A); } }',
            'app/D.php' => '<?php namespace App; class D { public function d() {} public function run() { $this->d(); } }'];
        $generation = $this->prepare($files);
        $this->assertOracle($generation, $files, ['App\\A', 'App\\B::b', 'App\\C::c']);
        foreach (['<?php namespace App; class A extends B { public function a(C $c) { $c->c($this); } public function added() {} }',
            '<?php namespace App; class A { public function a(C $c) { $c->c($this); } public function added() {} }'] as $source) {
            $files['app/A.php'] = $source;
            $generation = $this->update($generation, $files, ['app/A.php' => $source]);
            $this->assertOracle($generation, $files, ['App\\A', 'App\\B::b', 'App\\C::c']);
            $trace = $generation->measurements['trace'];
            $this->assertSame(['reuse'], array_column(array_values(array_filter($trace, fn ($row) => $row['path'] === 'app/D.php')), 'action'));
            foreach ($trace as $operation) {
                if ($operation['path'] !== 'app/D.php') {
                    $this->assertSame('resolve', $operation['action']);
                }
            }
        }
    }

    public function test_trait_cycle_and_ambiguous_cache_diagnostics_keep_full_multiplicity(): void
    {
        $files = ['app/Traits.php' => '<?php namespace App; trait One { use Two; public function act() {} } trait Two { use One; public function act() {} }',
            'app/User.php' => '<?php namespace App; class User { use One, Two; }',
            'app/Caller.php' => '<?php namespace App; function run(User $u) { $u->act(); $u->act(); }'];
        $generation = $this->prepare($files);
        $this->assertOracle($generation, $files, ['App\\User', 'App\\run']);
        $this->assertSame(1, count(array_filter($generation->exportState()['diagnostics'], fn ($row) => $row['code'] === 'trait_method_ambiguous')));
        // Replay both operations with shared member-cache writes: no duplicated first-use diagnostic.
        $generation = $this->update($generation, $files, []);
        $this->assertOracle($generation, $files, ['App\\User', 'App\\run']);
        $this->assertSame(2, $generation->measurements['reused_calls']);
        $files['app/Traits.php'] = '<?php namespace App; trait One { use Two; public function act() {} } trait Two {}';
        $generation = $this->update($generation, $files, ['app/Traits.php' => $files['app/Traits.php']]);
        $this->assertOracle($generation, $files, ['App\\User', 'App\\run']);
        $this->assertNotContains('trait_method_ambiguous', array_column($generation->exportState()['diagnostics'], 'code'));
    }
}
