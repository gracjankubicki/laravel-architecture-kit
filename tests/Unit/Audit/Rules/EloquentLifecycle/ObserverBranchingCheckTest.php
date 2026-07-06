<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class ObserverBranchingCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_business_if_branching(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function saved(Document $document): void
    {
        if ($document->status === 'ready') {
            event(new DocumentReady());
        }
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, 'if ($document->status'), 'Business branching does not belong in observer methods');
    }

    public function test_it_reports_loop_switch_and_match_orchestration(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function saved(Document $document): void
    {
        foreach ($document->items as $item) {
            $this->handle($item);
        }

        switch ($document->status) {
            default:
                break;
        }

        match ($document->status) {
            default => null,
        };
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'foreach'), 'Observer methods should not orchestrate loops or switch logic');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'switch'), 'Observer methods should not orchestrate loops or switch logic');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'match'), 'Observer methods should not contain match-based orchestration');
    }

    public function test_it_allows_technical_lifecycle_conditions(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function saved(Document $document): void
    {
        if ($document->isDirty('status') || $document->wasRecentlyCreated !== null) {
            event(new DocumentChanged());
        }
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Business branching does not belong in observer methods');
    }
}
