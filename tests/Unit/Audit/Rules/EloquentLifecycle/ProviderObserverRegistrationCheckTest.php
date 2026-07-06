<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\EloquentLifecycle;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class ProviderObserverRegistrationCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_on_hidden_provider_observer_registration(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Document;

final class EventServiceProvider
{
    public function boot(): void
    {
        Document::observe(DocumentObserver::class);
    }
}
PHP;

        $findings = $this->eloquentLifecycleFindings('app/Providers/EventServiceProvider.php', $contents);

        $this->assertHasFinding($findings, 'eloquent-lifecycle', 'warn', $this->lineOf($contents, 'Document::observe'), 'Register observers with #[ObservedBy(...)]');
    }

    public function test_it_allows_provider_registration_when_model_has_observed_by_attribute(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Models');
        $files->put($this->tempPath.'/app/Models/Document.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([DocumentObserver::class])]
final class Document
{
}
PHP);

        $findings = $this->eloquentLifecycleFindings('app/Providers/EventServiceProvider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Document;

final class EventServiceProvider
{
    public function boot(): void
    {
        Document::observe(DocumentObserver::class);
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'eloquent-lifecycle', 'Register observers with #[ObservedBy(...)]');
    }
}
