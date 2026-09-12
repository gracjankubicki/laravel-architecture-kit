<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class ProjectGraphCacheTest extends TestCase
{
    public function test_a_stored_graph_comes_back_exactly_as_it_went_in(): void
    {
        $cache = $this->cache();
        $graph = $this->graph($this->signature());

        $this->assertTrue($cache->write($graph));

        $restored = $cache->read($this->signature())->graph;

        $this->assertNotNull($restored);
        $this->assertSame(array_keys($graph->entries), array_keys($restored->entries));

        $symbol = $restored->entries['app/Actions/SendInvoice.php']->symbols[0];
        $edge = $restored->entries['app/Actions/SendInvoice.php']->edges[0];

        $this->assertSame('App\\Actions\\SendInvoice', $symbol->name);
        $this->assertSame('app/Actions/SendInvoice.php', $symbol->path);
        $this->assertSame(7, $symbol->line);
        $this->assertSame('App\\Actions', $symbol->namespace);
        $this->assertSame('class', $symbol->kind);
        $this->assertSame('application', $symbol->role);
        $this->assertTrue($symbol->hasMethods);

        $this->assertSame('App\\Actions\\SendInvoice', $edge->from);
        $this->assertSame('App\\Models\\Invoice', $edge->to);
        $this->assertSame(12, $edge->line);
        $this->assertSame('parameter', $edge->kind);
        $this->assertTrue($edge->strong);
    }

    public function test_an_entry_written_under_another_fingerprint_is_not_used(): void
    {
        // The stored symbols and edges may have been produced by different rules, so
        // reparsing a few files could not bring the entry up to date.
        $this->cache()->write($this->graph($this->signature('one')));

        $this->assertNull($this->cache()->read($this->signature('two'))->graph);
    }

    public function test_nothing_stored_yet_is_simply_a_miss(): void
    {
        $this->assertNull($this->cache()->read($this->signature())->graph);
        $this->assertSame(CacheStatus::Missing, $this->cache()->read($this->signature())->status);
    }

    public function test_a_rejected_entry_says_why_it_was_rejected(): void
    {
        // A rebuild produces the right answer either way, so without the reason a broken
        // entry and an absent one look identical while one of them costs twenty seconds
        // on every single run.
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));
        (new Filesystem)->put($this->storedFile(), 'not serialized at all');

        $result = $cache->read($this->signature());

        $this->assertSame(CacheStatus::Corrupt, $result->status);
        $this->assertTrue($result->status->isNoteworthy());
        $this->assertNotNull($result->status->note());
    }

    public function test_an_entry_too_large_for_the_remaining_budget_is_refused_rather_than_allocated(): void
    {
        // Restoring costs about three times the file on disk and costs it at once, so
        // reading first and discovering the limit afterwards means an allocation failure
        // instead of a slower run.
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        $tight = new ProjectGraphCache(
            new Filesystem,
            $this->tempPath,
            ProjectGraphCache::DIRECTORY,
            memoryLimitBytes: 1024,
            memoryUsage: static fn (): int => 512,
        );

        $result = $tight->read($this->signature());

        $this->assertNull($result->graph);
        $this->assertSame(CacheStatus::TooLarge, $result->status);
        $this->assertTrue($result->status->isNoteworthy());
    }

    public function test_an_entry_that_fits_the_budget_is_still_used(): void
    {
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        $roomy = new ProjectGraphCache(
            new Filesystem,
            $this->tempPath,
            ProjectGraphCache::DIRECTORY,
            memoryLimitBytes: 512 * 1024 * 1024,
            memoryUsage: static fn (): int => 1024,
        );

        $this->assertNotNull($roomy->read($this->signature())->graph);
    }

    public function test_a_truncated_file_is_treated_as_no_cache_rather_than_an_error(): void
    {
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        $path = $this->storedFile();
        $files = new Filesystem;
        $files->put($path, substr((string) $files->get($path), 0, 40));

        $this->assertNull($cache->read($this->signature())->graph);
    }

    public function test_a_file_that_is_not_serialized_data_is_treated_as_no_cache(): void
    {
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));
        (new Filesystem)->put($this->storedFile(), 'not serialized at all');

        $this->assertNull($cache->read($this->signature())->graph);
    }

    public function test_a_file_holding_an_object_payload_is_refused(): void
    {
        // Restoring objects from the file would make it a description of this package's
        // classes, and a file dropped in by anything else would then be instantiated.
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));
        (new Filesystem)->put($this->storedFile(), serialize(new \stdClass));

        $this->assertNull($cache->read($this->signature())->graph);
    }

    public function test_an_entry_missing_its_symbol_fields_is_refused(): void
    {
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        $data = $this->storedArray();
        $data['entries']['app/Actions/SendInvoice.php']['s'][0] = ['App\\Actions\\SendInvoice'];
        $this->rewriteStored($data);

        $this->assertNull($cache->read($this->signature())->graph);
    }

    public function test_an_entry_whose_field_has_the_wrong_type_is_refused(): void
    {
        // A line number arriving as an array would otherwise raise deep inside the
        // command, long after the file that caused it stopped being visible.
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        $data = $this->storedArray();
        $data['entries']['app/Actions/SendInvoice.php']['e'][0][2] = ['not a line'];
        $this->rewriteStored($data);

        $this->assertNull($cache->read($this->signature())->graph);
    }

    public function test_a_first_write_under_a_new_fingerprint_still_respects_the_budget(): void
    {
        // Nothing has been stored under this fingerprint, which is exactly the state a
        // changed configuration or an upgraded package is in. Sizing the write from the
        // file it replaces left this case unchecked.
        $tight = new ProjectGraphCache(
            new Filesystem,
            $this->tempPath,
            ProjectGraphCache::DIRECTORY,
            memoryLimitBytes: 1024,
            memoryUsage: static fn (): int => 512,
        );

        $this->assertFalse($tight->write($this->graph($this->signature())));
        $this->assertSame([], (new Filesystem)->glob($tight->absoluteDirectory().'/*.cache') ?: []);
    }

    public function test_a_graph_that_outgrew_the_budget_is_not_written(): void
    {
        // The same limit accepts a small graph and refuses a large one, so the estimate
        // follows the graph rather than whatever happened to be on disk before.
        $cache = new ProjectGraphCache(
            new Filesystem,
            $this->tempPath,
            ProjectGraphCache::DIRECTORY,
            memoryLimitBytes: 200_000,
            memoryUsage: static fn (): int => 0,
        );

        $this->assertTrue($cache->write($this->graph($this->signature())));
        $this->assertFalse($cache->write($this->largeGraph($this->signature())));
    }

    public function test_a_symbol_cannot_disagree_with_the_file_it_is_stored_under(): void
    {
        // The path is taken from the entry key rather than stored beside each symbol, so
        // there is no second copy to drift from the first. Whatever key an entry arrives
        // under is the path everything in it gets.
        $stored = $this->graph($this->signature())->toArray();
        $stored['entries']['app/Actions/Renamed.php'] = $stored['entries']['app/Actions/SendInvoice.php'];
        unset($stored['entries']['app/Actions/SendInvoice.php']);

        $restored = CachedGraph::fromArray($stored);

        $this->assertNotNull($restored);
        $this->assertSame('app/Actions/Renamed.php', $restored->entries['app/Actions/Renamed.php']->symbols[0]->path);
        $this->assertSame('app/Actions/Renamed.php', $restored->entries['app/Actions/Renamed.php']->edges[0]->path);
    }

    public function test_an_entry_stripped_of_its_symbols_is_refused_rather_than_believed(): void
    {
        // The damaging case is the one that still looks valid: an entry with no symbols
        // is exactly what a file declaring none produces, so nothing about its shape says
        // it was emptied. The stat signature matches, so the class would simply cease to
        // exist as far as the graph is concerned.
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        // The digest stays as written, because that is the whole point: the file no
        // longer hashes to it.
        $data = $this->storedArray();
        $data['entries']['app/Actions/SendInvoice.php'] = ['s' => [], 'e' => []];
        (new Filesystem)->put($this->storedFile(), serialize($data));

        $result = $cache->read($this->signature());

        $this->assertNull($result->graph);
        $this->assertSame(CacheStatus::Corrupt, $result->status);
    }

    public function test_a_payload_edited_behind_the_digest_is_refused(): void
    {
        $cache = $this->cache();
        $cache->write($this->graph($this->signature()));

        $raw = (string) (new Filesystem)->get($this->storedFile());
        (new Filesystem)->put($this->storedFile(), $raw.' ');

        $this->assertSame(CacheStatus::Corrupt, $cache->read($this->signature())->status);
    }

    public function test_entries_without_symbols_are_still_counted_against_the_budget(): void
    {
        // A project of files that declare nothing produces entries with no symbols and no
        // edges, and counting only those would estimate the write at zero bytes: the one
        // graph guaranteed to pass a budget it cannot actually fit.
        $entries = [];

        for ($i = 0; $i < 200; $i++) {
            $entries['app/Some/Deeply/Nested/Directory/Empty'.$i.'.php'] = new FileGraphEntry([], []);
        }

        $cache = new ProjectGraphCache(
            new Filesystem,
            $this->tempPath,
            ProjectGraphCache::DIRECTORY,
            memoryLimitBytes: 50_000,
            memoryUsage: static fn (): int => 0,
        );

        $this->assertFalse($cache->write(new CachedGraph($this->signature(), $entries)));
    }

    public function test_clearing_removes_every_stored_graph(): void
    {
        $cache = $this->cache();
        $cache->write($this->graph($this->signature('one')));
        $cache->write($this->graph($this->signature('two')));

        $this->assertSame(2, $cache->clear());
        $this->assertNull($cache->read($this->signature('one'))->graph);
        $this->assertNull($cache->read($this->signature('two'))->graph);
    }

    public function test_clearing_a_cache_that_was_never_written_is_not_an_error(): void
    {
        $this->assertSame(0, $this->cache()->clear());
    }

    public function test_the_default_location_is_a_directory_laravel_already_ignores(): void
    {
        // 69.8 MB on a large application, so the default must not be something a project
        // commits by accident.
        $this->assertSame('storage/framework/cache/architecture-kit', ProjectGraphCache::DIRECTORY);
        $this->assertSame($this->tempPath.'/storage/framework/cache/architecture-kit', $this->cache()->absoluteDirectory());
    }

    public function test_a_writing_failure_leaves_the_command_working(): void
    {
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath, '/proc/architecture-kit-should-not-be-writable');

        $this->assertFalse($cache->write($this->graph($this->signature())));
        $this->assertNull($cache->read($this->signature())->graph);
    }

    private function cache(): ProjectGraphCache
    {
        return new ProjectGraphCache(new Filesystem, $this->tempPath);
    }

    private function signature(string $fingerprint = 'one'): GraphCacheSignature
    {
        return GraphCacheSignature::create([$fingerprint], ['app/Actions/SendInvoice.php' => '100:10']);
    }

    private function graph(GraphCacheSignature $signature): CachedGraph
    {
        return new CachedGraph($signature, [
            'app/Actions/SendInvoice.php' => new FileGraphEntry(
                [new ProjectSymbol('App\\Actions\\SendInvoice', 'app/Actions/SendInvoice.php', 7, 'App\\Actions', 'class', 'application')],
                [new DependencyEdge('App\\Actions\\SendInvoice', 'App\\Models\\Invoice', 'app/Actions/SendInvoice.php', 12, 'parameter', true)],
            ),
        ]);
    }

    /** A graph whose serialized form cannot fit a small budget. */
    private function largeGraph(GraphCacheSignature $signature): CachedGraph
    {
        $symbols = [];
        $edges = [];

        for ($i = 0; $i < 400; $i++) {
            $symbols[] = new ProjectSymbol('App\\Big'.$i, 'app/Big'.$i.'.php', 1, 'App', 'class', 'application');
            $edges[] = new DependencyEdge('App\\Big'.$i, 'App\\Models\\Invoice', 'app/Big'.$i.'.php', 3, 'parameter', true);
        }

        return new CachedGraph($signature, ['app/Big.php' => new FileGraphEntry($symbols, $edges)]);
    }

    private function storedFile(): string
    {
        $files = (new Filesystem)->glob($this->cache()->absoluteDirectory().'/*.cache');

        $this->assertNotEmpty($files);

        return $files[0];
    }

    /** @return array<string, mixed> */
    private function storedArray(): array
    {
        $raw = (string) (new Filesystem)->get($this->storedFile());
        /** @var array<string, mixed> $data */
        $data = unserialize(substr($raw, (int) strpos($raw, "\n") + 1), ['allowed_classes' => false]);

        return $data;
    }

    /**
     * Rewrite the stored file the way the cache would, digest included.
     *
     * A test that tampers with the payload has to choose: leave the digest alone to prove
     * the tampering is caught, or recompute it to prove something about the payload
     * itself. Both are needed, so both are explicit.
     *
     * @param  array<string, mixed>  $data
     */
    private function rewriteStored(array $data): void
    {
        $payload = serialize($data);
        (new Filesystem)->put($this->storedFile(), hash('xxh128', $payload)."\n".$payload);
    }
}
