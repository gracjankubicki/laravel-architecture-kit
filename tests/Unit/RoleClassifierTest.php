<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoleClassifierTest extends TestCase
{
    #[DataProvider('domainFirstRoles')]
    public function test_it_classifies_domain_first_architecture_segments(string $path, string $role): void
    {
        $this->assertSame($role, (new RoleClassifier)->classify(
            $path,
            pathinfo($path, PATHINFO_FILENAME),
            'class',
        ));
    }

    /** @return array<string, array{string, string}> */
    public static function domainFirstRoles(): array
    {
        return [
            'action' => ['app/Documents/Actions/ApproveDocument.php', 'application'],
            'service' => ['app/Documents/Services/DocumentService.php', 'application'],
            'query' => ['app/Documents/Queries/FindDocument.php', 'application'],
            'job' => ['app/Documents/Jobs/IndexDocument.php', 'application'],
            'listener' => ['app/Documents/Listeners/RecordApproval.php', 'application'],
            'model' => ['app/Documents/Models/Document.php', 'domain'],
            'domain' => ['app/Documents/Domain/Document.php', 'domain'],
            'data' => ['app/Documents/Data/DocumentData.php', 'domain'],
            'value object' => ['app/Documents/ValueObjects/DocumentId.php', 'domain'],
            'enum' => ['app/Documents/Enums/DocumentStatus.php', 'domain'],
            'controller' => ['app/Documents/Http/Controllers/DocumentController.php', 'adapter'],
            'request' => ['app/Documents/Http/Requests/ApproveDocumentRequest.php', 'adapter'],
            'resource' => ['app/Documents/Http/Resources/DocumentResource.php', 'adapter'],
            'integration' => ['app/Documents/Http/Integrations/ArchiveIntegration.php', 'infrastructure'],
            'adapter' => ['app/Documents/Adapters/DocumentGateway.php', 'infrastructure'],
            'infrastructure' => ['app/Documents/Infrastructure/DocumentStore.php', 'infrastructure'],
            'provider' => ['app/Documents/Providers/DocumentServiceProvider.php', 'composition'],
        ];
    }

    public function test_outer_role_segments_take_precedence_over_nested_inner_names(): void
    {
        $classifier = new RoleClassifier;

        $this->assertSame('infrastructure', $classifier->classify(
            'app/Documents/Infrastructure/Actions/RetryDocument.php',
            'RetryDocument',
            'class',
        ));
        $this->assertSame('adapter', $classifier->classify(
            'app/Documents/Http/Controllers/Data/DocumentController.php',
            'DocumentController',
            'class',
        ));
    }
}
