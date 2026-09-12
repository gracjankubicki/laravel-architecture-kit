<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class ProjectGraphCacheReuseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeClass('app/Models/Invoice.php', 'App\\Models', 'Invoice');
        $this->writeClass('app/Actions/SendInvoice.php', 'App\\Actions', 'SendInvoice', 'App\\Models\\Invoice');
    }

    public function test_a_cached_graph_is_identical_to_one_built_from_scratch(): void
    {
        // The whole feature rests on this: if the answer differed at all, a project would
        // be trading correctness for speed without being told.
        $expected = $this->describe($this->loader(cached: false)->load());

        $this->loader()->load();

        $this->assertSame($expected, $this->describe($this->loader()->load()));
    }

    public function test_the_second_run_answers_without_reading_the_unchanged_files(): void
    {
        $this->loader()->load();

        $reads = $this->countingFiles();
        (new ProjectGraphLoader($reads, $this->tempPath, new AuditScope, $this->cache()))->load();

        $this->assertSame([], $reads->read, 'An unchanged project should not open a single source file.');
    }

    public function test_only_the_changed_file_is_read_again(): void
    {
        $this->loader()->load();
        $this->touchFile('app/Actions/SendInvoice.php');

        $reads = $this->countingFiles();
        (new ProjectGraphLoader($reads, $this->tempPath, new AuditScope, $this->cache()))->load();

        $this->assertSame(['app/Actions/SendInvoice.php'], $reads->read);
    }

    public function test_an_edited_file_changes_the_answer(): void
    {
        $this->loader()->load();

        $this->writeClass('app/Actions/SendInvoice.php', 'App\\Actions', 'SendInvoice', 'App\\Models\\Customer');
        $this->touchFile('app/Actions/SendInvoice.php');

        $graph = $this->loader()->load();
        $targets = array_map(static fn (DependencyEdge $edge): string => $edge->to, $graph->dependenciesOf('App\\Actions\\SendInvoice'));

        $this->assertContains('App\\Models\\Customer', $targets);
        $this->assertNotContains('App\\Models\\Invoice', $targets);
    }

    public function test_a_new_file_appears_in_the_answer(): void
    {
        $this->loader()->load();

        $this->writeClass('app/Actions/VoidInvoice.php', 'App\\Actions', 'VoidInvoice', 'App\\Models\\Invoice');

        $graph = $this->loader()->load();

        $this->assertNotNull($graph->symbol('App\\Actions\\VoidInvoice'));
        $this->assertSame(
            $this->describe($this->loader(cached: false)->load()),
            $this->describe($graph),
        );
    }

    public function test_a_deleted_file_leaves_the_answer(): void
    {
        // Deletion is the change a per-file comparison cannot see, because every
        // surviving file keeps its stat.
        $this->loader()->load();

        (new Filesystem)->delete($this->tempPath.'/app/Actions/SendInvoice.php');

        $graph = $this->loader()->load();

        $this->assertNull($graph->symbol('App\\Actions\\SendInvoice'));
        $this->assertSame([], $graph->dependentsOf('App\\Models\\Invoice'));
    }

    public function test_a_wider_scope_is_not_answered_from_the_narrow_entry(): void
    {
        $this->writeFile('routes/web.php', "<?php\n\nuse App\\Actions\\SendInvoice;\n\nnew SendInvoice;\n");

        $this->loader()->load();

        $wider = new ProjectGraphLoader(new Filesystem, $this->tempPath, new AuditScope(['app', 'routes']), $this->cache());

        $this->assertNotSame([], $wider->load()->dependentsOf('App\\Actions\\SendInvoice'));
    }

    public function test_a_project_whose_configuration_differs_does_not_share_an_entry(): void
    {
        $this->loader(configuration: ['thin-controllers'])->load();

        // Both halves are needed: without the first, a cache that never hits would pass
        // this test for the wrong reason.
        $same = $this->countingFiles();
        (new ProjectGraphLoader($same, $this->tempPath, new AuditScope, $this->cache(), ['thin-controllers']))->load();

        $differing = $this->countingFiles();
        (new ProjectGraphLoader($differing, $this->tempPath, new AuditScope, $this->cache(), ['thin-controllers', 'actions']))->load();

        $this->assertSame([], $same->read, 'The same configuration should have been reused.');
        $this->assertCount(2, $differing->read, 'A different configuration must be rebuilt rather than reused.');
    }

    public function test_a_corrupt_entry_is_rebuilt_instead_of_failing(): void
    {
        $expected = $this->describe($this->loader()->load());

        foreach ((new Filesystem)->glob($this->cache()->absoluteDirectory().'/*.cache') ?: [] as $file) {
            (new Filesystem)->put($file, 'not a cache file');
        }

        $this->assertSame($expected, $this->describe($this->loader()->load()));
    }

    public function test_an_entry_missing_a_file_it_claims_to_describe_is_repaired(): void
    {
        // The signature lists every file, the contributions are stored separately, and
        // nothing forces the two to agree. An entry where they disagree used to look
        // complete: no file had moved, so nothing was reparsed, and the missing class
        // simply vanished from the graph with no sign that anything was wrong.
        $expected = $this->describe($this->loader()->load());

        $this->rewriteStoredWithout('app/Actions/SendInvoice.php');

        $this->assertSame($expected, $this->describe($this->loader()->load()));
    }

    public function test_the_repaired_entry_is_written_back(): void
    {
        // Repairing in memory alone would leave the damaged entry on disk, and every
        // later run would pay to repair it again.
        $this->loader()->load();

        $this->rewriteStoredWithout('app/Actions/SendInvoice.php');

        $this->loader()->load();

        $raw = (string) (new Filesystem)->get($this->storedFile());
        $restored = unserialize(substr($raw, (int) strpos($raw, "\n") + 1), ['allowed_classes' => false]);

        $this->assertArrayHasKey('app/Actions/SendInvoice.php', $restored['entries']);
    }

    /**
     * Drop one entry from the stored graph, leaving a file the cache still accepts.
     *
     * The digest is recomputed on purpose: this is about an entry whose file list and
     * contributions disagree, not about a file somebody edited, and leaving a stale
     * digest would make the read fail for the wrong reason.
     */
    private function rewriteStoredWithout(string $path): void
    {
        $file = $this->storedFile();
        $raw = (string) (new Filesystem)->get($file);
        $data = unserialize(substr($raw, (int) strpos($raw, "\n") + 1), ['allowed_classes' => false]);
        unset($data['entries'][$path]);
        $payload = serialize($data);
        (new Filesystem)->put($file, hash('xxh128', $payload)."\n".$payload);
    }

    private function storedFile(): string
    {
        $files = (new Filesystem)->glob($this->cache()->absoluteDirectory().'/*.cache');

        $this->assertNotEmpty($files);

        return $files[0];
    }

    public function test_a_cache_that_cannot_be_written_still_answers(): void
    {
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath, '/proc/architecture-kit-should-not-be-writable');
        $loader = new ProjectGraphLoader(new Filesystem, $this->tempPath, new AuditScope, $cache);

        $this->assertSame($this->describe($this->loader(cached: false)->load()), $this->describe($loader->load()));
    }

    private function loader(bool $cached = true, array $configuration = []): ProjectGraphLoader
    {
        return new ProjectGraphLoader(
            new Filesystem,
            $this->tempPath,
            new AuditScope,
            $cached ? $this->cache() : null,
            $configuration,
        );
    }

    private function cache(): ProjectGraphCache
    {
        return new ProjectGraphCache(new Filesystem, $this->tempPath);
    }

    private function countingFiles(): Filesystem
    {
        return new class extends Filesystem
        {
            /** @var array<int, string> */
            public array $read = [];

            public function get($path, $lock = false)
            {
                if (str_ends_with((string) $path, '.php')) {
                    $this->read[] = ltrim(str_replace(sys_get_temp_dir(), '', (string) $path), '/');
                    $this->read = array_map(
                        static fn (string $entry): string => (string) preg_replace('#^architecture-kit-[^/]+/#', '', $entry),
                        $this->read,
                    );
                }

                return parent::get($path, $lock);
            }
        };
    }

    private function describe(ProjectGraphSnapshot $graph): string
    {
        $symbols = array_map(
            static fn (ProjectSymbol $symbol): string => implode('|', [$symbol->name, $symbol->path, $symbol->line, $symbol->kind, $symbol->role]),
            $graph->symbols,
        );
        $edges = array_map(
            static fn (DependencyEdge $edge): string => implode('|', [$edge->from, $edge->to, $edge->path, $edge->line, $edge->kind, $edge->strong ? '1' : '0']),
            $graph->edges,
        );

        return implode("\n", [...$symbols, '--', ...$edges]);
    }

    private function writeClass(string $path, string $namespace, string $class, ?string $dependency = null): void
    {
        $use = $dependency === null ? '' : "use {$dependency};\n";
        $body = $dependency === null
            ? "    public function handle(): void\n    {\n    }"
            : '    public function handle('.substr((string) strrchr($dependency, '\\'), 1)." \$subject): void\n    {\n    }";

        $this->writeFile($path, "<?php\n\nnamespace {$namespace};\n\n{$use}\nfinal class {$class}\n{\n{$body}\n}\n");
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }

    /** A rewrite within the same second keeps the size, so the stat has to be moved. */
    private function touchFile(string $path): void
    {
        touch($this->tempPath.'/'.$path, time() + 10);
        clearstatcache(true, $this->tempPath.'/'.$path);
    }
}
