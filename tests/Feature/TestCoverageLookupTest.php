<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Context\TestCoverageLookup;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class TestCoverageLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeFile('app/Actions/SendInvoice.php', <<<'PHP'
<?php

namespace App\Actions;

final readonly class SendInvoice
{
    public function handle(): void
    {
    }
}
PHP);
    }

    public function test_a_test_using_the_symbol_is_reported_as_direct_coverage(): void
    {
        $this->writeTest('tests/Feature/SendInvoiceTest.php', 'App\\Actions\\SendInvoice');

        $coverage = $this->lookup('App\\Actions\\SendInvoice');

        $this->assertSame([['path' => 'tests/Feature/SendInvoiceTest.php', 'coverage' => 'direct', 'via' => null]], $coverage);
    }

    public function test_a_test_reaching_the_symbol_through_another_class_is_reported_as_indirect(): void
    {
        // Stopping at direct edges would call this element untested even though the suite
        // exercises it on every run.
        $this->writeFile('app/Services/BillingService.php', <<<'PHP'
<?php

namespace App\Services;

use App\Actions\SendInvoice;

final readonly class BillingService
{
    public function __construct(private SendInvoice $action)
    {
    }
}
PHP);
        $this->writeTest('tests/Feature/BillingTest.php', 'App\\Services\\BillingService');

        $coverage = $this->lookup('App\\Actions\\SendInvoice');

        $this->assertCount(1, $coverage);
        $this->assertSame('indirect', $coverage[0]['coverage']);
        $this->assertSame('App\\Services\\BillingService', $coverage[0]['via']);
    }

    public function test_direct_coverage_wins_over_indirect_for_the_same_file(): void
    {
        $this->writeFile('app/Services/BillingService.php', <<<'PHP'
<?php

namespace App\Services;

use App\Actions\SendInvoice;

final readonly class BillingService
{
    public function __construct(private SendInvoice $action)
    {
    }
}
PHP);
        $this->writeFile('tests/Feature/BothTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use App\Actions\SendInvoice;
use App\Services\BillingService;
use PHPUnit\Framework\TestCase;

final class BothTest extends TestCase
{
    public function test_it_works(): void
    {
        $this->assertNotNull(new SendInvoice);
        $this->assertNotNull(new BillingService(new SendInvoice));
    }
}
PHP);

        $coverage = $this->lookup('App\\Actions\\SendInvoice');

        $this->assertCount(1, $coverage);
        $this->assertSame('direct', $coverage[0]['coverage']);
    }

    public function test_a_symbol_no_test_reaches_returns_nothing(): void
    {
        $this->writeTest('tests/Feature/UnrelatedTest.php', 'App\\Actions\\Other');

        $this->assertSame([], $this->lookup('App\\Actions\\SendInvoice'));
    }

    public function test_a_project_without_a_test_directory_returns_nothing(): void
    {
        $this->assertSame([], $this->lookup('App\\Actions\\SendInvoice'));
    }

    public function test_a_name_that_only_appears_in_a_comment_is_not_coverage(): void
    {
        // The text filter is deliberately generous; the parser is what decides. A file
        // that merely mentions the name contributes no edge.
        $this->writeFile('tests/Feature/MentionTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Related to SendInvoice, but does not use it.
 */
final class MentionTest extends TestCase
{
    public function test_it_works(): void
    {
        $this->assertTrue(true);
    }
}
PHP);

        $this->assertSame([], $this->lookup('App\\Actions\\SendInvoice'));
    }

    public function test_a_symbol_whose_short_name_is_a_substring_of_another_is_not_confused(): void
    {
        $this->writeFile('app/Actions/SendInvoiceReminder.php', <<<'PHP'
<?php

namespace App\Actions;

final readonly class SendInvoiceReminder
{
    public function handle(): void
    {
    }
}
PHP);
        $this->writeTest('tests/Feature/ReminderTest.php', 'App\\Actions\\SendInvoiceReminder');

        // The filter matches both files by text, but only the parsed edge counts.
        $this->assertSame([], $this->lookup('App\\Actions\\SendInvoice'));
        $this->assertCount(1, $this->lookup('App\\Actions\\SendInvoiceReminder'));
    }

    public function test_a_reference_written_in_another_case_still_counts(): void
    {
        // PHP class names are case-insensitive and the graph compares them that way, so
        // this builds a real edge. A case-sensitive filter would drop the file before
        // parsing and report the symbol as untested.
        $this->writeFile('tests/Feature/LowercaseTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class LowercaseTest extends TestCase
{
    public function test_it_works(): void
    {
        $this->assertNotNull(new \App\Actions\sendinvoice());
    }
}
PHP);

        $this->assertCount(1, $this->lookup('App\\Actions\\SendInvoice'));
    }

    public function test_a_neighbouring_class_in_the_same_file_is_not_credited(): void
    {
        // Two declarations in one file: only First uses the target. Crediting Second
        // would report SecondTest as covering a symbol it never touches.
        $this->writeFile('app/Services/Pair.php', <<<'PHP'
<?php

namespace App\Services;

use App\Actions\SendInvoice;

final class First
{
    public function __construct(private SendInvoice $action)
    {
    }
}

final class Second
{
}
PHP);
        $this->writeTest('tests/Feature/SecondTest.php', 'App\\Services\\Second');

        $this->assertSame([], $this->lookup('App\\Actions\\SendInvoice'));
    }

    public function test_the_class_that_holds_the_edge_is_still_credited(): void
    {
        $this->writeFile('app/Services/Pair.php', <<<'PHP'
<?php

namespace App\Services;

use App\Actions\SendInvoice;

final class First
{
    public function __construct(private SendInvoice $action)
    {
    }
}

final class Second
{
}
PHP);
        $this->writeTest('tests/Feature/FirstTest.php', 'App\\Services\\First');

        $coverage = $this->lookup('App\\Actions\\SendInvoice');

        $this->assertCount(1, $coverage);
        $this->assertSame('App\\Services\\First', $coverage[0]['via']);
    }

    public function test_an_aliased_import_still_counts(): void
    {
        $this->writeFile('tests/Feature/AliasTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use App\Actions\SendInvoice as Invoicing;
use PHPUnit\Framework\TestCase;

final class AliasTest extends TestCase
{
    public function test_it_works(): void
    {
        $this->assertNotNull(new Invoicing);
    }
}
PHP);

        $this->assertCount(1, $this->lookup('App\\Actions\\SendInvoice'));
    }

    /**
     * @return array<int, array{path: string, coverage: string, via: string|null}>
     */
    private function lookup(string $symbol): array
    {
        return (new TestCoverageLookup(new Filesystem, $this->tempPath))->for($symbol);
    }

    private function writeTest(string $path, string $subject): void
    {
        $short = substr((string) strrchr($subject, '\\'), 1);

        $this->writeFile($path, <<<PHP
<?php

namespace Tests\Feature;

use {$subject};
use PHPUnit\Framework\TestCase;

final class GeneratedTest extends TestCase
{
    public function test_it_works(): void
    {
        \$this->assertNotNull(new {$short});
    }
}
PHP);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
