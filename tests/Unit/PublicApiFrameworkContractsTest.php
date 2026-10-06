<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\PublicApi\AutoloadSurface;
use GracjanKubicki\ArchitectureKit\PublicApi\ContractChanges;
use GracjanKubicki\ArchitectureKit\PublicApi\FrameworkContracts;
use GracjanKubicki\ArchitectureKit\PublicApi\PhpContracts;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class PublicApiFrameworkContractsTest extends TestCase
{
    public function test_commands_use_literal_signatures_with_arguments_options_and_callbacks(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php namespace Demo;
class Run extends \Illuminate\Console\Command { protected $signature = 'demo:run {id} {mode?} {tags?*} {--Q|queue=}'; }
\Illuminate\Support\Facades\Artisan::command('demo:callback {name=world}', function () { throw new \Exception; });
PHP);
        $this->assertSame('id', $contracts->entries['command:demo:run']['arguments'][0]['name']);
        $this->assertTrue($contracts->entries['command:demo:run']['arguments'][0]['required']);
        $this->assertFalse($contracts->entries['command:demo:run']['arguments'][1]['required']);
        $this->assertTrue($contracts->entries['command:demo:run']['arguments'][2]['array']);
        $this->assertSame('Q', $contracts->entries['command:demo:run']['options'][0]['shortcut']);
        $this->assertSame('world', $contracts->entries['command:demo:callback']['arguments'][0]['default']);
        $this->assertSame([], $contracts->notices);
    }

    public function test_mcp_name_input_and_declared_output_are_read_without_invoking_schema(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php namespace Demo;
#[\Laravel\Mcp\Server\Attributes\Name('demo-tool')]
class DemoTool extends \Laravel\Mcp\Server\Tool {
    public function schema($schema): array { return ['id' => $schema->integer()->required(), 'mode' => $schema->string()->enum(['a', 'b'])->default('a')]; }
    public function outputSchema($schema): array { return ['status' => $schema->string()->required()]; }
    public function handle() { throw new \Exception('must not run'); }
}
PHP);
        $tool = $contracts->entries['mcp:demo-tool'];
        $this->assertSame('integer', $tool['input']['id']['chain'][0]['method']);
        $this->assertSame('required', $tool['input']['id']['chain'][1]['method']);
        $this->assertSame(['a', 'b'], $tool['input']['mode']['chain'][1]['arguments'][0]['value']);
        $this->assertArrayHasKey('status', $tool['output']);
        $this->assertSame([], $contracts->notices);
    }

    public function test_publications_include_config_keys_and_migration_hashes_without_execution(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php class Provider extends \Illuminate\Support\ServiceProvider {
 public function boot() {
  $this->publishes([__DIR__.'/../config/demo.php' => config_path('demo.php')], 'demo-config');
  $this->publishesMigrations([__DIR__.'/../migrations' => database_path('migrations')], 'demo-migrations');
 }
}
PHP, [
            'config/demo.php' => '<?php throw new Exception; return ["enabled" => true, "nested" => ["limit" => env("LIMIT", 5)]];',
            'migrations/create.php' => '<?php throw new Exception; class Migration {}',
        ]);
        $rows = array_values($contracts->entries);
        $this->assertCount(2, $rows);
        $config = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'published_config'))[0];
        $migration = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'published_migration'))[0];
        $this->assertSame(['enabled', 'nested', 'nested.limit'], $config['files']['config/demo.php']['keys']);
        $this->assertArrayHasKey('migrations/create.php', $migration['files']);
        $this->assertSame([], $contracts->notices);
    }

    public function test_event_identities_and_dynamic_declarations_remain_separate(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php namespace Demo;
class Paid { use \Illuminate\Foundation\Events\Dispatchable; }
event(new Paid);
\Illuminate\Support\Facades\Event::dispatch('paid.name');
event($unknown);
class DynamicCommand extends \Illuminate\Console\Command { protected $signature = SIGNATURE; }
class DynamicTool extends \Laravel\Mcp\Server\Tool { public function schema($schema): array { return dynamic_schema(); } }
PHP);
        $this->assertArrayHasKey('event:Demo\\Paid', $contracts->entries);
        $this->assertArrayHasKey('event:paid.name', $contracts->entries);
        $this->assertGreaterThanOrEqual(3, count($contracts->notices));
        $this->assertNull($contracts->entries['mcp:dynamic-tool']['input']);
    }

    public function test_inherited_commands_schemas_and_literal_schema_helpers_are_resolved(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php namespace Demo;
abstract class BaseCommand extends \Illuminate\Console\Command { protected $signature = 'demo:inherited {id}'; }
class Command extends BaseCommand {}
class Schema { public static function output(): array { return ['status' => ['type' => 'string']]; } }
abstract class BaseTool extends \Laravel\Mcp\Server\Tool {
 protected string $name = 'inherited-tool';
 public function schema($schema): array { return ['id' => $schema->integer()->required()]; }
 public function outputSchema($schema): array { return Schema::output(); }
}
class Tool extends BaseTool {}
PHP);
        $this->assertArrayHasKey('command:demo:inherited', $contracts->entries);
        $this->assertTrue($contracts->entries['command:demo:inherited']['arguments'][0]['required']);
        $tool = $contracts->entries['mcp:inherited-tool'];
        $this->assertSame('required', $tool['input']['id']['chain'][1]['method']);
        $this->assertSame(['type' => 'string'], $tool['output']['status']['value']);
        $this->assertSame([], $contracts->notices);
    }

    public function test_dynamic_names_and_conditional_schema_returns_are_not_guessed(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php class Tool extends \Laravel\Mcp\Server\Tool {
 public function name(): string { return getenv('NAME'); }
 public function schema($schema): array { if (flag()) { return ['required' => $schema->string()->required()]; } return []; }
}
PHP);
        $this->assertArrayNotHasKey('mcp:tool', $contracts->entries);
        $tool = $contracts->entries['mcp:@unresolved:Tool'];
        $this->assertTrue($tool['conditional']);
        $this->assertNull($tool['input']);
        $this->assertGreaterThanOrEqual(2, count($contracts->notices));
    }

    public function test_internal_event_exposes_only_public_payload_and_constructor(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php namespace Demo;
/** @internal */ class Paid { public function __construct(public int $id) {} public function helper(): void {} private string $secret; }
event(new Paid(1));
PHP);
        $payload = $contracts->entries['event:Demo\\Paid']['payload'];
        $this->assertArrayHasKey('property:id', $payload);
        $this->assertArrayHasKey('method:__construct', $payload);
        $this->assertArrayNotHasKey('method:helper', $payload);
        $this->assertArrayNotHasKey('property:secret', $payload);
    }

    public function test_trait_schema_and_inherited_event_payload_are_visible(): void
    {
        $contracts = $this->contracts(<<<'PHP'
<?php namespace Demo;
trait ToolSchema { public function schema($schema): array { return ['id' => $schema->integer()->required()]; } }
class Tool extends \Laravel\Mcp\Server\Tool { use ToolSchema; }
/** @internal */ class EventBase { public int $id; }
/** @internal */ class Paid extends EventBase {}
event(new Paid);
PHP);
        $this->assertSame('required', $contracts->entries['mcp:tool']['input']['id']['chain'][1]['method']);
        $this->assertArrayHasKey('property:id', $contracts->entries['event:Demo\\Paid']['payload']);
        $this->assertSame([], $contracts->notices);
    }

    public function test_command_and_mcp_required_changes_are_breaking_and_migrations_need_check(): void
    {
        $compare = function (string $before, string $after): array {
            return (new ContractChanges)->compare($this->contracts($before)->entries, $this->contracts($after)->entries);
        };
        $before = '<?php class Run extends \Illuminate\Console\Command { protected $signature = "demo:run {id?}"; }';
        $after = '<?php class Run extends \Illuminate\Console\Command { protected $signature = "demo:run {id}"; }';
        $this->assertSame('breaking', $compare($before, $after)[0]['verdict']);
        $before = '<?php class Tool extends \Laravel\Mcp\Server\Tool { public function schema($schema): array { return []; } }';
        $required = '<?php class Tool extends \Laravel\Mcp\Server\Tool { public function schema($schema): array { return ["id" => $schema->integer()->required()]; } }';
        $optional = str_replace('->required()', '', $required);
        $this->assertSame('breaking', $compare($before, $required)[0]['verdict']);
        $this->assertSame('compatible', $compare($before, $optional)[0]['verdict']);
        $entry = ['kind' => 'published_migration', 'name' => 'migration', 'files' => ['create.php' => ['hash' => 'old']], 'source' => ['path' => 'create.php', 'line' => 1]];
        $changed = [...$entry, 'files' => ['create.php' => ['hash' => 'new']]];
        $comparator = new ContractChanges;
        $this->assertSame('check', $comparator->compare(['migration' => $entry], ['migration' => $changed])[0]['verdict']);
        $this->assertSame('check', $comparator->compare(['migration' => $entry], [])[0]['verdict']);
    }

    public function test_named_events_preserve_literal_payload_and_mark_dynamic_payload(): void
    {
        foreach (['event', '\\Illuminate\\Support\\Facades\\Event::dispatch'] as $dispatch) {
            $before = $this->contracts('<?php '.$dispatch.'("paid", ["id" => 1]);');
            $after = $this->contracts('<?php '.$dispatch.'("paid", ["code" => 1]);');
            $this->assertSame(['id' => 1], $before->entries['event:paid']['payload']['value']);
            $this->assertSame([], $before->notices);
            $rows = (new ContractChanges)->compare($before->entries, $after->entries);
            $this->assertSame('check', $rows[0]['verdict']);
            $dynamic = $this->contracts('<?php '.$dispatch.'("paid", payload());');
            $this->assertFalse($dynamic->entries['event:paid']['payload']['known']);
            $this->assertNotEmpty($dynamic->notices);
            $this->assertSame('check', (new ContractChanges)->compare($before->entries, $dynamic->entries)[0]['verdict']);
        }
    }

    public function test_identical_named_event_emissions_share_one_contract(): void
    {
        $contracts = $this->contracts('<?php event("paid", ["id" => 1]); \Illuminate\Support\Facades\Event::dispatch("paid", ["id" => 1]);');
        $this->assertCount(1, $contracts->entries);
        $this->assertSame([], $contracts->notices);
        $this->assertArrayNotHasKey('ambiguous', $contracts->entries['event:paid']);
        $different = $this->contracts('<?php event("paid", ["id" => 1]); event("paid", ["code" => 1]);');
        $this->assertTrue($different->entries['event:paid']['ambiguous']);
        $this->assertNotEmpty($different->notices);
    }

    public function test_conditional_and_nested_callback_commands_do_not_prove_breaking_removal(): void
    {
        $call = '\\Illuminate\\Support\\Facades\\Artisan::command("demo:run", function () {});';
        foreach (['if (enabled()) {'.$call.'}', 'function register() {'.$call.'}', 'class Provider { public function boot() {'.$call.'} }'] as $source) {
            $contracts = $this->contracts('<?php '.$source);
            $this->assertTrue($contracts->entries['command:demo:run']['conditional']);
            $this->assertNotEmpty($contracts->notices);
            $this->assertSame('check', (new ContractChanges)->compare($contracts->entries, [])[0]['verdict']);
        }
        $contracts = $this->contracts('<?php '.$call);
        $this->assertFalse($contracts->entries['command:demo:run']['conditional']);
        $this->assertSame('breaking', (new ContractChanges)->compare($contracts->entries, [])[0]['verdict']);
    }

    /** @param array<string, string> $extra */
    private function contracts(string $source, array $extra = []): FrameworkContracts
    {
        $files = ['composer.json' => '{"autoload":{"classmap":["src"]}}', 'src/Api.php' => $source, ...$extra];
        $snapshot = new SourceSnapshot('git', 'abc', 'hash', $files, [], array_keys($files));
        $surface = new AutoloadSurface($snapshot);

        return new FrameworkContracts($snapshot, $surface, new PhpContracts($snapshot, $surface));
    }
}
