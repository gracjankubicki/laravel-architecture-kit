<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\PublicApi\AutoloadSurface;
use GracjanKubicki\ArchitectureKit\PublicApi\EffectiveApi;
use GracjanKubicki\ArchitectureKit\PublicApi\PhpContracts;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class PublicApiExposureTest extends TestCase
{
    public function test_internal_base_is_exposed_through_public_child_without_exporting_internal_api(): void
    {
        $api = $this->api(<<<'PHP'
<?php namespace Demo;
/** @internal */ class Base {
    public function create(): void {}
    protected function extend(): void {}
    private function secret(): void {}
    /** @internal */ public function implementation(): void {}
}
class InvoiceService extends Base {}
/** @internal */ class Input { public function hidden(): void {} }
class Consumer { public function accept(Input $input): Input { return $input; } }
PHP);
        $this->assertArrayNotHasKey('class:demo\\base', $api->entries);
        $this->assertArrayHasKey('demo\\invoiceservice::method:create', $api->entries);
        $this->assertSame('Demo\\Base', $api->entries['demo\\invoiceservice::method:create']['declared_in']);
        $this->assertSame(['Demo\\InvoiceService', 'Demo\\Base'], $api->entries['demo\\invoiceservice::method:create']['via']);
        $this->assertArrayHasKey('demo\\invoiceservice::method:extend', $api->entries);
        $this->assertArrayNotHasKey('demo\\invoiceservice::method:secret', $api->entries);
        $this->assertArrayNotHasKey('demo\\invoiceservice::method:implementation', $api->entries);
        $this->assertArrayNotHasKey('demo\\input::method:hidden', $api->entries);
        $this->assertSame('Demo\\Input', $api->typeExposures[0]['type']);
        $this->assertSame('type_identity_only', $api->typeExposures[0]['scope']);
        $this->assertSame([], $api->notices);
    }

    public function test_traits_precedence_alias_visibility_final_and_self_types(): void
    {
        $api = $this->api(<<<'PHP'
<?php namespace Demo;
/** @internal */ trait A { public function run(self $input): static { return $this; } }
trait B { public function run(int $input): int { return $input; } }
class Consumer {
    use A, B { A::run insteadof B; B::run as protected fallback; A::run as final finalRun; }
}
class PrivateConsumer { use A { run as private; } }
PHP);
        $run = $api->entries['demo\\consumer::method:run'];
        $this->assertSame('demo\\consumer', $run['signature']['parameters'][0]['type']);
        $this->assertSame('@static:demo\\consumer', $run['signature']['return_type']);
        $this->assertSame('Demo\\A', $run['declared_in']);
        $this->assertSame('protected', $api->entries['demo\\consumer::method:fallback']['visibility']);
        $this->assertSame('int', $api->entries['demo\\consumer::method:fallback']['signature']['return_type']);
        $this->assertTrue($api->entries['demo\\consumer::method:finalrun']['signature']['final']);
        $this->assertArrayNotHasKey('demo\\privateconsumer::method:run', $api->entries);
        $this->assertSame([], $api->notices);
    }

    public function test_class_override_wins_and_interface_inheritance_is_public(): void
    {
        $api = $this->api(<<<'PHP'
<?php namespace Demo;
interface ParentContract { public function run(): void; }
interface ChildContract extends ParentContract {}
trait A { public function run(): int { return 1; } }
trait B { public function run(): int { return 2; } }
class Consumer { use A, B; public function run(): int { return 3; } }
PHP);
        $this->assertArrayHasKey('demo\\childcontract::method:run', $api->entries);
        $this->assertTrue($api->entries['demo\\childcontract::method:run']['signature']['abstract']);
        $this->assertSame('Demo\\Consumer', $api->entries['demo\\consumer::method:run']['declared_in']);
        $this->assertSame([], $api->notices);
    }

    public function test_cycles_external_parents_and_trait_collisions_are_not_silent(): void
    {
        $api = $this->api(<<<'PHP'
<?php namespace Demo;
class A extends B {}
class B extends A {}
class ExternalChild extends External {}
trait Left { public function run(): int { return 1; } }
trait Right { public function run(): int { return 2; } }
class Consumer { use Left, Right; }
PHP);
        $this->assertGreaterThanOrEqual(3, count($api->notices));
        $this->assertTrue($api->entries['demo\\consumer::method:run']['ambiguous']);
    }

    public function test_internal_constructor_does_not_hide_promoted_public_property(): void
    {
        $api = $this->api('<?php class Api { /** @internal */ public function __construct(public int $count) {} }');
        $this->assertArrayHasKey('api::property:count', $api->entries);
        $this->assertArrayNotHasKey('api::method:__construct', $api->entries);
    }

    public function test_inheritance_expansion_stops_before_multiplying_a_million_members(): void
    {
        $methods = '';
        for ($i = 0; $i < 1000; $i++) {
            $methods .= 'public function m'.$i.'(): void {}';
        }
        $source = '<?php class Base {'.$methods.'}';
        for ($i = 0; $i < 1000; $i++) {
            $source .= 'class Child'.$i.' extends Base {}';
        }
        $api = $this->api($source);
        $this->assertNotEmpty($api->entries);
        $this->assertLessThanOrEqual(10000, count($api->entries));
        $this->assertContains('API exposure budget reached; recognized facts are partial.', array_column($api->notices, 'message'));
        $this->assertArrayHasKey('base::method:m0', $api->entries);
    }

    private function api(string $source): EffectiveApi
    {
        $files = ['composer.json' => '{"autoload":{"files":["api.php"]}}', 'api.php' => $source];
        $snapshot = new SourceSnapshot('git', 'abc', 'hash', $files, [], array_keys($files));

        return new EffectiveApi(new PhpContracts($snapshot, new AutoloadSurface($snapshot)));
    }
}
