<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ApiResources\ApiResourcesRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\DataObjects\DataObjectsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi\LaravelAiRule;
use PHPUnit\Framework\TestCase;

final class ResolvedNameRulesTest extends TestCase
{
    public function test_api_resources_only_treat_resolved_project_models_as_queries(): void
    {
        $findings = (new ApiResourcesRule)->check(new FileContext('app/Http/Resources/DocumentResource.php', <<<'PHP'
<?php

namespace App\Http\Resources;

use App\Domain\Collection;

final class DocumentResource
{
    public function toArray(): array
    {
        $documents = new Collection;

        $documents->load('items');
        $documents->loadMissing('owner');

        return $documents->where('status', 'ready')->all();
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_data_objects_do_not_treat_same_basename_domain_model_as_eloquent(): void
    {
        $findings = (new DataObjectsRule)->check(new FileContext('app/Data/DocumentPayload.php', <<<'PHP'
<?php

namespace App\Data;

use App\Domain\Model;

final class DocumentPayload extends Model
{
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_data_objects_do_not_treat_arbitrary_receivers_as_eloquent_models(): void
    {
        $findings = (new DataObjectsRule)->check(new FileContext('app/Data/DocumentPayload.php', <<<'PHP'
<?php

namespace App\Data;

final class DocumentPayload
{
    public function normalize(object $payload): void
    {
        $payload->update();
        $payload->delete();
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_laravel_ai_media_rule_ignores_same_basename_domain_classes(): void
    {
        $findings = (new LaravelAiRule)->check(new FileContext('app/Http/Controllers/ImageController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Domain\Image;

final class ImageController
{
    public function store(): void
    {
        Image::create('thumbnail');
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_laravel_ai_reference_does_not_turn_unrelated_prompt_methods_into_ai_calls(): void
    {
        $findings = (new LaravelAiRule)->check(new FileContext('app/Http/Controllers/DialogController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Support\Dialog;
use Laravel\Ai\Contracts\Tool;

final class DialogController
{
    public function show(Dialog $dialog): void
    {
        $dialog->prompt('Continue?');
    }
}
PHP));

        $this->assertSame([], $findings);
    }
}
