<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class ObserverAfterCommitCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_when_after_commit_observer_has_before_methods(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function creating(Document $document): void
    {
        $document->status = 'draft';
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'implements ShouldHandleEventsAfterCommit'), 'Do not delay the whole observer');
    }

    public function test_it_allows_after_commit_observer_without_before_methods(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public bool $afterCommit = true;

    public function updated(Document $document): void
    {
        event(new DocumentUpdated($document));
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Do not delay the whole observer');
    }
}
