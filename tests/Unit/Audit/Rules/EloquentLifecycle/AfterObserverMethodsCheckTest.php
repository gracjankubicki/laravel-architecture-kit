<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class AfterObserverMethodsCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_forbidden_after_observer_work(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function updated(Document $document): void
    {
        DB::transaction(fn () => null);
        app(DocumentLifecycle::class);
        dispatch(new DocumentJob());
        $other->save();
        Document::query();
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'DB::transaction'), 'After-save observers should dispatch one named event');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'app('), 'After-save observers should not resolve collaborators with app()');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'dispatch('), 'After-save observers should not dispatch jobs directly');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, '$other->save'), 'After-save observers should not write models directly');
        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'Document::query'), 'After-save observers should not create models or build queries directly');
    }

    public function test_it_allows_single_named_event_dispatch(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function updated(Document $document): void
    {
        event(new DocumentUpdated($document));
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'After-save observers should');
    }

    public function test_it_reports_single_job_dispatch_instead_of_treating_it_as_an_event(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

final class DocumentObserver
{
    public function updated(Document $document): void
    {
        ProcessDocument::dispatch($document);
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'ProcessDocument::dispatch'), 'After-save observers should dispatch one named event');
    }

    public function test_it_allows_a_single_static_dispatch_of_an_imported_app_event(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\DocumentUploaded;

final class DocumentObserver
{
    public function updated(Document $document): void
    {
        DocumentUploaded::dispatch($document);
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'After-save observers should');
    }

    public function test_it_does_not_treat_an_app_job_ending_in_event_as_an_event(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessDocumentEvent;

final class DocumentObserver
{
    public function updated(Document $document): void
    {
        ProcessDocumentEvent::dispatch($document);
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Observers/DocumentObserver.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'ProcessDocumentEvent::dispatch'), 'After-save observers should dispatch one named event');
    }
}
