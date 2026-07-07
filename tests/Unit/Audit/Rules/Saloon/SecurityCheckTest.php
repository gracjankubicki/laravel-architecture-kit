<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class SecurityCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_when_authenticators_are_serialized(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

final class StoreToken
{
    public function handle(): void
    {
        serialize(new AcmeAuthenticator());
    }
}
PHP;

        $findings = $this->saloonFindings('app/Http/Integrations/Acme/StoreToken.php', $contents);

        $this->assertHasFinding($findings, 'saloon', 'warn', $this->lineOf($contents, 'serialize('), 'Do not serialize/unserialize Saloon authenticators');
    }

    public function test_it_allows_serializing_plain_values(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/StoreToken.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

final class StoreToken
{
    public function handle(): void
    {
        serialize(['token' => 'redacted']);
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'saloon', 'Do not serialize/unserialize Saloon authenticators');
    }
}
