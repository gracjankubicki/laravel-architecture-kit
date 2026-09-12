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

final class MissingTestRuleTest extends TestCase
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

    public function test_an_element_without_any_test_is_reported(): void
    {
        $findings = $this->missingTestFindings($this->audit(MissingTestLevel::Warn));

        $this->assertCount(1, $findings);
        $this->assertSame('app/Actions/SendInvoice.php', $findings[0]->path);
        $this->assertSame('warn', $findings[0]->severity);
        $this->assertStringContainsString('App\\Actions\\SendInvoice', $findings[0]->message);
    }

    public function test_the_finding_names_where_a_test_would_live(): void
    {
        $findings = $this->missingTestFindings($this->audit(MissingTestLevel::Warn));

        $this->assertStringContainsString('tests/Feature/Actions/SendInvoiceTest.php', $findings[0]->message);
    }

    public function test_an_element_a_test_depends_on_is_accepted(): void
    {
        $this->writeTest('tests/Feature/SendInvoiceTest.php', 'App\\Actions\\SendInvoice');

        $this->assertSame([], $this->missingTestFindings($this->audit(MissingTestLevel::Warn)));
    }

    public function test_coverage_reached_through_another_class_counts(): void
    {
        // A test that drives a service which uses the action still exercises the action,
        // so demanding a direct edge would report elements that are in fact covered.
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

        $this->assertSame([], $this->missingTestFindings($this->audit(MissingTestLevel::Warn)));
    }

    public function test_the_severity_follows_the_configured_level(): void
    {
        $findings = $this->missingTestFindings($this->audit(MissingTestLevel::Error));

        $this->assertSame('error', $findings[0]->severity);
        $this->assertSame('E_MISSING_TEST', $findings[0]->code);
    }

    public function test_the_rule_is_silent_while_it_is_off(): void
    {
        // This is the state every existing installation is in after an upgrade.
        $this->assertSame([], $this->missingTestFindings($this->audit(MissingTestLevel::Off)));
    }

    public function test_it_reports_elements_outside_the_conventional_folders(): void
    {
        // The rule reads the graph, so it does not depend on where an architecture keeps
        // its elements.
        $this->writeFile('app/Billing/Internal/Calculator.php', <<<'PHP'
<?php

namespace App\Billing\Internal;

final readonly class Calculator
{
    public function total(): int
    {
        return 0;
    }
}
PHP);

        $paths = array_map(
            static fn (AuditFinding $finding): string => $finding->path,
            $this->missingTestFindings($this->audit(MissingTestLevel::Warn)),
        );

        $this->assertContains('app/Billing/Internal/Calculator.php', $paths);
    }

    public function test_interfaces_traits_and_plain_enums_are_not_reported(): void
    {
        $this->writeFile('app/Contracts/Payable.php', "<?php\n\nnamespace App\\Contracts;\n\ninterface Payable\n{\n    public function pay(): void;\n}\n");
        $this->writeFile('app/Concerns/Billable.php', "<?php\n\nnamespace App\\Concerns;\n\ntrait Billable\n{\n}\n");
        $this->writeFile('app/Enums/Status.php', "<?php\n\nnamespace App\\Enums;\n\nenum Status: string\n{\n    case Draft = 'draft';\n}\n");

        $paths = array_map(
            static fn (AuditFinding $finding): string => $finding->path,
            $this->missingTestFindings($this->audit(MissingTestLevel::Warn)),
        );

        $this->assertNotContains('app/Contracts/Payable.php', $paths);
        $this->assertNotContains('app/Concerns/Billable.php', $paths);
        $this->assertNotContains('app/Enums/Status.php', $paths);
    }

    public function test_an_enum_carrying_behaviour_is_reported(): void
    {
        // Enums are an architecture of their own here, and a method on one is real
        // behaviour; exempting every enum would leave that behaviour unasserted.
        $this->writeFile('app/Enums/Priority.php', <<<'PHP'
<?php

namespace App\Enums;

enum Priority: string
{
    case Low = 'low';
    case High = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
PHP);

        $paths = array_map(
            static fn (AuditFinding $finding): string => $finding->path,
            $this->missingTestFindings($this->audit(MissingTestLevel::Warn)),
        );

        $this->assertContains('app/Enums/Priority.php', $paths);
    }

    public function test_a_classless_pest_test_counts_as_coverage(): void
    {
        // Pest is the default in a new Laravel application and writes tests as top-level
        // calls, so a graph built only from class declarations would call every element
        // untested.
        $this->writeFile('tests/Feature/SendInvoiceTest.php', <<<'PHP'
<?php

use App\Actions\SendInvoice;

it('sends an invoice', function () {
    $action = new SendInvoice();

    expect($action)->not->toBeNull();
});
PHP);

        $this->assertSame([], $this->missingTestFindings($this->audit(MissingTestLevel::Warn)));
    }

    public function test_the_rule_cannot_be_run_against_a_scope_without_tests(): void
    {
        // Asked for the rule with the default scope: the audit has to widen it itself,
        // otherwise the graph holds no test files and every element looks untested.
        $this->writeTest('tests/Feature/SendInvoiceTest.php', 'App\\Actions\\SendInvoice');

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::Actions],
            changedOnly: false,
            scope: AuditScope::default(),
            missingTestLevel: MissingTestLevel::Warn,
        );

        $this->assertSame([], $this->missingTestFindings($result));
    }

    public function test_an_exclusion_cannot_hide_the_tests_the_rule_depends_on(): void
    {
        // Excluding tests/ while the rule is on would report covered classes as untested.
        $this->writeTest('tests/Feature/SendInvoiceTest.php', 'App\\Actions\\SendInvoice');

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::Actions],
            changedOnly: false,
            exclude: ['tests/**'],
            scope: AuditScope::default()->withTests(),
            missingTestLevel: MissingTestLevel::Warn,
        );

        $this->assertSame([], $this->missingTestFindings($result));
    }

    public function test_the_test_file_itself_is_never_reported(): void
    {
        $this->writeTest('tests/Feature/SendInvoiceTest.php', 'App\\Actions\\SendInvoice');

        $paths = array_map(
            static fn (AuditFinding $finding): string => $finding->path,
            $this->missingTestFindings($this->audit(MissingTestLevel::Warn)),
        );

        $this->assertSame([], $paths);
    }

    private function audit(MissingTestLevel $level): ApplicationAuditResult
    {
        $scope = $level->isEnabled() ? AuditScope::default()->withTests() : AuditScope::default();

        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::Actions],
            changedOnly: false,
            scope: $scope,
            missingTestLevel: $level,
        );
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function missingTestFindings(ApplicationAuditResult $result): array
    {
        return array_values(array_filter(
            $result->findings,
            static fn (AuditFinding $finding): bool => $finding->rule === 'missing-test',
        ));
    }

    private function writeTest(string $path, string $subject): void
    {
        $this->writeFile($path, <<<PHP
<?php

namespace Tests\Feature;

use {$subject};
use PHPUnit\Framework\TestCase;

final class GeneratedTest extends TestCase
{
    public function test_it_works(): void
    {
        \$subject = new \\{$subject};

        \$this->assertNotNull(\$subject);
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
