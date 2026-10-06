<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\PublicApi\AutoloadSurface;
use GracjanKubicki\ArchitectureKit\PublicApi\ContractChanges;
use GracjanKubicki\ArchitectureKit\PublicApi\EffectiveApi;
use GracjanKubicki\ArchitectureKit\PublicApi\PhpContracts;
use GracjanKubicki\ArchitectureKit\PublicApi\SemverAdvice;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class PublicApiContractChangesTest extends TestCase
{
    public function test_removal_required_and_named_reference_contract_changes_are_breaking(): void
    {
        $before = '<?php class Api { public function run(int $id = 1): void {} }';
        foreach ([
            '<?php class Api {}',
            '<?php class Api { public function run(int $id): void {} }',
            '<?php class Api { public function run(int $newName = 1): void {} }',
            '<?php class Api { public function run(int &$id = 1): void {} }',
            '<?php class Api { protected function run(int $id = 1): void {} }',
        ] as $after) {
            $rows = $this->compare($before, $after);
            $this->assertSame('breaking', $rows[0]['verdict']);
            $this->assertSame('api::method:run', $rows[0]['element']);
            $this->assertNotEmpty($rows[0]['reasons']);
            $this->assertSame('api.php', $rows[0]['before']['source']['path']);
        }
    }

    public function test_optional_extension_and_new_abstract_requirement_have_distinct_verdicts(): void
    {
        $before = '<?php class Api { public function run(int $id): void {} }';
        $rows = $this->compare($before, '<?php class Api { public function run(int $id, string $mode = "x"): void {} }');
        $this->assertSame('compatible', $rows[0]['verdict']);
        $rows = $this->compare('<?php interface Api {}', '<?php interface Api { public function run(): void; }');
        $this->assertSame('breaking', $rows[0]['verdict']);
    }

    public function test_types_defaults_and_logic_require_inspection_and_line_shifts_are_ignored(): void
    {
        $before = '<?php class Api { public function run(int $id = 1): int { return 1; } }';
        foreach ([
            '<?php class Api { public function run(string $id = "x"): int { return 1; } }',
            '<?php class Api { public function run(int $id = 2): int { return 1; } }',
            '<?php class Api { public function run(int $id = 1): int { return 2; } }',
        ] as $after) {
            $this->assertSame('check', $this->compare($before, $after)[0]['verdict']);
        }
        $this->assertSame([], $this->compare($before, str_replace('<?php ', "<?php\n\n", $before)));
    }

    public function test_public_exposure_removal_is_reported_on_child_even_when_base_is_internal(): void
    {
        $before = '<?php /** @internal */ class Base { public function run(): void {} } class Api extends Base {}';
        $after = '<?php /** @internal */ class Base {} class Api extends Base {}';
        $rows = $this->compare($before, $after);
        $this->assertCount(1, $rows);
        $this->assertSame('api::method:run', $rows[0]['element']);
        $this->assertSame('Base', $rows[0]['before']['declared_in']);
        $this->assertSame('breaking', $rows[0]['verdict']);
    }

    public function test_partial_inventory_does_not_prove_removal_or_addition(): void
    {
        $before = $this->entries('<?php class Api { public function run(): void {} }');
        $after = $this->entries('<?php class Api {}');
        $rows = (new ContractChanges)->compare($before, $after, afterComplete: false);
        $this->assertSame('check', $rows[0]['verdict']);
        $rows = (new ContractChanges)->compare($after, $before, beforeComplete: false);
        $this->assertSame('check', $rows[0]['verdict']);
    }

    public function test_new_interface_and_abstract_class_are_compatible_extensions(): void
    {
        foreach (['interface NewContract { public function run(): void; }', 'abstract class NewContract { abstract public function run(): void; }'] as $declaration) {
            $rows = $this->compare('<?php', '<?php '.$declaration);
            $this->assertCount(2, $rows);
            $this->assertSame(['compatible', 'compatible'], array_column($rows, 'verdict'));
            $this->assertSame('minor', SemverAdvice::forChanges($rows, '1.2.3', null, true)['recommendation']);
        }
        $rows = $this->compare('<?php abstract class Api {}', '<?php abstract class Api { abstract public function run(): void; }');
        $this->assertSame('breaking', $rows[0]['verdict']);
    }

    /** @return list<array<string, mixed>> */
    private function compare(string $before, string $after): array
    {
        return (new ContractChanges)->compare($this->entries($before), $this->entries($after));
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(string $source): array
    {
        $files = ['composer.json' => '{"autoload":{"files":["api.php"]}}', 'api.php' => $source];
        $snapshot = new SourceSnapshot('git', 'abc', 'hash', $files, [], array_keys($files));

        return (new EffectiveApi(new PhpContracts($snapshot, new AutoloadSurface($snapshot))))->entries;
    }
}
