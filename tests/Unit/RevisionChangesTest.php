<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Revision\RevisionChanges;
use GracjanKubicki\ArchitectureKit\Revision\RevisionConfiguration;
use GracjanKubicki\ArchitectureKit\Revision\RevisionFacts;
use GracjanKubicki\ArchitectureKit\Revision\RevisionRows;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use GracjanKubicki\ArchitectureKit\Revision\SymbolPairs;
use PHPUnit\Framework\TestCase;

final class RevisionChangesTest extends TestCase
{
    public function test_line_shifts_are_ignored_and_duplicate_statements_remain_distinct(): void
    {
        $before = $this->facts(['app/Run.php' => '<?php namespace App; class Run { public function run() { Other::work(); Other::work(); } }']);
        $shifted = $this->facts(['app/Run.php' => "<?php\n// shift\nnamespace App; class Run { public function run() { Other::work(); Other::work(); } }"]);
        $same = RevisionChanges::compare($before, $shifted);
        $this->assertSame([], $same['symbols']);
        $this->assertSame([], $same['structure']);
        $one = $this->facts(['app/Run.php' => '<?php namespace App; class Run { public function run() { Other::work(); } }']);
        $rows = RevisionChanges::compare($before, $one)['structure'];
        $this->assertCount(2, $rows);
        $this->assertSame('removed', $rows[0]['change']);
        $this->assertSame(['removed'], array_values(array_unique(array_column($rows, 'change'))));
        $this->assertContains('work', array_column(array_column($rows, 'before'), 'method'));
    }

    public function test_unique_class_move_and_rename_remap_unchanged_relations(): void
    {
        $before = $this->facts(['app/Old.php' => '<?php namespace App; class Old { public function run() { Other::work(); } }']);
        $after = $this->facts(['app/New.php' => '<?php namespace App; class NewName { public function run() { Other::work(); } }']);
        $rows = RevisionChanges::compare($before, $after);
        $this->assertSame('app\newname', $rows['pairs']['app\old']);
        $this->assertSame('app\newname::run', $rows['pairs']['app\old::run']);
        $this->assertSame([], $rows['structure']);
        $this->assertSame('(file) app/new.php', $rows['pairs']['(file) app/old.php']);
        $this->assertContains('moved_or_renamed', array_column($rows['symbols'], 'change'));
    }

    public function test_scope_loss_is_separate_from_source_deletion(): void
    {
        $code = ['extra/Run.php' => '<?php class Run {}'];
        $before = $this->facts([...$code, 'config/architectures.php' => '<?php return ["audit" => ["paths" => ["extra"]]];']);
        $after = $this->facts([...$code, 'config/architectures.php' => '<?php return ["audit" => ["paths" => []]];']);
        $rows = RevisionChanges::compare($before, $after);
        $this->assertSame(['scope_left'], array_values(array_unique(array_column($rows['symbols'], 'change'))));
        $this->assertSame('scope', $rows['configuration'][0]['dimension']);
    }

    public function test_coordinates_are_ignored_but_payload_fields_and_certainty_are_not(): void
    {
        $before = [['from' => 'A::run', 'line' => 1, 'offset' => 10, 'payload' => ['id' => 1, 'line' => 1], 'certainty' => 'possible']];
        $after = [['from' => 'A::run', 'line' => 9, 'offset' => 99, 'payload' => ['id' => 1, 'line' => 1], 'certainty' => 'possible']];
        $this->assertSame([], RevisionRows::compare($before, $after));
        $after[0]['payload']['id'] = 2;
        $this->assertCount(2, RevisionRows::compare($before, $after));
        $after[0]['payload']['id'] = 1;
        $after[0]['certainty'] = 'declared';
        $this->assertCount(2, RevisionRows::compare($before, $after));
    }

    public function test_empty_declarations_are_candidates_and_manual_pairs_keep_relation_changes(): void
    {
        $before = $this->facts(['app/Example.php' => '<?php class Old {}']);
        $after = $this->facts(['app/Example.php' => '<?php class NewName {}']);
        $candidate = RevisionChanges::compare($before, $after);
        $this->assertArrayNotHasKey('old', $candidate['pairs']);
        $this->assertNotEmpty($candidate['candidates']);
        $confirmed = RevisionChanges::compare($before, $after, ['Old' => 'NewName']);
        $this->assertSame('newname', $confirmed['pairs']['old']);
        $this->assertSame([], $confirmed['candidates']);
        $before = $this->facts(['app/Example.php' => '<?php class Old { public function run() { Other::work(); } }']);
        $after = $this->facts(['app/Example.php' => '<?php class NewName { public function run() { Different::work(); } }']);
        $changes = RevisionChanges::compare($before, $after, ['Old' => 'NewName']);
        $this->assertNotEmpty($changes['structure']);
    }

