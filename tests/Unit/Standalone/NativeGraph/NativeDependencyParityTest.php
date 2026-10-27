<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeBasic;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;

final class NativeDependencyParityTest extends NativeBasicFixture
{
    public function test_negative_lookups_recompute_unchanged_callers_and_reuse_independent_operations(): void
    {
        $files = ['app/Caller.php' => '<?php namespace App; function caller(Target $t) {$t->run(); helper();}',
            'app/Independent.php' => '<?php namespace Elsewhere; function work() {} function independent() {work();}',
            'app/Global.php' => '<?php function helper() {}'];
        [$generation] = $this->load($files);
        $stages = [
            ['app/Target.php' => '<?php namespace App; class Target {public function run(){}}'],
            ['app/Duplicate.php' => '<?php namespace App; class Target {public function run(){}}'],
            ['app/Duplicate.php' => null],
            ['app/Target.php' => '<?php namespace App; class Target {public function other(){}}'],
            ['app/Target.php' => null],
            ['app/Shadow.php' => '<?php namespace App; function helper(){}'],
            ['app/Shadow.php' => null],
        ];
        foreach ($stages as $change) {
            $changed = [];
            foreach ($change as $path => $bytes) {
                if ($bytes === null) {
                    unset($files[$path]);
                } else {
                    $files[$path] = $bytes;
                    $changed[] = $path;
                }
            }
            [$generation] = $this->patch($generation, $files, $changed);
            $trace = $this->basic->trace($generation, $this->identity);
            $independent = array_values(array_filter($trace, fn ($row) => $row['path'] === 'app/Independent.php'));
            $this->assertSame('reuse', $independent[0]['action']);
            $callers = array_values(array_filter($trace, fn ($row) => $row['path'] === 'app/Caller.php'));
            $this->assertContains('resolve', array_column($callers, 'action'));
        }
    }

    public function test_edit_move_delete_and_ready_snapshot_restore_preserve_old_leases(): void
    {
        $files = ['app/One.php' => '<?php class One {public function run(){}}', 'app/Caller.php' => '<?php function caller(One $one) {$one->run();}'];
        [$old, $oldOracle] = $this->load($files);
        $oldIdentity = $this->identity;
        $lease = $this->client->request(['op' => 'pin', 'generation' => $old, 'identity' => $oldIdentity])['lease'];
        $files['app/Moved.php'] = str_replace('run', 'act', $files['app/One.php']);
        unset($files['app/One.php']);
        [$generation, $oracle] = $this->patch($old, $files, ['app/Moved.php']);
        $oldPage = $this->client->request(['op' => 'read_product', 'generation' => $old, 'identity' => $oldIdentity, 'lease' => $lease, 'field' => 'elements', 'start' => 0, 'limit' => 32]);
        $this->assertSame($oldOracle->exportState()['elements'], TypedValue::decode($oldPage['records']));
        $this->client->request(['op' => 'release', 'lease' => $lease]);
        $directory = realpath(sys_get_temp_dir()).'/ak-r03-cache-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $pin = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        $this->client->request(['op' => 'snapshot', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $pin, 'directory' => $directory]);
        $this->client->close();
        AnalysisRuntime::activate(null);
        $this->client = new NativeGraphClient(getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME'), 268435456, 536870912);
        $this->basic = new NativeBasic($this->client);
        $restored = $this->basic->restore($directory, $this->identity);
        $this->assertSame($generation, $restored['generation']);
        $phases = $this->client->request(['op' => 'status'])['phase_measurements']['phases'];
        foreach (['compose', 'foundation', 'resolve', 'dependency', 'search', 'search_patch'] as $phase) {
            $this->assertArrayNotHasKey($phase, $phases, 'Restore rebuilt '.$phase);
        }
        $this->assertGreaterThan(0, $phases['restore']['wall_ns']);
        $this->assertNotNull($phases['restore']['cpu_user_us']);
        $this->assertGreaterThan(0, $phases['restore']['owned_capacity_after_last']);
        $this->assertSame($oracle->exportState(), $this->basic->state($generation, $this->identity));
        $this->assertAccounting($generation);
        $this->assertQueries($generation, $oracle);
    }

    public function test_function_absence_candidate_set_and_shadow_are_tracked_through_every_transition(): void
    {
        $files = ['app/Caller.php' => '<?php namespace App; function caller(){helper();}',
            'app/Independent.php' => '<?php namespace Other; function work(){} function caller(){work();}'];
        [$generation] = $this->load($files);
        foreach ([['app/Global.php' => '<?php function helper(){}'],
            ['app/Duplicate.php' => '<?php function helper(){}'],
            ['app/Duplicate.php' => null], ['app/Global.php' => null],
            ['app/Local.php' => '<?php namespace App; function helper(){}'],
            ['app/Local.php' => null]] as $changes) {
            $changed = [];
            foreach ($changes as $path => $bytes) {
                if ($bytes === null) {
                    unset($files[$path]);
                } else {
                    $files[$path] = $bytes;
                    $changed[] = $path;
                }
            }
            [$generation] = $this->patch($generation, $files, $changed);
            $trace = $this->basic->trace($generation, $this->identity);
            $callers = array_values(array_filter($trace, fn ($row) => $row['path'] === 'app/Caller.php'));
            $this->assertContains('resolve', array_column($callers, 'action'));
        }
    }

    public function test_signature_trait_visibility_property_return_and_classification_patches_match_final_oracle(): void
    {
        $files = ['app/Caller.php' => '<?php namespace App; function caller(Child $c){$c->alias();$c->value->run();$c->make()->run();}',
            'app/Independent.php' => '<?php namespace Other; function work(){} function caller(){work();}',
            'app/Types.php' => '<?php namespace App; class Target {public function run(){}} trait One {public function act(){}} class Base {} class Child extends Base {use One {act as alias;} public Target $value; public function make(): Target {return new Target;}}'];
        [$generation] = $this->load($files);
        // Property/returned descriptors retain a broad dependency for unknown declaration effects.
        $files['app/Independent.php'] .= ' class AddedDeclaration {}';
        [$generation] = $this->patch($generation, $files, ['app/Independent.php']);
        $trace = array_values(array_filter($this->basic->trace($generation, $this->identity), fn ($row) => $row['path'] === 'app/Caller.php'));
        $this->assertContains('dependency:declarations:*', array_column($trace, 'reason'));
        foreach ([
            'trait One {protected function act(){}} class Base {} class Child extends Base {use One {act as private alias;} public Target $value; public function make(): Target {return new Target;}}',
            'trait One {public function act(){}} class Base {public function alias(){}} class Child extends Base {use One {act as alias;} public Other $value; public function make(): Other {return new Other;}} class Other {public function run(){}}',
            'trait One {public function act(){}} class Base extends Child {} class Child extends Base {use One {act as alias;} public Target|Other $value; public function make(): Target|Other {return new Target;}} class Other {public function run(){}}',
            'trait One {public function act(){}} class Base {} class Child extends Base implements \Illuminate\Contracts\Queue\ShouldQueue {use One {act as alias;} public Target $value; public function make(): Target {return new Target;}}',
        ] as $declarations) {
            $files['app/Types.php'] = '<?php namespace App; class Target {public function run(){}} '.$declarations;
            [$generation] = $this->patch($generation, $files, ['app/Types.php']);
            $callers = array_values(array_filter($this->basic->trace($generation, $this->identity), fn ($row) => $row['path'] === 'app/Caller.php'));
            $this->assertContains('resolve', array_column($callers, 'action'));
        }
    }
}
