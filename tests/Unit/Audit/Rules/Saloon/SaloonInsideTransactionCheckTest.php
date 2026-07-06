<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class SaloonInsideTransactionCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_when_saloon_send_runs_inside_database_transaction(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class SyncAcme
{
    public function handle($connector): void
    {
        DB::transaction(fn () => $connector->send(new AcmeRequest()));
    }
}
PHP;

        $findings = $this->saloonFindings('app/Actions/SyncAcme.php', $contents);

        $this->assertHasFinding($findings, 'transaction-side-effects', 'warn', $this->lineOf($contents, '->send'), 'Do not send Saloon requests inside an open DB transaction');
    }

    public function test_it_allows_transactions_without_saloon_send(): void
    {
        $findings = $this->saloonFindings('app/Actions/SyncAcme.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class SyncAcme
{
    public function handle(): void
    {
        DB::transaction(fn () => $document->save());
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'transaction-side-effects', 'Do not send Saloon requests inside an open DB transaction');
    }
}
