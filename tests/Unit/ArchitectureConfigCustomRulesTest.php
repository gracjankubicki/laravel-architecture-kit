<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ArchitectureConfigCustomRulesTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-config-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath.'/config');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_flat_rules_are_global_rules(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        App\Architecture\Rules\GlobalProjectRule::class,
    ],
];
PHP);

        $rules = $this->config()->customRuleSet();

        $this->assertSame(['App\Architecture\Rules\GlobalProjectRule'], $rules->globalRules());
        $this->assertSame([], $rules->scopedRules());
    }

    public function test_keyed_rules_are_scoped_to_architecture_slugs(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        'actions' => [
            App\Architecture\Rules\NoLegacyActionBaseClass::class,
        ],
    ],
];
PHP);

        $rules = $this->config()->customRuleSet();

        $this->assertSame([], $rules->globalRules());
        $this->assertSame([
            'actions' => ['App\Architecture\Rules\NoLegacyActionBaseClass'],
        ], $rules->scopedRules());
        $this->assertSame(['App\Architecture\Rules\NoLegacyActionBaseClass'], $rules->rulesFor([Architecture::Actions]));
        $this->assertSame([], $rules->rulesFor([Architecture::FormRequests]));
    }

    public function test_mixed_rules_preserve_global_and_scoped_rules(): void
    {
        $this->writeCustomArchitecture('billing-workflows');
        $this->writeConfig(<<<'PHP'
<?php

return [
    'enabled' => [
        'billing-workflows',
    ],
    'rules' => [
        App\Architecture\Rules\GlobalProjectRule::class,
        'billing-workflows' => [
            App\Architecture\BillingWorkflows\Rules\NoInvoiceTransitionInController::class,
        ],
    ],
];
PHP);

        $rules = $this->config()->customRuleSet();

        $this->assertSame(['App\Architecture\Rules\GlobalProjectRule'], $rules->globalRules());
        $this->assertSame(['App\Architecture\Rules\GlobalProjectRule'], $this->config()->customRules());
        $this->assertSame([
            'billing-workflows' => ['App\Architecture\BillingWorkflows\Rules\NoInvoiceTransitionInController'],
        ], $rules->scopedRules());
        $this->assertSame([
            'App\Architecture\Rules\GlobalProjectRule',
            'App\Architecture\BillingWorkflows\Rules\NoInvoiceTransitionInController',
        ], $rules->rulesFor(['billing-workflows']));
    }

    public function test_invalid_scoped_rule_slug_fails_clearly(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        'BillingWorkflows' => [
            App\Architecture\Rules\RuleA::class,
        ],
    ],
];
PHP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rules key [BillingWorkflows] must be an architecture slug in kebab-case');

        $this->config()->customRuleSet();
    }

    public function test_keyed_rule_value_must_be_an_array(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        'actions' => App\Architecture\Rules\RuleA::class,
    ],
];
PHP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rules.actions must be an array of class-string values');

        $this->config()->customRuleSet();
    }

    public function test_unknown_scoped_architecture_slug_fails_clearly(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'rules' => [
        'billing-workfows' => [
            App\Architecture\Rules\RuleA::class,
        ],
    ],
];
PHP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rules.billing-workfows references an unknown architecture');

        $this->config()->customRuleSet();
    }

    private function config(): ArchitectureConfig
    {
        return new ArchitectureConfig($this->tempPath.'/config/architectures.php', new Filesystem);
    }

    private function writeConfig(string $contents): void
    {
        (new Filesystem)->put($this->tempPath.'/config/architectures.php', $contents);
    }

    private function writeCustomArchitecture(string $slug): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/'.$slug);
        $files->put($this->tempPath.'/.architecture-kit/architectures/'.$slug.'/guideline.md', 'Custom rules.');
    }
}
