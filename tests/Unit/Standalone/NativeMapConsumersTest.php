<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\GraphPolicy;
use GracjanKubicki\ArchitectureKit\Standalone\GraphResources;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeConsumerGuard;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class NativeMapConsumersTest extends TestCase
{
    public function test_actual_native_consumer_sources_cannot_hydrate_or_collect_the_full_php_catalog(): void
    {
        $sources = [];
        foreach (glob(dirname(__DIR__, 3).'/src/Standalone/NativeGraph/*.php') as $path) {
            $sources[$path] = file_get_contents($path);
        }
        self::assertSame([], NativeConsumerGuard::violations($sources));
        $path = dirname(__DIR__, 3).'/src/Standalone/NativeGraph/NativeObservedCatalog.php';
        // Mutate the real consumer source read above, not an invented isolated guard input.
        $sources[$path] = str_replace('$started = hrtime(true);', '$index = new \\GracjanKubicki\\ArchitectureKit\\Catalog\\CatalogIndex([]); $started = hrtime(true);', $sources[$path]);
        self::assertStringContainsString('full_php_catalog', implode(',', NativeConsumerGuard::violations($sources)));
    }

    public function test_native_lru_counts_live_client_and_shared_process_trees_once(): void
    {
        $root = realpath(sys_get_temp_dir()).'/kit-r04-accounting-'.bin2hex(random_bytes(6));
        $home = $root.'-home';
        mkdir($root.'/.architecture-kit', 0700, true);
        file_put_contents($root.'/composer.json', '{"name":"fixture/accounting"}');
        file_put_contents($root.'/.architecture-kit/settings.json', '{"analysis":{"engine":"php"},"graph_runtime":{"representation":"rust","resident_budget_bytes":268435456,"transition_budget_bytes":536870912}}');
        try {
            $resources = new GraphResources(new RepositoryContext($root, $home));
            $state = new StateStore($home);
            $state->update('graph-resources-v2', static function (array $r): array {
                $r['native_clients']['same-client'] = ['pid' => getmypid()];

                return $r;
            });
            $resources->controller('same-controller', ['pid' => getmypid(), 'bytes' => 268435456]);
            $resources->resident('same-worker', ['pid' => getmypid(), 'bytes' => 268435456, 'last_activity' => 1]);
            $registry = $state->read('graph-resources-v2');
            self::assertSame('unique_pid_forest_rss', $registry['resident_accounting']['basis']);
            self::assertNull($registry['resident_accounting']['reason']);
            self::assertGreaterThan(0, $registry['resident_accounting']['rss_bytes']);
            self::assertLessThan(268435456, $registry['resident_accounting']['rss_bytes']);
            self::assertNull($resources->retirement('same-worker'), 'Duplicate registry tree estimates must not force LRU eviction.');
        } finally {
            (new Filesystem)->deleteDirectory($root);
            (new Filesystem)->deleteDirectory($home);
        }
    }

    public function test_representation_defaults_to_php_and_is_independent_of_parser(): void
    {
        self::assertSame('php', (new GraphPolicy(['resident_budget_bytes' => 100, 'transition_budget_bytes' => 100]))->representation);
        self::assertSame('rust', (new GraphPolicy(['resident_budget_bytes' => 100, 'transition_budget_bytes' => 100, 'representation' => 'rust']))->representation);
        $this->expectException(\InvalidArgumentException::class);
        new GraphPolicy(['representation' => 'other']);
    }
}
