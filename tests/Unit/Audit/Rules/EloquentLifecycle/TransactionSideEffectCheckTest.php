<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class TransactionSideEffectCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_on_side_effects_inside_database_transactions(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class StoreDocument
{
    public function handle(): void
    {
        DB::transaction(fn () => event(new DocumentStored()));
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Actions/StoreDocument.php', $contents);

        $this->assertHasFinding($findings, 'transaction-side-effects', 'warn', $this->lineOf($contents, 'event(new DocumentStored'), 'Do not dispatch events, jobs, notifications, mail, HTTP, or external API calls inside an open DB transaction');
    }

    public function test_it_allows_transactions_without_side_effects(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Actions/StoreDocument.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class StoreDocument
{
    public function handle(): void
    {
        DB::transaction(fn () => $document->save());
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'transaction-side-effects', 'Do not dispatch events, jobs, notifications, mail, HTTP, or external API calls inside an open DB transaction');
    }
}
