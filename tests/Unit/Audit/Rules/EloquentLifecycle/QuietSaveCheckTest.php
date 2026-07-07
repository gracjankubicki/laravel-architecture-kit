<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class QuietSaveCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_on_quiet_saves_outside_tests(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class StoreDocument
{
    public function handle(Document $document): void
    {
        $document->saveQuietly();
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Actions/StoreDocument.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'saveQuietly'), 'Quiet saves/withoutEvents suggest observers carry behavior');
    }

    public function test_it_ignores_quiet_saves_inside_tests(): void
    {
        $findings = $this->eloquentLifecycleFindings('tests/Feature/StoreDocumentTest.php', <<<'PHP'
<?php

declare(strict_types=1);

final class StoreDocumentTest
{
    public function test_it_stores(): void
    {
        $document->saveQuietly();
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Quiet saves/withoutEvents suggest observers carry behavior');
    }
}
