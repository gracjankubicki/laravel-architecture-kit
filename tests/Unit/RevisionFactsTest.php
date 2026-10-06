<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Revision\RevisionChanges;
use GracjanKubicki\ArchitectureKit\Revision\RevisionConfiguration;
use GracjanKubicki\ArchitectureKit\Revision\RevisionFacts;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class RevisionFactsTest extends TestCase
{
    public function test_compatible_file_graph_facts_are_reused_and_config_changes_invalidate_them(): void
    {
        $old = $this->source(['app/Run.php' => '<?php namespace App; class Run { public function run() { Other::work(); } }']);
        $before = RevisionFacts::collect($old, RevisionConfiguration::from($old));
        $after = RevisionFacts::collect($old, RevisionConfiguration::from($old), $before->reuse);
        $this->assertSame(1, $before->metrics['parsed_files']);
        $this->assertSame(0, $after->metrics['parsed_files']);
        $this->assertSame(1, $after->metrics['reused_files']);
        $this->assertSame($before->graph->edges, $after->graph->edges);
        $changed = $this->source([...$old->files, 'config/architectures.php' => '<?php return ["audit" => ["classification" => ["roles" => [["path" => "app", "role" => "domain"]]]]];']);
        $facts = RevisionFacts::collect($changed, RevisionConfiguration::from($changed), $after->reuse);
        $this->assertSame(0, $facts->metrics['reused_files']);
        $this->assertSame('domain', $facts->symbols['app\run']['role']);
        $this->assertGreaterThanOrEqual(0, $facts->metrics['elapsed_ms']);
    }

    public function test_unknown_configuration_does_not_claim_roles_or_read_current_settings(): void
    {
        $source = $this->source(['config/architectures.php' => '<?php return env("CONFIG");', 'app/Actions/Run.php' => '<?php namespace App; class Run {}']);
        $facts = RevisionFacts::collect($source, RevisionConfiguration::from($source));
        $this->assertNull($facts->symbols['app\run']['role']);
        $this->assertNull($facts->symbols['app\run']['module']);
        $this->assertNotEmpty($facts->notices);
    }

    public function test_rule_source_changes_are_separate_from_rule_settings_and_comments(): void
    {
        $files = ['config/architectures.php' => '<?php return ["rules" => [\\App\\Rule::class]];',
            'app/Rule.php' => '<?php namespace App; class Rule implements \\GracjanKubicki\\ArchitectureKit\\Audit\\AuditRule { public function supports(string $path, array $enabled): bool { return true; } public function check(\\GracjanKubicki\\ArchitectureKit\\Audit\\FileContext $file): array { return []; } }'];
        $old = $this->source($files);
        $before = RevisionFacts::collect($old, RevisionConfiguration::from($old));
        $files['app/Rule.php'] = str_replace('return true', 'return false', $files['app/Rule.php']);
        $new = $this->source($files);
        $changes = RevisionChanges::compare($before, RevisionFacts::collect($new, RevisionConfiguration::from($new)));
        $this->assertCount(2, $changes['rule_sources']);
        $this->assertSame([], $changes['configuration']);
        $this->assertSame('app/Rule.php', $changes['rule_sources'][0]['before']['path']);
        $files['app/Rule.php'] = str_replace('return false', 'return true', $files['app/Rule.php']);
        $files['app/Rule.php'] = str_replace('<?php', "<?php\n// comment\n", $files['app/Rule.php']);
        $shift = $this->source($files);
        $changes = RevisionChanges::compare($before, RevisionFacts::collect($shift, RevisionConfiguration::from($shift)));
        $this->assertSame([], $changes['rule_sources']);
    }

    public function test_duplicate_and_conflicting_declarations_keep_known_facts_with_notices(): void
    {
        $source = $this->source(['app/A.php' => '<?php class Same {}', 'app/B.php' => '<?php class Same {}', 'app/Known.php' => '<?php class Known {}']);
        $facts = RevisionFacts::collect($source, RevisionConfiguration::from($source));
        $this->assertTrue($facts->symbols['same']['ambiguous']);
        $this->assertArrayHasKey('known', $facts->symbols);
        $this->assertFalse($facts->channels['structure']);
        $this->assertNotEmpty($facts->notices);
    }

    public function test_declaration_shapes_are_independent_of_neighbouring_classes(): void
    {
        $first = $this->source(['app/Many.php' => '<?php namespace App; class A { public function run() {} } class B { public function run() { return 1; } }']);
        $second = $this->source(['app/Many.php' => '<?php namespace App; class A { public function run() {} } class B { public function run() { return 2; } }']);
        $changes = RevisionChanges::compare(RevisionFacts::collect($first, RevisionConfiguration::from($first)), RevisionFacts::collect($second, RevisionConfiguration::from($second)));
        $names = array_column(array_column($changes['symbols'], 'before'), 'name');
        $this->assertNotContains('App\A', $names);
        $this->assertContains('App\B', $names);
    }

    public function test_source_budget_keeps_known_symbols_and_marks_incomplete_channels(): void
    {
        $source = $this->source(['app/Known.php' => '<?php class Known {}', 'app/Large.php' => '<?php '.str_repeat(' ', 110000).'class Large {}']);
        $facts = RevisionFacts::collect($source, RevisionConfiguration::from($source));
        $this->assertArrayHasKey('known', $facts->symbols);
        $this->assertArrayNotHasKey('large', $facts->symbols);
        $this->assertFalse($facts->channels['structure']);
        $this->assertNotEmpty($facts->notices);
    }

    /** @param array<string, string> $files */
    private function source(array $files): SourceSnapshot
    {
        return new SourceSnapshot('git', str_repeat('a', 40), 'hash', $files, [], array_keys($files));
    }
}
