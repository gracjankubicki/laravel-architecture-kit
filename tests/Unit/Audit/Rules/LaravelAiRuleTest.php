<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi\LaravelAiRule;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class LaravelAiRuleTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-ai-rule-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->path.'/app');
        (new Filesystem)->put($this->path.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_it_detects_all_agent_entry_points_on_a_confirmed_typed_receiver(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Laravel\Ai\Contracts\Agent;

final class ReportController
{
    public function __construct(private Agent $agent) {}

    public function show(Agent $parameter): void
    {
        $parameter->prompt('one');
        $parameter->stream('two');
        $this->agent->queue('three');
        $this->agent->broadcast('four', []);
        $this->agent->broadcastNow('five', []);
        $this->agent->broadcastOnQueue('six', []);
    }
}
PHP));

        $this->assertCount(6, $findings);
        $this->assertSame([13, 14, 15, 16, 17, 18], array_column($findings, 'line'));
        $this->assertSame(['error'], array_values(array_unique(array_column($findings, 'severity'))));
    }

    public function test_it_detects_new_make_assignment_alias_and_fluent_calls_without_an_agent_suffix(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->path.'/app/Ai/Agents');
        $files->put($this->path.'/app/Ai/Agents/ReportProcessor.php', <<<'PHP'
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Promptable;

final class ReportProcessor
{
    use Promptable;
}
PHP);

        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ReportProcessor as ReportWriter;

