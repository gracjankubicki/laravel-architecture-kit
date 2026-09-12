<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Guidance;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\BuiltInRules;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\RuleRegistry;
use GracjanKubicki\ArchitectureKit\EnabledArchitecture;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;

/**
 * Answers "which rules govern this one file" so an agent can write it correctly
 * instead of loading the whole rulebook or guessing the relevant section.
 *
 * The path does not have to exist: writing a new file is the main reason to ask.
 */
final readonly class FileGuidance
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private ArchitectureCatalog $catalog,
        private AuditScope $scope = new AuditScope,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array{path: string, in_scope: bool, architectures: array<int, array<string, mixed>>, rules: array<int, string>, project_rules: array<int, string>, global_rules: array<int, string>}
     */
    public function for(string $path, array $enabled, CustomRuleSet $customRules): array
    {
        $path = $this->normalize($path);
        // Follows the audited scope rather than assuming app/: once a project adds
        // routes/ the rules there are real, and telling an agent otherwise would be the
        // same blind spot this scope was widened to close.
        $inScope = $this->scope->covers($path) && ! $this->scope->isTestPath($path);
        $architectures = [];
        $builtIn = [];
        $project = [];

        // The audit only ever loads application files, so reporting rules for a path it
        // never reads would promise enforcement that does not exist.
        if ($inScope) {
            [$builtIn, $project] = $this->supportedSlugs($path, $enabled, $customRules);

            foreach ($this->catalog->ordered($enabled) as $architecture) {
                $entry = $this->entry($architecture, $path, $builtIn);

                if ($entry !== null) {
                    $architectures[] = $entry;
                }
            }
        }

        // Derived from the rules themselves rather than from the architecture entries:
        // a project rule and a shared rule such as folder purity govern the file even
        // when no enabled architecture claims them.
        $rules = array_values(array_unique(array_merge($builtIn, $project)));
        sort($rules);

        return [
            'path' => $path,
            'in_scope' => $inScope,
            'architectures' => $architectures,
            'rules' => $rules,
            'project_rules' => $project,
            'global_rules' => $inScope ? RuleCoverage::globalRules() : [],
        ];
    }

    /**
     * @param  array<int, string>  $supported
     * @return array<string, mixed>|null
     */
    private function entry(EnabledArchitecture $architecture, string $path, array $supported): ?array
    {
        $slug = $architecture->slug();
        $advisory = ! RuleCoverage::isKnownArchitecture($slug) || RuleCoverage::isAdvisoryOnly($slug);
        $placements = $this->placements($architecture);
        $governs = $this->matchesPlacement($path, $placements);
        $rules = array_values(array_intersect(RuleCoverage::rulesForArchitecture($slug), $supported));

        if (! $governs) {
            $rules = array_values(array_diff($rules, RuleCoverage::placementScopedRules()));
        }

        // Guidance without any verifiable rule still applies to every application file,
        // otherwise the agent would never be told that it exists.
        if (! $advisory && ! $governs && $rules === []) {
            return null;
        }

        return [
            'slug' => $slug,
            'label' => $architecture->label(),
            // Placement tells the agent which guidance actually describes this file.
            // A shared rule such as folder purity can fire here without the file
            // belonging to that architecture at all.
            'governs' => $governs,
            'enforcement' => $advisory ? 'advisory' : 'enforced',
            'rules' => $rules,
            'placement' => $placements,
            'skill' => $architecture->skillName(),
        ];
    }

    /**
     * Slugs supported at this path, split into the package's own rules and the rules the
     * project registered itself. The split is taken at the source rather than by
     * subtracting a static map, so a custom rule can never be dropped silently.
     *
     * @param  array<int, Architecture|string>  $enabled
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function supportedSlugs(string $path, array $enabled, CustomRuleSet $customRules): array
    {
        $builtIn = [];
        $project = [];

        foreach (BuiltInRules::all($this->files, $this->basePath, $enabled) as $rule) {
            if ($rule->supports($path, $enabled)) {
                array_push($builtIn, ...RuleCoverage::slugsFor($rule));
            }
        }

        foreach ((new RuleRegistry($customRules->rulesFor($enabled)))->customRules() as $rule) {
            if ($rule->supports($path, $enabled)) {
                array_push($project, ...RuleCoverage::slugsFor($rule));
            }
        }

        $project = array_values(array_unique($project));
        sort($project);

        return [array_values(array_unique($builtIn)), $project];
    }

    /**
     * @return array<int, string>
     */
    private function placements(EnabledArchitecture $architecture): array
    {
        $placement = $architecture->defaultPlacement();

        if ($placement === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $placement))));
    }

    /**
     * @param  array<int, string>  $placements
     */
    private function matchesPlacement(string $path, array $placements): bool
    {
        foreach ($placements as $placement) {
            if (str_starts_with($path, rtrim($placement, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        return ProjectPath::relative($this->basePath, str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path);
    }
}
