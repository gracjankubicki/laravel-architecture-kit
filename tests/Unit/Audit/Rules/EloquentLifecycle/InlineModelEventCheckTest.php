<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class InlineModelEventCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_inline_model_lifecycle_closures(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

final class Document
{
    protected static function booted(): void
    {
        static::creating(fn (Document $document) => null);
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Models/Document.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'error', $this->lineOf($contents, 'static::creating'), 'Concrete models must not register inline lifecycle closures');
    }

    public function test_it_allows_dispatches_events_without_inline_closures(): void
    {
        $findings = $this->eloquentLifecycleFindings('app/Models/Document.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

final class Document
{
    protected $dispatchesEvents = [
        'created' => DocumentCreated::class,
    ];
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Concrete models must not register inline lifecycle closures');
    }
}
