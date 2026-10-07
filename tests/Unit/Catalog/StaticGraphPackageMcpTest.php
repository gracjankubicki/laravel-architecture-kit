<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogPackageVersions;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use PHPUnit\Framework\TestCase;

class StaticGraphPackageMcpTest extends TestCase
{
    /** @return list<CatalogFacts> */
    private function facts(string $source): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build([new FileContext('app/Mcp.php', '<?php '.$source)])->catalogFacts;
    }

    private function package(string $version = '0.8.2', string $path = 'composer.lock'): CatalogFacts
    {
        return (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [['name' => 'laravel/mcp', 'version' => $version]]], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
    }

    public function test_source_servers_members_and_handlers_are_package_version_gated_and_conditional(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Mcp\Facades\Mcp as Protocol;
class MyTool extends \Laravel\Mcp\Server\Tool { public function handle() { return Service::read(); } }
class MyResource extends \Laravel\Mcp\Server\Resource { public function handle() {} }
class MyPrompt extends \Laravel\Mcp\Server\Prompt { public function handle() {} }
class Service { public static function read() {} }
class Base extends \Laravel\Mcp\Server { protected array $tools = [MyTool::class]; }
class Server extends Base { protected array $resources = [MyResource::class]; protected array $prompts = [MyPrompt::class]; }
Protocol::web(route: '/mcp', serverClass: Server::class);
Protocol::local('local-tools', Server::class);
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $this->assertStringNotContainsString('source-only-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'starts-mcp-server'));
        $handlers = $this->edges($index, 'mcp-member-handler');
        $this->assertCount(4, $handlers);
        $this->assertContains('mcp-tool', $index->elements[$index->names[strtolower('App\MyTool')][0]]['roles']);
        foreach ($handlers as $handler) {
            $this->assertSame('0.8.2.0', $handler['metadata']['version']);
            $this->assertTrue($handler['metadata']['request_selects_member_required']);
            $this->assertTrue($handler['metadata']['runtime_member_eligible_required']);
            $this->assertFalse($handler['metadata']['execution_proven']);
            $this->assertNotEmpty($handler['metadata']['package_sources']);
        }
        foreach ([[], [$this->package('99.0.0')], [$this->package(), $this->package('0.9.0', 'vendor/composer/installed.json')]] as $packages) {
            $index = new CatalogIndex([...$facts, ...$packages]);
            $this->assertSame([], $this->edges($index, 'mcp-member-handler'));
            $this->assertSame([], $this->edges($index, 'starts-mcp-server'));
            $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
            $this->assertNotEmpty($index->names[strtolower('App\MyTool::handle')]);
        }
    }

    public function test_source_overrides_lookalikes_and_dynamic_selectors_do_not_invent_mcp_handlers(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Tool extends \Laravel\Mcp\Server\Tool { private function handle() {} }
class Decoy { public function handle() {} }
class Plain { protected array $tools = [Decoy::class]; }
class Dynamic extends \Laravel\Mcp\Server { protected array $tools = UNKNOWN; }
class Override extends \Laravel\Mcp\Server { protected array $tools = [Decoy::class]; public function createContext() { return unknown(); } }
\Laravel\Mcp\Facades\Mcp::web($uri, Dynamic::class);
\Laravel\Mcp\Facades\Mcp::local('decoy', Decoy::class);
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($index, 'mcp-member-handler'));
        $this->assertSame([], $this->edges($index, 'starts-mcp-server'));
        $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_shared_source_contracts_work_across_inspected_mcp_releases_without_execution(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Mcp\Facades\Mcp as Protocol;
use Laravel\Mcp\Server\Attributes\Name as Label;
#[Label('read-item')]
class Reader extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Item extends \Laravel\Mcp\Server\Resource { public function handle() {} }
class Describe extends \Laravel\Mcp\Server\Prompt { public function handle() {} }
class Decoy { public function handle() {} }
class Server extends \Laravel\Mcp\Server {
    protected array $tools = [Reader::class];
    protected array $resources = [Item::class];
    protected array $prompts = [Describe::class];
}
class Unknown extends \Laravel\Mcp\Server { protected array $tools = UNKNOWN; }
class Group extends \Laravel\Mcp\Server { protected array $tools = [\Laravel\Mcp\Server\Tools\ToolSearch::class => [Reader::class]]; }
Protocol::web('/mcp', Server::class);
Protocol::local('local', Server::class);
Protocol::local('decoy', Decoy::class);
throw new \RuntimeException('mcp-execution-sentinel');
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $this->assertStringNotContainsString('mcp-execution-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
        foreach (CatalogPackageVersions::MCP_REFERENCES as $version => $reference) {
            $package = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', json_encode(['packages' => [
                ['name' => 'laravel/mcp', 'version' => $version, 'source' => ['reference' => $reference]],
            ]], JSON_THROW_ON_ERROR)));
            $index = new CatalogIndex([...$cached, $package]);
            $this->assertCount(2, $this->edges($index, 'starts-mcp-server'), $version);
            $handlers = $this->edges($index, 'mcp-member-handler');
            $this->assertCount(version_compare($version, '0.9.6.0', '>=') ? 4 : 3, $handlers, $version);
            foreach ($handlers as $handler) {
                $this->assertSame($handler['metadata']['dispatch'] === 'execute_tools' ? 'MCP execute_tools dispatch' : 'App\\Server', $index->elements[$handler['from']]['name']);
                $this->assertSame($version, $handler['metadata']['version']);
                $this->assertFalse($handler['metadata']['execution_proven']);
                $this->assertTrue($handler['metadata']['request_selects_member_required']);
                $this->assertTrue($handler['metadata']['runtime_standard_dispatch_required']);
            }
            $names = $this->edges($index, 'declares-mcp-name');
            $this->assertCount(1, $names, $version);
            $this->assertSame('read-item', $names[0]['metadata']['selector']);
            $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_literal_class_strings_and_cached_list_edits_follow_current_source_members(): void
    {
        $source = <<<'SOURCE'
namespace App;
class First extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Second extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Server extends \Laravel\Mcp\Server { protected array $tools = ['\\App\\First']; }
\Laravel\Mcp\Facades\Mcp::web('/mcp', 'App\\Server');
SOURCE;
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($source));
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'starts-mcp-server'));
        $handlers = $this->edges($index, 'mcp-member-handler');
        $this->assertCount(1, $handlers);
        $this->assertSame('App\\First::handle', $index->elements[$handlers[0]['to']]['name']);

        $edited = str_replace('App\\\\First', 'App\\\\Second', $source);
        $fresh = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($edited));
        $index = new CatalogIndex([...$fresh, $this->package()]);
        $handlers = $this->edges($index, 'mcp-member-handler');
        $this->assertCount(1, $handlers);
        $this->assertSame('App\\Second::handle', $index->elements[$handlers[0]['to']]['name']);
        $this->assertStringNotContainsString('App\\First::handle', json_encode($handlers, JSON_THROW_ON_ERROR));
    }

    public function test_reserved_tool_search_key_requires_an_array_only_in_group_capable_versions(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Reader extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Server extends \Laravel\Mcp\Server { protected array $tools = [\Laravel\Mcp\Server\Tools\ToolSearch::class => Reader::class]; }
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        foreach (['0.8.2', '0.9.5', '0.9.6', '1.0.0'] as $version) {
            $index = new CatalogIndex([...$cached, $this->package($version)]);
            $this->assertCount(version_compare($version, '0.9.6', '<') ? 1 : 0, $this->edges($index, 'mcp-member-handler'), $version);
            if (version_compare($version, '0.9.6', '>=')) {
                $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
            }
        }
    }

    public function test_tool_search_groups_keep_direct_and_indirect_dispatch_separate_and_invalid_groups_explicit(): void
    {
        $source = <<<'SOURCE'
namespace App;
use Laravel\Mcp\Server\Tools\ToolSearch as SearchGroup;
class Reader extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Decoy { public function handle() {} }
class Server extends \Laravel\Mcp\Server { protected array $tools = [Reader::class, SearchGroup::class => [Reader::class]]; }
SOURCE;
        foreach (['0.9.6', '1.0.0'] as $version) {
            $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($source));
            $index = new CatalogIndex([...$cached, $this->package($version)]);
            $handlers = $this->edges($index, 'mcp-member-handler');
            $this->assertCount(2, $handlers);
            $this->assertEqualsCanonicalizing(['direct', 'execute_tools'], array_column(array_column($handlers, 'metadata'), 'dispatch'));
            foreach ($handlers as $handler) {
                $this->assertSame('App\\Reader::handle', $index->elements[$handler['to']]['name']);
                $this->assertTrue($handler['metadata']['runtime_unique_tool_names_required']);
                $this->assertFalse($handler['metadata']['execution_proven']);
                if ($handler['metadata']['dispatch'] === 'execute_tools') {
                    $this->assertSame('package-operation', $index->elements[$handler['from']]['kind']);
                    $this->assertTrue($handler['metadata']['runtime_group_call_reached_required']);
                }
            }
            foreach (['[Decoy::class]', '[Reader::class,Reader::class]', '[$dynamic]', '[...$dynamic]', "['same'=>Reader::class]", '[[Reader::class]]'] as $group) {
                $invalid = str_replace('SearchGroup::class => [Reader::class]', 'SearchGroup::class => '.$group, $source);
                $index = new CatalogIndex([...$this->facts($invalid), $this->package($version)]);
                $this->assertSame([], $this->edges($index, 'mcp-member-handler'), $group);
                $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
            }
            foreach (['namespace Laravel\\Mcp\\Server\\Tools; class ToolSearch {}',
                'namespace Laravel\\Mcp\\Server\\Tools; class ExecuteTools {}',
                'namespace Laravel\\Mcp\\Server; class ToolInvoker {}',
                'namespace Laravel\\Mcp\\Server; class ServerContext {}'] as $shadow) {
                $index = new CatalogIndex([...$this->facts($source.' '.$shadow), $this->package($version)]);
                $this->assertSame([], $this->edges($index, 'mcp-member-handler'));
            }
        }
        $index = new CatalogIndex([...$this->facts($source), $this->package('0.9.5')]);
        $this->assertSame([], $this->edges($index, 'mcp-member-handler'));
        $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_member_list_limits_and_invalid_class_strings_remain_explicit(): void
    {
        $source = <<<'SOURCE'
namespace App;
class Tool extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Server extends \Laravel\Mcp\Server { protected array $tools = ['not a class?token=secret']; }
\Laravel\Mcp\Facades\Mcp::web('/mcp', 'App\\Server');
SOURCE;
        $facts = $this->facts($source);
        $this->assertStringNotContainsString('token=secret', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($index, 'mcp-member-handler'));
        $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));

        $limited = str_replace("'not a class?token=secret'", implode(',', array_fill(0, 129, 'Tool::class')), $source);
        $index = new CatalogIndex([...$this->facts($limited), $this->package()]);
        $this->assertSame([], $this->edges($index, 'mcp-member-handler'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_source_attribute_names_and_description_hashes_follow_nearest_class_and_overrides(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Mcp\Server\Attributes\Name as ToolName;
use Laravel\Mcp\Server\Attributes\Description;
#[ToolName(value: 'base-tool'), Description('private-description-sentinel')]
class Base extends \Laravel\Mcp\Server\Tool { public function handle() {} }
class Child extends Base {}
#[ToolName('own-tool')]
class Own extends Base {}
class Override extends Base { public function name(): string { return unknown(); } }
#[ToolName($dynamic)]
class Dynamic extends Base {}
#[ToolName('trait-name')]
trait Labels {}
class Plain extends \Laravel\Mcp\Server\Tool { use Labels; public function handle() {} }
#[ToolName('first'), ToolName('second')]
class Repeated extends Base {}
SOURCE);
        $this->assertStringNotContainsString('private-description-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $names = [];
        foreach ($this->edges($index, 'declares-mcp-name') as $edge) {
            $names[$index->elements[$edge['from']]['name']] = $edge['metadata']['selector'];
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertSame(['App\\Base' => 'base-tool', 'App\\Child' => 'base-tool', 'App\\Own' => 'own-tool'], $names);
        $descriptions = $this->edges($index, 'declares-mcp-description');
        $this->assertCount(6, $descriptions);
        foreach ($descriptions as $edge) {
            $this->assertSame(hash('sha256', 'private-description-sentinel'), $edge['metadata']['value_hash']);
            $this->assertNull($edge['metadata']['selector']);
        }
        $this->assertContains('package_mcp_analysis', array_column($index->diagnostics, 'code'));
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'declares-mcp-name'));
    }
}
