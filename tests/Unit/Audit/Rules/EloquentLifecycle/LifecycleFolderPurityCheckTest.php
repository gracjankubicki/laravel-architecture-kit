<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class LifecycleFolderPurityCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_non_handler_classes_inside_lifecycle_folders(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Lifecycle/Documents/DocumentStatusData.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Lifecycle\Documents;

final readonly class DocumentStatusData
{
}
PHP);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', 1, 'Lifecycle folders must contain final lifecycle handlers only');
    }

    public function test_it_allows_final_lifecycle_handlers_with_one_handle_method(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Lifecycle/Documents/ApplyDocumentLifecycle.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Lifecycle\Documents;

final class ApplyDocumentLifecycle
{
    public function handle(Document $document): void
    {
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Lifecycle folders must contain final lifecycle handlers only');
    }

    public function test_it_keeps_current_behavior_for_unparseable_non_lifecycle_files(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Observers/BrokenObserver.php', '<?php final class Broken {');

        $this->assertSame([], $findings);
    }
}
