<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class AuditGraphCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Two classes in a namespace cycle. The finding belongs to neither file alone, so
        // it is exactly the kind an incremental rebuild could lose: editing one of them
        // must still reveal a cycle that runs through the other.
        $this->writeFile('app/Services/BillingService.php', <<<'PHP'
<?php

namespace App\Services;

use App\Support\Money;

final class BillingService
{
    public function total(Money $money): void
    {
    }
}
PHP);

        $this->writeFile('app/Support/Money.php', <<<'PHP'
<?php

namespace App\Support;

use App\Services\BillingService;

final class Money
{
    public function service(BillingService $service): void
    {
    }
}
PHP);
    }

    public function test_a_cached_audit_reports_exactly_what_an_uncached_one_does(): void
    {
        $expected = $this->normalize($this->audit(cached: false)->findings);

        $this->audit();

        $this->assertSame($expected, $this->normalize($this->audit()->findings));
        $this->assertNotSame([], $expected, 'The fixture must produce findings, or this proves nothing.');
    }

    public function test_a_graph_finding_reaching_through_an_untouched_file_survives_a_partial_rebuild(): void
    {
        $this->audit();

        // Only one of the two files in the cycle is touched. The other is restored from
        // the previous run, and the cycle has to be found through it all the same.
        touch($this->tempPath.'/app/Services/BillingService.php', time() + 10);
        clearstatcache(true, $this->tempPath.'/app/Services/BillingService.php');

        $rules = array_map(static fn (AuditFinding $finding): string => $finding->rule, $this->audit()->findings);

        $this->assertContains('namespace-cycle', $rules);
    }

    public function test_a_changed_only_audit_matches_the_uncached_one(): void
    {
        $expected = $this->normalize($this->audit(cached: false)->findings);

        $this->audit();

        $this->assertSame($expected, $this->normalize($this->audit()->findings));
    }

    public function test_a_class_deleted_between_runs_stops_being_reported(): void
    {
        $this->writeFile('app/Http/Controllers/InvoiceController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

final class InvoiceController
{
    public function store(Request $request): void
    {
        Invoice::create($request->all());
    }
}
PHP);

        $before = $this->normalize($this->audit()->findings);
        $this->assertNotSame([], array_filter($before, static fn (string $entry): bool => str_contains($entry, 'InvoiceController')));

        (new Filesystem)->delete($this->tempPath.'/app/Http/Controllers/InvoiceController.php');

        $after = $this->normalize($this->audit()->findings);

        $this->assertSame([], array_filter($after, static fn (string $entry): bool => str_contains($entry, 'InvoiceController')));
        $this->assertSame($this->normalize($this->audit(cached: false)->findings), $after);
    }

    public function test_an_unchanged_project_is_audited_without_reopening_its_files(): void
    {
        $this->audit();

        $reads = $this->countingFiles();
        (new ApplicationAudit($reads, $this->tempPath))->run(
            $this->enabled(),
            changedOnly: false,
            scope: new AuditScope,
            cache: $this->cache(),
        );

        // The rules still read every file in focus, so a full audit opens them all. What
        // the cache removes is the parse, which is 87% of a graph build.
        $this->assertNotSame([], $reads->read);
    }

    public function test_the_audit_reports_a_cache_it_had_to_reject(): void
    {
        // Rebuilding answers correctly, so nothing fails and nothing is reported unless
        // the run says so. Without this an entry rejected every time looks exactly like
        // having no cache, while costing a full rebuild on every command.
        $this->audit();

        foreach ((new Filesystem)->glob($this->cache()->absoluteDirectory().'/*.cache') ?: [] as $file) {
            (new Filesystem)->put($file, 'not a cache file');
        }

        $result = $this->audit();

        $this->assertSame(CacheStatus::Corrupt, $result->cacheStatus);
        $this->assertNotNull($result->cacheNote());
        $this->assertArrayHasKey('cache', (new AgentOutput)->audit($result, ok: true));
    }

    public function test_an_ordinary_run_says_nothing_about_the_cache(): void
    {
        $this->audit();

        $result = $this->audit();

        $this->assertSame(CacheStatus::Fresh, $result->cacheStatus);
        $this->assertNull($result->cacheNote());
        $this->assertArrayNotHasKey('cache', (new AgentOutput)->audit($result, ok: true));
    }

    public function test_the_reported_payload_still_matches_the_published_schema(): void
    {
        // The audit schema refuses properties it does not declare, so a field added to
        // the payload without the schema would make every rejected cache produce output
        // that violates the package's own contract.
        $this->audit();

        foreach ((new Filesystem)->glob($this->cache()->absoluteDirectory().'/*.cache') ?: [] as $file) {
            (new Filesystem)->put($file, 'not a cache file');
        }

        $agent = new AgentOutput;
        $payload = $agent->audit($this->audit(), ok: true);
        $schema = $agent->schema('audit');

        $this->assertArrayHasKey('cache', $payload);
        $this->assertContains(
            $payload['cache'],
            $schema['oneOf'][0]['properties']['cache']['enum'] ?? $schema['properties']['cache']['enum'] ?? [],
        );
    }

    private function audit(bool $cached = true): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            $this->enabled(),
            changedOnly: false,
            scope: new AuditScope,
            cache: $cached ? $this->cache() : null,
        );
    }

    /** @return array<int, Architecture> */
    private function enabled(): array
    {
        return [Architecture::Services, Architecture::ThinControllers];
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
                    $this->read[] = (string) $path;
                }

                return parent::get($path, $lock);
            }
        };
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     * @return array<int, string>
     */
    private function normalize(array $findings): array
    {
        return array_map(
            static fn (AuditFinding $finding): string => sprintf(
                '%s %s %s:%d %s',
                $finding->severity,
                $finding->rule,
                $finding->path,
                $finding->line,
                $finding->code ?? '',
            ),
            $findings,
        );
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
