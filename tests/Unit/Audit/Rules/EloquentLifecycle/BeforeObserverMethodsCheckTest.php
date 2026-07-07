<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class BeforeObserverMethodsCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_forbidden_before_observer_work(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function creating(Document $document): void
    {
        DB::transaction(fn () => null);
        app(DocumentLifecycle::class);
        dispatch(new DocumentCreated());
        $other->save();
        Document::query();
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, 'DB::transaction'), 'Before-save observers must not use facades');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, 'app('), 'Before-save observers must not resolve collaborators with app()');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, 'dispatch('), 'Before-save observers must not dispatch events or jobs');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, '$other->save'), 'Before-save observers must not write other models');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, 'Document::query'), 'Before-save observers must not create models or build queries');
    }

    public function test_it_reports_mutation_in_deleting_and_restoring_gatekeepers(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function deleting(Document $document): void
    {
        $document->status = 'deleted';
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, '$document->status'), 'deleting/restoring handlers are gatekeepers only');
    }

    public function test_it_allows_current_model_mutation_in_before_save_methods(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function creating(Document $document): void
    {
        $document->status = 'draft';
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Before-save observers must not');
    }
}
