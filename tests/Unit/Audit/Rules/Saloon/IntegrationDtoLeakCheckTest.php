<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class IntegrationDtoLeakCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_integration_dto_leaks_outside_use_case_boundaries(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Integrations\Acme\Dto\AcmeData;

final class AcmeController
{
    public function __invoke(AcmeData $data): void
    {
    }
}
PHP;

        $findings = $this->saloonFindings('app/Http/Controllers/AcmeController.php', $contents);

        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'use App\\Http\\Integrations'), 'Integration DTOs must not leak outside Actions');
    }

    public function test_it_allows_integration_dtos_in_actions(): void
    {
        $findings = $this->saloonFindings('app/Actions/SyncAcme.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Http\Integrations\Acme\Dto\AcmeData;

final class SyncAcme
{
    public function handle(AcmeData $data): void
    {
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'saloon', 'Integration DTOs must not leak outside Actions');
    }
}
