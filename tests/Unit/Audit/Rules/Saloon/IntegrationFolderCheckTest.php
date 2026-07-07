<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class IntegrationFolderCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_invalid_integration_folder_classes(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/Bad.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

final class Bad
{
}
PHP);

        $this->assertHasFinding($findings, 'folder-purity', 'error', 1, 'app/Http/Integrations/** must contain Saloon Connectors');
    }

    public function test_it_reports_invalid_integration_dto_shape(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/Dto/AcmeData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme\Dto;

final class AcmeData
{
}
PHP);

        $this->assertHasFinding($findings, 'folder-purity', 'error', 1, 'Integration DTOs under app/Http/Integrations/**/Dto/** must be final readonly');
    }

    public function test_it_reports_model_and_persistence_logic_inside_integrations(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

use App\Models\Document;
use Saloon\Http\Connector;

final class AcmeConnector extends Connector
{
    public function persist(): void
    {
        DB::transaction(fn () => null);
    }
}
PHP;

        $findings = $this->saloonFindings('app/Http/Integrations/Acme/AcmeConnector.php', $contents);

        $this->assertHasFinding($findings, 'folder-purity', 'error', $this->lineOf($contents, 'use App\\Models'), 'Integrations must not depend on Eloquent models');
        $this->assertHasFinding($findings, 'folder-purity', 'error', $this->lineOf($contents, 'DB::transaction'), 'Integrations must not contain business persistence logic');
    }

    public function test_it_allows_saloon_connector_classes_in_integrations(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/AcmeConnector.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

use Saloon\Http\Connector;

final class AcmeConnector extends Connector
{
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'folder-purity', 'app/Http/Integrations/** must contain Saloon Connectors');
    }
}