    public function test_candidate_budget_is_explicit_instead_of_allocating_all_pairs(): void
    {
        $before = $after = [];
        for ($i = 0; $i < 1100; $i++) {
            $entry = ['php_kind' => 'class', 'shape' => 'same', 'auto_pair' => false, 'path' => 'app/All.php'];
            $before['old'.$i] = [...$entry, 'name' => 'Old'.$i];
            $after['new'.$i] = [...$entry, 'name' => 'New'.$i];
        }
        $pairs = new SymbolPairs($before, $after);
        $this->assertTrue($pairs->limited);
        $this->assertCount(1000, $pairs->candidates);
    }

    public function test_row_limit_is_explicit_and_absence_is_not_reported_as_deletion(): void
    {
        $files = [];
        for ($i = 0; $i < 30; $i++) {
            $files['app/Run'.$i.'.php'] = '<?php namespace App; class Run'.$i.' { public function run() { '.str_repeat('Other::work();', 400).' } }';
        }
        $before = $this->facts(['app/Z.php' => '<?php namespace App; class Z { public function run() { Other::known(); } }']);
        $after = $this->facts([...$files, 'app/Z.php' => '<?php namespace App; class Z { public function run() { Other::known(); } }']);
        $changes = RevisionChanges::compare($before, $after);
        $this->assertContains('E_REVISION_ROW_LIMIT', array_column($changes['notices'], 'code'));
        $lost = array_filter($changes['structure'], static fn (array $row): bool => ($row['before']['method'] ?? null) === 'known');
        $this->assertNotEmpty($lost);
        $this->assertSame(['not_observed_after'], array_values(array_unique(array_column($lost, 'change'))));
    }

    public function test_layer_and_module_transition_changes_have_source_witnesses(): void
    {
        $files = ['app/Actions/Run.php' => '<?php namespace App; class Run { public function run(Other $other) {} }', 'app/Services/Other.php' => '<?php namespace App; class Other {}'];
        $before = $this->facts($files);
        $files['config/architectures.php'] = '<?php return ["audit" => ["classification" => ["roles" => [["path" => "app/Services", "role" => "domain"]], "modules" => [["path" => "app/Services", "name" => "Billing"]]]]];';
        $after = $this->facts($files);
        $changes = RevisionChanges::compare($before, $after);
        $this->assertNotEmpty($changes['transitions']);
        foreach ($changes['transitions'] as $row) {
            $witness = $row['before'] ?? $row['after'];
            $this->assertSame('app/Actions/Run.php', $witness['path']);
            $this->assertArrayHasKey('to_role', $witness);
            $this->assertArrayHasKey('to_module', $witness);
        }
        $this->assertSame([], $changes['structure']);
        $this->assertContains('classification', array_column($changes['configuration'], 'dimension'));
    }

    public function test_missing_test_rule_level_change_is_reported_separately(): void
    {
        $before = $this->facts(['config/architectures.php' => '<?php return ["audit" => ["missing_test" => "warn"]];']);
        $after = $this->facts(['config/architectures.php' => '<?php return ["audit" => ["missing_test" => "error"]];']);
        $changes = RevisionChanges::compare($before, $after);
        $this->assertSame('missing_test', $changes['configuration'][0]['dimension']);
        $this->assertSame('warn', $changes['configuration'][0]['before']);
        $this->assertSame('error', $changes['configuration'][0]['after']);
    }

    public function test_split_lines_preserve_structural_occurrences_and_transitions(): void
    {
        $files = ['app/Actions/Run.php' => '<?php namespace App; class Run { public function run() { Other::work(); Other::work(); } }', 'app/Models/Other.php' => '<?php namespace App; class Other { public static function work() {} }'];
        $before = $this->facts($files);
        $files['app/Actions/Run.php'] = str_replace('Other::work(); Other::work();', "Other::work();\nOther::work();", $files['app/Actions/Run.php']);
        $after = $this->facts($files);
        $changes = RevisionChanges::compare($before, $after);
        $this->assertSame([], $changes['structure']);
        $this->assertSame([], $changes['transitions']);
        $files['app/Actions/Run.php'] = str_replace("Other::work();\nOther::work();", 'Other::work();', $files['app/Actions/Run.php']);
        $removed = RevisionChanges::compare($after, $this->facts($files));
        $static = array_filter($removed['structure'], static fn (array $row): bool => ($row['before']['kind'] ?? null) === 'static');
        $this->assertCount(1, $static);
        $this->assertCount(1, $removed['transitions']);
    }

    /** @param array<string, string> $files */
    private function facts(array $files): RevisionFacts
    {
        $source = new SourceSnapshot('git', str_repeat('a', 40), 'hash', $files, [], array_keys($files));

        return RevisionFacts::collect($source, RevisionConfiguration::from($source));
    }
}
