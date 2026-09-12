<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use PHPUnit\Framework\TestCase;

final class AuditScopeTest extends TestCase
{
    public function test_the_default_scope_is_the_application_directory_only(): void
    {
        $scope = AuditScope::default();

        $this->assertSame(['app'], $scope->directories);
        $this->assertFalse($scope->includesTests());
    }

    public function test_the_application_directory_cannot_be_dropped(): void
    {
        // Dropping it would silently disable every rule the package exists to enforce.
        $scope = new AuditScope(['routes']);

        $this->assertSame(['app', 'routes'], $scope->directories);
    }

    public function test_directories_are_normalized_and_deduplicated(): void
    {
        $scope = new AuditScope(['app', '/routes/', 'routes', '', '  ']);

        $this->assertSame(['app', 'routes'], $scope->directories);
    }

    public function test_it_covers_only_paths_inside_its_directories(): void
    {
        $scope = new AuditScope(['app', 'routes']);

        $this->assertTrue($scope->covers('app/Actions/SendInvoice.php'));
        $this->assertTrue($scope->covers('routes/api.php'));
        $this->assertFalse($scope->covers('tests/Feature/InvoiceTest.php'));
        $this->assertFalse($scope->covers('database/migrations/create.php'));
    }

    public function test_a_directory_name_is_not_matched_as_a_prefix_of_another(): void
    {
        $scope = new AuditScope(['app']);

        $this->assertFalse($scope->covers('application/Thing.php'));
    }

    public function test_the_test_directory_is_added_on_demand(): void
    {
        $scope = AuditScope::default()->withTests();

        $this->assertSame(['app', 'tests'], $scope->directories);
        $this->assertTrue($scope->includesTests());
        $this->assertTrue($scope->covers('tests/Feature/InvoiceTest.php'));
    }

    public function test_it_recognizes_a_test_path_regardless_of_scope(): void
    {
        $scope = AuditScope::default();

        $this->assertTrue($scope->isTestPath('tests/Feature/InvoiceTest.php'));
        $this->assertFalse($scope->isTestPath('app/Actions/SendInvoice.php'));
    }

    public function test_a_test_directory_nested_in_the_application_is_recognized(): void
    {
        // Role classification already treats app/Tests as tests, and a second answer
        // here would let application rules run inside those files.
        $scope = AuditScope::default();

        $this->assertTrue($scope->isTestPath('app/Domain/Tests/InvoiceTest.php'));
    }

    public function test_a_directory_that_escapes_the_project_is_rejected(): void
    {
        $scope = new AuditScope(['app', '../outside', 'routes/../../elsewhere']);

        $this->assertSame(['app'], $scope->directories);
    }
}
