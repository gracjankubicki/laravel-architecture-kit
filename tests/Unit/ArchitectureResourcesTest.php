<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityResult;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityStatus;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiProfile;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use Illuminate\Filesystem\Filesystem;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ArchitectureResourcesTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-resources-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_behavioural_test_guidance_is_present_in_each_generated_form(): void
    {
        $resources = $this->resources();
        $enabled = [Architecture::Actions];
        foreach ([$resources->guideline($enabled)->contents, $resources->fullGuideline($enabled), $resources->architectureGuideline(Architecture::Actions, $enabled), $resources->skills($enabled)['actions']->contents] as $text) {
            $this->assertStringContainsString('class role alone does not require a separate test', $text);
            $this->assertStringContainsString('static class references do not prove execution or meaningful assertions', $text);
        }
    }

    public function test_compact_guideline_stays_within_token_budget(): void
    {
        $resources = $this->resources();

        $default = $resources->guideline(Architecture::defaultSelection())->contents;
        $all = $resources->guideline(Architecture::cases())->contents;

        $this->assertLessThanOrEqual(60, $this->lineCount($default));
        $this->assertLessThanOrEqual(6000, strlen($default));
        $this->assertLessThanOrEqual(90, $this->lineCount($all));
        $this->assertStringContainsString('## Architecture Kit', $default);
        $this->assertStringContainsString('## Enabled Architecture Index', $default);
        $this->assertStringContainsString('## Before Finishing', $default);
        $this->assertStringNotContainsString('Good example:', $default);
        $this->assertStringNotContainsString('```php', $default);
    }

    public function test_read_flow_uses_service_only_when_queries_are_not_enabled(): void
    {
        $enabled = [Architecture::ThinControllers, Architecture::Actions, Architecture::Services];
        $guideline = $this->resources()->guideline($enabled)->contents;
        $this->assertStringContainsString('Read flow: Route -> Controller -> Read Service', $guideline);
        $withQueries = $this->resources()->guideline([...$enabled, Architecture::QueryObjects])->contents;
        $this->assertStringContainsString('Read flow: Route -> Controller -> Query Object', $withQueries);
        $this->assertStringNotContainsString('Read Service', $withQueries);
    }

    public function test_full_and_single_architecture_guidelines_keep_expanded_content(): void
    {
        $resources = $this->resources();
        $enabled = [Architecture::Actions];

        $full = $resources->fullGuideline($enabled);
        $single = $resources->architectureGuideline('actions', $enabled);

        $this->assertStringContainsString('## Package-First Architecture Rule', $full);
        $this->assertStringContainsString('### Actions', $full);
        $this->assertStringContainsString('Good example:', $full);
        $this->assertStringContainsString('## Actions', $single);
        $this->assertStringContainsString('Status: enabled globally.', $single);
        $this->assertStringContainsString('Expose one public entry method: `handle(...)`.', $single);
    }

    public function test_inertia_resources_keep_guidance_and_enforcement_boundaries_consistent(): void
    {
        $resources = $this->resources();
        $enabled = [
            Architecture::Actions,
            Architecture::QueryObjects,
            Architecture::DataObjects,
            Architecture::ApiResources,
            Architecture::Inertia,
        ];

        $guideline = $resources->architectureGuideline(Architecture::Inertia, $enabled);
        $skill = $resources->skills($enabled)['inertia']->contents;
        $summary = $resources->summaryFor(Architecture::Inertia, $enabled);
        $combined = implode("\n", [$guideline, $skill]);

        $this->assertStringContainsString('Keep Inertia 3 response composition in the presentation layer', $summary);
        $this->assertStringContainsString('name: architecture-kit-inertia', $skill);
        $this->assertStringContainsString('explicit array', $combined);
        $this->assertStringContainsString('API Resources', $combined);
        $this->assertStringContainsString('Query Objects', $combined);
        $this->assertStringContainsString('Data Objects', $combined);
        $this->assertStringContainsString('Spatie Laravel Data is optional', $combined);
        $this->assertStringContainsString('ProvidesInertiaProperties', $combined);
        $this->assertStringContainsString('reloadOnly', $combined);
        $this->assertStringContainsString('loadDeferredProps', $combined);
        $this->assertStringContainsString('rescue()', $combined);
        $this->assertStringContainsString('frontend is not the authorization boundary', $combined);
        $this->assertStringContainsString('The `inertia` audit rule enforces two boundaries', $guideline);
        $this->assertStringContainsString('The guard does not prove prop completeness', $skill);
    }

    public function test_summary_fragments_and_custom_fallbacks_are_resolved(): void
    {
        $resources = $this->resources();

        $withoutData = $resources->summaryFor(Architecture::FormRequests, [Architecture::FormRequests]);
        $withData = $resources->summaryFor(Architecture::FormRequests, [Architecture::FormRequests, Architecture::DataObjects]);

        $this->assertStringNotContainsString('toData()', $withoutData);
        $this->assertStringContainsString('toData()', $withData);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/billing-workflows');
        $files->put($this->tempPath.'/.architecture-kit/architectures/billing-workflows/guideline.md', "\nCustom billing workflow rules.\nSecond line.");

        $this->assertSame(
            'Custom billing workflow rules.',
            $resources->summaryFor('billing-workflows', ['billing-workflows']),
        );
    }

    public function test_custom_architecture_renders_without_default_placement(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit/architectures/billing-workflows');
        $files->put($this->tempPath.'/.architecture-kit/architectures/billing-workflows/guideline.md', 'Custom billing workflow rules.');

        $guideline = $this->resources()->guideline(['billing-workflows'])->contents;

        $this->assertStringContainsString('| Billing Workflows | — | Custom billing workflow rules. |', $guideline);
    }

    public function test_builtin_architectures_require_summary_resources(): void
    {
        $files = new Filesystem;
        $packagePath = $this->tempPath.'/package';
        $files->ensureDirectoryExists($packagePath.'/resources/architectures/actions');
        $files->put($packagePath.'/resources/architectures/actions/guideline.md', 'Rules');
        $files->put($packagePath.'/resources/architectures/actions/SKILL.md', "---\nname: architecture-kit-actions\n---\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing Architecture Kit source resource');

        (new ArchitectureResources($packagePath, $this->tempPath, $files))->assertSourcesExist([Architecture::Actions]);
    }

    public function test_laravel_ai_uses_exactly_one_resolved_profile_at_stable_paths(): void
    {
        $compatibility = new LaravelAiCompatibilityResult(
            status: LaravelAiCompatibilityStatus::Supported,
            section: 'require',
            declaredConstraint: '^0.9',
            installedVersion: '0.9.0',
            lockedVersion: '0.9.0',
            profile: LaravelAiProfile::V09,
        );
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, new Filesystem, laravelAi: $compatibility);

        $skill = $resources->skills([Architecture::LaravelAi])['laravel-ai'];

        $this->assertSame($this->tempPath.'/.ai/skills/architecture-kit-laravel-ai/SKILL.md', $skill->path);
        $this->assertStringContainsString('Profile: `laravel-ai@0.9`', $skill->contents);
        $this->assertStringContainsString('Installed version: `0.9.0`', $skill->contents);
        $this->assertStringContainsString('withProviderOptions(', $skill->contents);
        $this->assertStringNotContainsString('structuredOutput()', $skill->contents);
        $this->assertStringNotContainsString('laravel-ai@0.8', $skill->contents);
    }

    public function test_laravel_ai_architecture_exposes_both_atomic_upgrade_skills(): void
    {
        $compatibility = new LaravelAiCompatibilityResult(
            status: LaravelAiCompatibilityStatus::Supported,
            section: 'require',
            declaredConstraint: '^0.8',
            installedVersion: '0.8.1',
            lockedVersion: '0.8.1',
            profile: LaravelAiProfile::V08,
        );
        $skills = (new ArchitectureResources(
            dirname(__DIR__, 2),
            $this->tempPath,
            new Filesystem,
            laravelAi: $compatibility,
        ))->skills([Architecture::LaravelAi]);

        $this->assertArrayHasKey('upgrade:laravel-ai:0.8-to-0.9', $skills);
        $this->assertArrayHasKey('upgrade:laravel-ai:0.9-to-0.10', $skills);
        $this->assertSame(
            $this->tempPath.'/.ai/skills/architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md',
            $skills['upgrade:laravel-ai:0.8-to-0.9']->path,
        );
        $this->assertSame(
            $this->tempPath.'/.ai/skills/architecture-kit-upgrade-laravel-ai-0-9-to-0-10/SKILL.md',
            $skills['upgrade:laravel-ai:0.9-to-0.10']->path,
        );
    }

    public function test_laravel_ai_08_to_09_upgrade_skill_publishes_an_evidence_first_contract(): void
    {
        $compatibility = new LaravelAiCompatibilityResult(
            status: LaravelAiCompatibilityStatus::Supported,
            section: 'require',
            declaredConstraint: '^0.8',
            installedVersion: '0.8.1',
            lockedVersion: '0.8.1',
            profile: LaravelAiProfile::V08,
        );
        $skill = (new ArchitectureResources(
            dirname(__DIR__, 2),
            $this->tempPath,
            new Filesystem,
            laravelAi: $compatibility,
        ))->skills([Architecture::LaravelAi])['upgrade:laravel-ai:0.8-to-0.9']->contents;

        $this->assertStringContainsString('## Evidence-first workflow', $skill);
        $this->assertStringContainsString('providerOptions()', $skill);
        $this->assertStringContainsString('TextGateway', $skill);
        $this->assertStringContainsString('Faked responses', $skill);
        $this->assertStringContainsString('native Anthropic structured outputs', $skill);
        $this->assertStringContainsString('architecture-kit-upgrade-laravel-ai-0-9-to-0-10', $skill);
        $this->assertStringContainsString('## Requirement-evidence handoff', $skill);
    }

    public function test_laravel_ai_09_to_010_upgrade_skill_covers_breaking_and_optional_changes(): void
    {
        $compatibility = new LaravelAiCompatibilityResult(
            status: LaravelAiCompatibilityStatus::Supported,
            section: 'require',
            declaredConstraint: '^0.9',
            installedVersion: '0.9.1',
            lockedVersion: '0.9.1',
            profile: LaravelAiProfile::V09,
        );
        $skill = (new ArchitectureResources(
            dirname(__DIR__, 2),
            $this->tempPath,
            new Filesystem,
            laravelAi: $compatibility,
        ))->skills([Architecture::LaravelAi])['upgrade:laravel-ai:0.9-to-0.10']->contents;

        $this->assertStringContainsString('## Evidence-first workflow', $skill);
        $this->assertStringContainsString('participant_type', $skill);
        $this->assertStringContainsString('approval_state', $skill);
        $this->assertStringContainsString('Decisions|string', $skill);
        $this->assertStringContainsString('storeApprovalResults()', $skill);
        $this->assertStringContainsString('Multimodal embeddings', $skill);
        $this->assertStringContainsString('Bedrock AssumeRole', $skill);
        $this->assertStringContainsString('Gemini default models', $skill);
        $this->assertStringContainsString('architecture-kit-upgrade-laravel-ai-0-8-to-0-9', $skill);
        $this->assertStringContainsString('## Requirement-evidence handoff', $skill);
    }

    public function test_laravel_ai_010_uses_its_verified_profile_resources(): void
    {
        $compatibility = new LaravelAiCompatibilityResult(
            status: LaravelAiCompatibilityStatus::Supported,
            section: 'require',
            declaredConstraint: '^0.10',
            installedVersion: '0.10.1',
            lockedVersion: '0.10.1',
            profile: LaravelAiProfile::V010,
        );
        $resources = new ArchitectureResources(
            dirname(__DIR__, 2),
            $this->tempPath,
            new Filesystem,
            laravelAi: $compatibility,
        );

        $skill = $resources->skills([Architecture::LaravelAi])['laravel-ai']->contents;
        $guideline = $resources->architectureGuideline(Architecture::LaravelAi, [Architecture::LaravelAi]);

        $this->assertStringContainsString('Profile: `laravel-ai@0.10`', $skill);
        $this->assertStringContainsString('human-in-the-loop', $skill);
        $this->assertStringContainsString('approval_state', $guideline);
        $this->assertStringNotContainsString('laravel-ai@0.9', $skill);
    }

    public function test_disabled_laravel_ai_has_a_neutral_summary_without_resolving_a_profile(): void
    {
        $summary = $this->resources()->summaryFor(Architecture::LaravelAi, [Architecture::Actions]);

        $this->assertStringContainsString('project-owned Gateways', $summary);
        $this->assertStringNotContainsString('0.8', $summary);
        $this->assertStringNotContainsString('0.9', $summary);
    }

    public function test_sdk_and_saloon_policy_is_consistent_across_generated_resource_variants(): void
    {
        $resources = $this->resources();
        $saloon = [Architecture::Saloon];
        $actionsAndSaloon = [Architecture::Actions, Architecture::Saloon];
        $allBoundaries = [
            Architecture::Actions,
            Architecture::Saloon,
            Architecture::PortsAndAdapters,
            Architecture::DataObjects,
            Architecture::LaravelBestPractices,
        ];
        $portsOnly = [Architecture::PortsAndAdapters];

        $outputs = [
            $resources->guideline($saloon)->contents,
            $resources->fullGuideline($actionsAndSaloon),
            $resources->architectureGuideline(Architecture::Saloon, $allBoundaries),
            $resources->summaryFor(Architecture::Saloon, $allBoundaries),
            $resources->skills($allBoundaries)['saloon']->contents,
            $resources->architectureGuideline(Architecture::PortsAndAdapters, $portsOnly),
            $resources->skills($portsOnly)['ports-and-adapters']->contents,
        ];
        $combined = implode("\n", $outputs);

        $this->assertStringContainsString('application-owned direct HTTP', $combined);
        $this->assertStringContainsString('official SDK', $combined);
        $this->assertStringContainsString('HTTP or gRPC', $combined);
        $this->assertStringContainsString('app/Advertising/Adapters', $combined);
        $this->assertStringContainsString('Actions and Jobs depend on application Ports', $combined);
        $this->assertStringContainsString('authentication, and token refresh', $combined);
        $this->assertStringContainsString('do not intercept SDK-owned traffic', $combined);
        $this->assertStringContainsString('googleads/google-ads-php` v35.0.0', $combined);
        $this->assertStringNotContainsString('Every third-party or internal outbound HTTP integration goes through Saloon.', $combined);
        $this->assertStringNotContainsString('All outbound HTTP integrations MUST go through Saloon', $combined);
    }

    public function test_saloon_skill_php_examples_are_syntactically_valid(): void
    {
        $skill = $this->resources()->skills([
            Architecture::Actions,
            Architecture::Saloon,
            Architecture::PortsAndAdapters,
        ])['saloon']->contents;
        $matched = preg_match_all('/```php\n(.*?)```/s', $skill, $matches);

        $this->assertNotFalse($matched);
        $this->assertGreaterThanOrEqual(6, $matched);

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach ($matches[1] as $example) {
            $this->assertNotNull($parser->parse("<?php\n".$example));
        }
    }

    private function resources(): ArchitectureResources
    {
        return new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, new Filesystem);
    }

    private function lineCount(string $contents): int
    {
        return substr_count(trim($contents), "\n") + 1;
    }
}
