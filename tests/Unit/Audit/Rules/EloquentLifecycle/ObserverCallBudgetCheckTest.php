<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class ObserverCallBudgetCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_more_than_one_significant_observer_call(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function saved(Document $document): void
    {
        $this->first($document);
        $this->second($document);
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, '$this->second'), 'Observer methods should call exactly one lifecycle handler');
    }

    public function test_it_allows_one_significant_observer_call(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function saved(Document $document): void
    {
        $this->first($document);
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Observer methods should call exactly one lifecycle handler');
    }
}
