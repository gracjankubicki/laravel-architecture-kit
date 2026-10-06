<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\BuiltInRules;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerReadAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\UnknownRoleRule;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\Baseline;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\InlineIgnores;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use GracjanKubicki\ArchitectureKit\Revision\RevisionFacts;
use Illuminate\Support\Str;
use Throwable;

/** Preserve raw audit findings and their suppression state; never execute custom rules. */
final readonly class TargetAudit
{
    /** @return array{findings: list<array<string, mixed>>, notices: list<array<string, mixed>>, complete: bool} */
    public static function collect(RevisionFacts $facts, string $base): array
    {
        if ($facts->configuration->values === null) {
            return ['findings' => [], 'notices' => $facts->configuration->notices, 'complete' => false];
        }
        $files = new FrozenFilesystem($facts->source, $base);
        $classification = new ProjectClassification($files, $base);
        $enabled = array_map(static fn (string $slug): Architecture|string => Architecture::tryFrom($slug) ?? $slug, $facts->configuration->values['enabled'] ?? []);
        $rules = BuiltInRules::all($files, $base, $enabled, $classification);
        $raw = $notices = [];
        $builder = new ProjectGraphBuilder($classification->roles);
        foreach ($facts->reuse as $path => $entry) {
            $scope = $facts->configuration->scope();
            if (! $scope->covers($path) || (Str::is($facts->configuration->excludes(), $path) && ! $scope->isTestPath($path))) {
                continue;
            }
            $file = new FileContext($path, $facts->source->files[$path]);
            if (($reason = ImpactExtractor::sourceLimit(strlen($file->contents))) !== null) {
                $notices[] = ['path' => $path, 'reason' => $reason];

                continue;
            }
            try {
                if ($file->ast() === null) {
                    $notices[] = ['path' => $path, 'reason' => 'Current audit source is unparseable.'];

                    continue;
                }
                $classification->prime($file);
                if (! $classification->roles::isTestPath($path)) {
                    foreach ($rules as $rule) {
                        if ($rule->supports($path, $enabled)) {
                            array_push($raw, ...BuiltInRules::check($rule, $file, $enabled, $classification));
                        }
                    }
                }
                $builder->add($file);
            } catch (Throwable $error) {
                $notices[] = ['path' => $path, 'reason' => 'Current audit unresolved: '.$error->getMessage()];
            } finally {
                $file->releaseAst();
            }
        }
        $graph = $builder->finish();
        foreach ([...(new ProjectRuleSet)->rules(), new UnknownRoleRule($classification->roles->mappings->unknownLevel)] as $rule) {
            array_push($raw, ...$rule->check($graph, $enabled));
        }
        // Runtime registrations are not booted or substituted by source guesses.
        $endpoint = (new ControllerReadAudit($files, $base, $classification))->analyze($graph, $enabled, null, new RouteMap(unavailable: 'Target migration analysis never boots runtime route registration.'));
        array_push($raw, ...$endpoint->findings);
        foreach ($endpoint->notices as $notice) {
            $notices[] = $notice->toArray();
        }
        if (($facts->configuration->values['rules'] ?? []) !== []) {
            $notices[] = ['path' => 'config/architectures.php', 'reason' => 'Custom audit rules are declared but not executed by source-only migration analysis.'];
        }
        if (($facts->configuration->values['audit']['missing_test'] ?? 'off') !== 'off') {
            $notices[] = ['path' => 'config/architectures.php', 'reason' => 'Runtime-dependent missing-test endpoint reachability is not evaluated by migration analysis.'];
        }
        $baseline = new Baseline($files, $base);
        $baseline->validate();
        $byPath = [];
        foreach ($raw as $finding) {
            $byPath[$finding->path][] = $finding;
        }
        $rows = [];
        foreach ($byPath as $path => $findings) {
            $remaining = (new InlineIgnores)->apply($path, $facts->source->files[$path] ?? '', $findings, FindingCodeRegistry::ruleIds())->findings;
            $visible = array_fill_keys(array_map('spl_object_id', $remaining), true);
            $unmasked = $baseline->apply($remaining)->findings;
            $afterBaseline = array_fill_keys(array_map('spl_object_id', $unmasked), true);
            foreach ($findings as $finding) {
                $id = spl_object_id($finding);
                $rows[] = [...get_object_vars($finding), 'unresolved' => true, 'suppression' => ! isset($visible[$id]) ? 'inline' : (! isset($afterBaseline[$id]) ? 'baseline' : null)];
            }
        }

        return ['findings' => $rows, 'notices' => $notices, 'complete' => $notices === []];
    }
}
