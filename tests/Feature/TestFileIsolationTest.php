<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

/**
 * Once test files enter the audit scope, rules written for application code must stay
 * out of them. Five built-in rules gate only on the enabled architecture and never look
 * at the path, and two match any path containing `Payload`, so this cannot be left to
 * each rule to remember.
 */
final class TestFileIsolationTest extends TestCase
{
    public function test_no_application_rule_reports_inside_a_test_file(): void
    {
        // Deliberately full of things the rules reject in application code: a service
        // locator call, a raw HTTP call, a transaction, and a direct model write.
        $this->writeFile('tests/Feature/BillingFlowTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class BillingFlowTest extends TestCase
{
    public function test_it_bills(): void
    {
        $service = app('billing');

        Http::get('https://example.test/ping');

        DB::transaction(function () {
            Invoice::create(['total' => 1]);
        });

        $this->assertNotNull($service);
    }
}
PHP);

        $this->assertSame([], $this->findingsIn($this->audit(), 'tests/'));
    }

    public function test_a_test_named_like_a_payload_is_not_reported(): void
    {
        // ServiceLocatorRule and TestabilityRule match any path containing `Payload`,
        // with no anchor on the application directory.
        $this->writeFile('tests/Unit/PayloadTest.php', <<<'PHP'
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    public function test_it_resolves(): void
    {
        $this->assertNotNull(app('payload'));
    }
}
PHP);

        $this->assertSame([], $this->findingsIn($this->audit(), 'tests/'));
    }

    public function test_a_test_directory_nested_in_the_application_is_also_protected(): void
    {
        // A project may keep tests beside the code they cover; role classification
        // already calls these tests, so the rules must agree.
        $this->writeFile('app/Domain/Tests/PayloadTest.php', <<<'PHP'
<?php

namespace App\Domain\Tests;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    public function test_it_calls_out(): void
    {
        Http::get('https://example.test/ping');

        $this->assertNotNull(app('payload'));
    }
}
PHP);

        $this->assertSame([], $this->findingsIn($this->audit(), 'app/Domain/Tests/'));
    }

    public function test_the_same_code_is_still_reported_in_application_files(): void
    {
        // Proves the isolation is about the location, not about the rules having stopped
        // working.
        $this->writeFile('app/Http/Controllers/InvoiceController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class InvoiceController
{
    public function store(): void
    {
        DB::transaction(function () {
            Invoice::create(['total' => 1]);
        });
    }
}
PHP);

        $this->assertNotSame([], $this->findingsIn($this->audit(), 'app/'));
    }

    private function audit(): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [
                Architecture::ThinControllers,
                Architecture::Actions,
                Architecture::Services,
                Architecture::Saloon,
                Architecture::ModernPhp85,
                Architecture::EloquentLifecycle,
                Architecture::Enums,
            ],
            changedOnly: false,
            scope: AuditScope::default()->withTests(),
            missingTestLevel: MissingTestLevel::Warn,
        );
    }

    /**
     * @return array<int, string>
     */
    private function findingsIn(ApplicationAuditResult $result, string $prefix): array
    {
        return array_values(array_map(
            static fn (AuditFinding $finding): string => $finding->rule.' '.$finding->path.':'.$finding->line,
            array_filter(
                $result->findings,
                static fn (AuditFinding $finding): bool => str_starts_with($finding->path, $prefix),
            ),
        ));
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