final class ReportController
{
    public function show(): void
    {
        (new ReportWriter)->prompt('new');
        ReportWriter::make()->stream('make');
        $agent = new ReportWriter;
        $agent->queue('assigned');
        ReportWriter::make()->forUser($this)->broadcastOnQueue('fluent', []);
    }
}
PHP));

        $this->assertCount(4, $findings);
        $this->assertSame([11, 12, 14, 15], array_column($findings, 'line'));
    }

    public function test_it_detects_a_class_declared_in_the_checked_file_from_its_sdk_contract(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Laravel\Ai\Contracts\Agent;

final class ReportProcessor implements Agent {}

final class ReportController
{
    public function show(): void
    {
        (new ReportProcessor)->prompt('review');
    }
}
PHP));

        $this->assertCount(1, $findings);
    }

    public function test_it_ignores_a_same_named_class_without_an_sdk_relationship(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

final class ReportAgent
{
    public function prompt(string $prompt): void {}
}

final class ReportController
{
    public function show(ReportAgent $agent): void
    {
        $agent->prompt('review');
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_it_allows_confirmed_agent_calls_behind_the_ai_boundary(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Ai/Gateways/ReportGateway.php', <<<'PHP'
<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Contracts\Agent;

final class ReportGateway
{
    public function run(Agent $agent): void
    {
        $agent->prompt('review');
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_it_guards_all_verified_file_write_operations_outside_the_ai_boundary(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Services/FileService.php', <<<'PHP'
<?php

namespace App\Services;

use Laravel\Ai\Files;

Files::put('a.txt', 'contents');
Files::putFromPath('/tmp/a.txt');
Files::putFromStorage('documents/a.txt');
PHP));

        $this->assertCount(3, $findings);
        $this->assertSame([7, 8, 9], array_column($findings, 'line'));
    }

    public function test_literal_provider_and_model_warning_requires_a_confirmed_sdk_receiver(): void
    {
        $unrelated = $this->rule()->check(new FileContext('app/Services/DialogService.php', <<<'PHP'
<?php

namespace App\Services;

final class Dialog
{
    public function prompt(string $text, string $provider, string $model): void {}
}

(new Dialog)->prompt('hello', provider: 'internal', model: 'rules');
PHP));
        $confirmed = $this->rule()->check(new FileContext('app/Ai/Gateways/ReportGateway.php', <<<'PHP'
<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Contracts\Agent;

final class ReportGateway
{
    public function run(Agent $agent): void
    {
        $agent->prompt('hello', provider: 'openai', model: 'gpt');
    }
}
PHP));

        $this->assertSame([], $unrelated);
        $this->assertCount(1, $confirmed);
        $this->assertSame('warn', $confirmed[0]->severity);
        $this->assertStringContainsString('raw provider/model', $confirmed[0]->message);
    }

    public function test_anonymous_tool_detection_resolves_the_real_contract_and_alias(): void
    {
        $ownTool = $this->rule()->check(new FileContext('app/Ai/Tools/OwnToolFactory.php', <<<'PHP'
<?php

namespace App\Ai\Tools;

interface Tool {}

$tool = new class implements Tool {};
PHP));
        $sdkTool = $this->rule()->check(new FileContext('app/Ai/Tools/SdkToolFactory.php', <<<'PHP'
<?php

namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool as AiTool;

$tool = new class implements AiTool {};
PHP));

        $this->assertSame([], $ownTool);
        $this->assertCount(1, $sdkTool);
        $this->assertStringContainsString('anonymous classes', $sdkTool[0]->message);
    }

    public function test_dynamic_and_ambiguous_calls_warn_only_after_an_sdk_relationship_is_known(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Ai/Gateways/ReportGateway.php', <<<'PHP'
<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Contracts\Agent;

final class Dialog {}

final class ReportGateway
{
    public function run(Agent $agent, Dialog $dialog, string $method, bool $useAi): void
    {
        $agent->{$method}('dynamic');
        $runner = $useAi ? $agent : $dialog;
        $runner->prompt('ambiguous');
        $dialog->{$method}('unrelated');
    }
}
PHP));

        $this->assertCount(2, $findings);
        $this->assertSame(['W_LARAVEL_AI_ANALYSIS_INCOMPLETE'], array_values(array_unique(array_column($findings, 'code'))));
        $this->assertSame(['warn'], array_values(array_unique(array_column($findings, 'severity'))));
    }

    public function test_reassignment_does_not_keep_a_stale_agent_type(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/DialogController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Laravel\Ai\Contracts\Agent;

final class Dialog {}

final class DialogController
{
    public function show(Agent $runner): void
    {
        $runner = new Dialog;
        $runner->prompt('not AI');
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_a_laravel_ai_namespace_prefix_is_not_enough_to_confirm_a_static_agent_call(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/DialogController.php', <<<'PHP'
<?php

namespace Laravel\Ai {
    final class Dialog {}
}

namespace App\Http\Controllers {
    \Laravel\Ai\Dialog::prompt('not an agent');
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_properties_are_resolved_per_class_in_a_multi_class_file(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/DialogController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Laravel\Ai\Contracts\Agent;

final class AgentController
{
    public function __construct(private Agent $runner) {}
}

final class Dialog {}

final class DialogController
{
    public function __construct(private Dialog $runner) {}

    public function show(): void
    {
        $this->runner->prompt('not AI');
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_closure_and_arrow_function_parameters_do_not_inherit_an_outer_agent_type(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/DialogController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Laravel\Ai\Contracts\Agent;

final class Dialog {}

final class DialogController
{
    public function show(Agent $runner): void
    {
        $closure = function (Dialog $runner): void {
            $runner->prompt('not AI');
        };
        $arrow = fn (Dialog $runner) => $runner->prompt('not AI');
        $captured = fn () => $runner->prompt('AI');
    }
}
PHP));

        $this->assertCount(1, $findings);
        $this->assertSame(17, $findings[0]->line);
        $this->assertSame('error', $findings[0]->severity);
    }

    public function test_a_union_with_an_agent_and_an_unrelated_type_is_incomplete_not_confirmed(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/DialogController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Laravel\Ai\Contracts\Agent;

final class Dialog {}

final class DialogController
{
    public function show(Agent|Dialog $runner): void
    {
        $runner->prompt('ambiguous');
    }
}
PHP));

        $this->assertCount(1, $findings);
        $this->assertSame('W_LARAVEL_AI_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertSame('warn', $findings[0]->severity);
    }

    public function test_psr4_array_mappings_are_searched_until_the_agent_source_is_found(): void
    {
        $files = new Filesystem;
        $files->put($this->path.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => ['missing/', 'app/']]],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->path.'/app/Ai/Agents');
        $files->put($this->path.'/app/Ai/Agents/ReportProcessor.php', <<<'PHP'
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Promptable;

final class ReportProcessor
{
    use Promptable;
}
PHP);

        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ReportProcessor;

(new ReportProcessor)->prompt('review');
PHP));

        $this->assertCount(1, $findings);
        $this->assertSame('error', $findings[0]->severity);
    }

    public function test_fluent_methods_keep_the_agent_but_an_entry_point_result_ends_the_agent_chain(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->path.'/app/Ai/Agents');
        $files->put($this->path.'/app/Ai/Agents/ReportProcessor.php', <<<'PHP'
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Promptable;

final class ReportProcessor
{
    use Promptable;
}
PHP);

        $findings = $this->rule()->check(new FileContext('app/Ai/Gateways/ReportGateway.php', <<<'PHP'
<?php

namespace App\Ai\Gateways;

use App\Ai\Agents\ReportProcessor;

final class ReportGateway
{
    public function run(string $method): array
    {
        return ReportProcessor::make()
            ->forUser($this)
            ->prompt('review')
            ->{$method}();
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_unavailable_or_oversized_mapped_sources_produce_an_incomplete_warning(): void
    {
        $missing = $this->rule()->check(new FileContext('app/Http/Controllers/MissingController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Ai\Agents\MissingAgent;

final class MissingController
{
    public function show(MissingAgent $runner): void
    {
        $runner->prompt('review');
    }
}
PHP));

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->path.'/app/Ai/Agents');
        $files->put(
            $this->path.'/app/Ai/Agents/HugeAgent.php',
            "<?php\nnamespace App\\Ai\\Agents;\n/*".str_repeat('x', 103_000)."*/\nfinal class HugeAgent {}\n",
        );
        $oversized = $this->rule()->check(new FileContext('app/Http/Controllers/HugeController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Ai\Agents\HugeAgent;

final class HugeController
{
    public function show(HugeAgent $runner): void
    {
        $runner->prompt('review');
    }
}
PHP));

        $this->assertSame('W_LARAVEL_AI_ANALYSIS_INCOMPLETE', $missing[0]->code);
        $this->assertStringContainsString('unavailable', $missing[0]->message);
        $this->assertSame('W_LARAVEL_AI_ANALYSIS_INCOMPLETE', $oversized[0]->code);
        $this->assertStringContainsString('per-file analysis limit', $oversized[0]->message);
    }

    public function test_the_total_source_budget_produces_an_incomplete_warning(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->path.'/app/Ai/Agents');

        for ($index = 0; $index < 12; $index++) {
            $extends = $index === 11
                ? ' implements \\Laravel\\Ai\\Contracts\\Agent'
                : ' extends BudgetAgent'.($index + 1);
            $files->put(
                $this->path.'/app/Ai/Agents/BudgetAgent'.$index.'.php',
                "<?php\nnamespace App\\Ai\\Agents;\n/*".str_repeat('x', 94_000)."*/\nclass BudgetAgent{$index}{$extends} {}\n",
            );
        }

        $findings = $this->rule()->check(new FileContext('app/Http/Controllers/BudgetController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Ai\Agents\BudgetAgent0;

final class BudgetController
{
    public function show(BudgetAgent0 $runner): void
    {
        $runner->prompt('review');
    }
}
PHP));

        $this->assertCount(1, $findings);
        $this->assertSame('W_LARAVEL_AI_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertStringContainsString('total source analysis budget', $findings[0]->message);
    }

    public function test_name_only_diagnostics_do_not_create_laravel_ai_findings(): void
    {
        $findings = $this->rule()->check(new FileContext('app/Services/DiagnosticService.php', <<<'PHP'
<?php

namespace App\Services;

final class StructuredGatewayAgent {}

final class DiagnosticService
{
    public function runAgent(string $agent, string $input): array
    {
        new StructuredGatewayAgent;

        return [];
    }
}
PHP));

        $this->assertSame([], $findings);
    }

    public function test_the_audit_never_executes_the_checked_php(): void
    {
        $sideEffect = $this->path.'/must-not-exist';
        $contents = str_replace('__SIDE_EFFECT__', var_export($sideEffect, true), <<<'PHP'
<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Contracts\Agent;

file_put_contents(__SIDE_EFFECT__, 'executed');

final class ReportGateway
{
    public function run(Agent $agent, string $method): void
    {
        $agent->{$method}('dynamic');
    }
}
PHP);

        $findings = $this->rule()->check(new FileContext('app/Ai/Gateways/ReportGateway.php', $contents));

        $this->assertFileDoesNotExist($sideEffect);
        $this->assertSame('W_LARAVEL_AI_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    private function rule(): LaravelAiRule
    {
        return new LaravelAiRule(new Filesystem, $this->path);
    }
}
