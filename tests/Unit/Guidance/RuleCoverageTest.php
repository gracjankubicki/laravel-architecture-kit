<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Guidance;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\BuiltInRules;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\FolderPurityRule;
use GracjanKubicki\ArchitectureKit\Guidance\RuleCoverage;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;

final class RuleCoverageTest extends TestCase
{
    public function test_every_built_in_audit_rule_declares_the_slugs_it_can_emit(): void
    {
        $mapped = RuleCoverage::builtInRuleSlugs();
        $missing = [];

        foreach ($this->builtInRules() as $rule) {
            if (! array_key_exists($rule::class, $mapped)) {
                $missing[] = $rule::class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These audit rules run in the audit but declare no finding slug, so file-scoped guidance would omit them.',
        );
    }

    public function test_every_declared_slug_is_a_known_finding_rule(): void
    {
        $known = FindingCodeRegistry::ruleIds();
        $declared = [];

        foreach (RuleCoverage::builtInRuleSlugs() as $slugs) {
            array_push($declared, ...$slugs);
        }

        foreach (RuleCoverage::architectureRules() as $slugs) {
            array_push($declared, ...$slugs);
        }

        array_push($declared, ...RuleCoverage::globalRules());

        $this->assertSame([], array_values(array_diff(array_unique($declared), $known)));
    }

    public function test_every_architecture_declares_whether_it_can_be_enforced(): void
    {
        foreach (Architecture::guidelineOrder() as $architecture) {
            $this->assertTrue(
                RuleCoverage::isKnownArchitecture($architecture->value),
                "Architecture [{$architecture->value}] has no enforcement entry, so agents cannot tell guidance from a verifiable rule.",
            );
        }
    }

    public function test_laravel_best_practices_is_reported_as_advisory_only(): void
    {
        // It ships in the default selection, so most installs carry guidance that no
        // rule can verify. Agents must be able to see that.
        $this->assertContains(Architecture::LaravelBestPractices, Architecture::defaultSelection());
        $this->assertTrue(RuleCoverage::isAdvisoryOnly('laravel-best-practices'));
    }

    public function test_an_enforced_architecture_is_not_reported_as_advisory(): void
    {
        $this->assertFalse(RuleCoverage::isAdvisoryOnly('actions'));
        $this->assertContains('actions', RuleCoverage::rulesForArchitecture('actions'));
    }

    public function test_every_architecture_slug_can_be_emitted_by_a_per_file_rule(): void
    {
        // A slug that only a project-graph rule emits never reaches file-scoped guidance,
        // so listing it under an architecture produces an entry nobody can ever see.
        $emittable = [];

        foreach ($this->builtInRules() as $rule) {
            array_push($emittable, ...$this->emittedSlugs($rule::class));
        }

        $declared = [];

        foreach (RuleCoverage::architectureRules() as $slugs) {
            array_push($declared, ...$slugs);
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($declared), $emittable)),
            'These architecture slugs are not emitted by any per-file audit rule, so they belong in the global list instead.',
        );
    }

    public function test_an_architecture_whose_folder_must_stay_pure_declares_the_folder_purity_rule(): void
    {
        $rule = new FolderPurityRule(Architecture::guidelineOrder());
        $missing = [];

        foreach (Architecture::guidelineOrder() as $architecture) {
            $placement = $architecture->defaultPlacement();

            if ($placement === null) {
                continue;
            }

            foreach (explode(',', $placement) as $folder) {
                $path = rtrim(trim($folder), '/').'/Example.php';

                if (
                    $rule->supports($path, Architecture::guidelineOrder())
                    && ! in_array('folder-purity', RuleCoverage::rulesForArchitecture($architecture->value), true)
                ) {
                    $missing[] = $architecture->value;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            'Folder purity runs in these architecture folders but is not declared, so agents are not told it can fail there.',
        );
    }

    public function test_a_placement_scoped_slug_is_shared_by_several_architectures(): void
    {
        // The scoping only earns its keep for a slug one rule emits on behalf of many
        // architectures; an exclusive slug needs no disambiguation by folder.
        foreach (RuleCoverage::placementScopedRules() as $slug) {
            $owners = array_keys(array_filter(
                RuleCoverage::architectureRules(),
                static fn (array $slugs): bool => in_array($slug, $slugs, true),
            ));

            $this->assertGreaterThan(1, count($owners), "Slug [{$slug}] is not shared, so scoping it by placement hides it.");
        }
    }

    public function test_no_always_active_rule_is_one_a_per_file_pass_can_report(): void
    {
        // A per-file rule answers per path through its own supports(), so repeating it
        // as always active both duplicates it and claims reach it does not have.
        $perFile = [];

        foreach (RuleCoverage::builtInRuleSlugs() as $slugs) {
            array_push($perFile, ...$slugs);
        }

        $this->assertSame(
            [],
            array_values(array_intersect(RuleCoverage::globalRules(), array_unique($perFile))),
            'These rules run per file, so they belong in the per-path result rather than in the always-active list.',
        );
    }

    public function test_every_rule_declares_each_slug_it_can_emit(): void
    {
        // A rule that emits a slug nobody attributed to it leaves file-scoped guidance
        // silently short, which is how service-locator inside app/Services was missed.
        $declared = RuleCoverage::builtInRuleSlugs();
        $missing = [];

        foreach ($this->builtInRules() as $rule) {
            foreach ($this->emittedSlugs($rule::class) as $slug) {
                if (! in_array($slug, $declared[$rule::class] ?? [], true)) {
                    $missing[] = $rule::class.' => '.$slug;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * Slugs the rule's own source passes to AuditFinding, read from the class file and
     * the checks it composes.
     *
     * @param  class-string  $class
     * @return array<int, string>
     */
    private function emittedSlugs(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        if ($file === false) {
            return [];
        }

        $sources = [(string) file_get_contents($file)];

        foreach (glob(dirname($file).'/Checks/*.php') ?: [] as $check) {
            $sources[] = (string) file_get_contents($check);
        }

        $slugs = [];

        foreach ($sources as $source) {
            // A slug may be a single word, such as `services` or `saloon`, so requiring a
            // hyphen would silently skip most of them.
            if (preg_match_all("/'([a-z0-9]+(?:-[a-z0-9]+)*)'/", $source, $matches) === false) {
                continue;
            }

            array_push($slugs, ...$matches[1]);
        }

        return array_values(array_intersect(array_unique($slugs), FindingCodeRegistry::ruleIds()));
    }

    public function test_a_custom_project_rule_falls_back_to_its_class_name_slug(): void
    {
        $rule = new class implements AuditRule
        {
            public function supports(string $path, array $enabled): bool
            {
                return true;
            }

            public function check(FileContext $file): array
            {
                return [];
            }
        };

        $this->assertNotSame([], RuleCoverage::slugsFor($rule));
    }

    /**
     * @return array<int, AuditRule>
     */
    private function builtInRules(): array
    {
        return BuiltInRules::all(new Filesystem, $this->tempPath, Architecture::guidelineOrder());
    }
}
