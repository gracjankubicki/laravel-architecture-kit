<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Revision\RevisionConfiguration;
use GracjanKubicki\ArchitectureKit\Revision\RevisionFacts;
use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use InvalidArgumentException;
use Throwable;

/** Shared read-only migration report. It never accepts, writes or refreshes a reference. */
final readonly class ArchitectureTarget
{
    public function __construct(private string $base) {}

    /** @return array<string, mixed> */
    public function inspect(string $subject = '', int $limit = 50): array
    {
        if ($limit < 0 || $limit > 500 || strlen($subject) > 1000) {
            return self::error('E_TARGET_INPUT', 'Use a subject up to 1000 bytes and limit 0..500.');
        }
        try {
            $definition = self::load($this->base);
            if ($definition === null) {
                return ['v' => 1, 'cmd' => 'architecture-target', 'ok' => true, 'configured' => false, 'next' => ['declare:'.TargetDefinition::PATH]];
            }
            $referencePath = $definition->values['reference'] ?? null;
            $extra = $referencePath === null ? [] : [$referencePath];
            $sources = new TargetSources($this->base);
            $snapshot = $sources->capture($definition->values['paths'], $extra);
            $config = RevisionConfiguration::from($snapshot);
            $directories = array_values(array_unique([...$definition->values['paths'], ...($config->scope() === null ? [] : $config->scope()->directories)]));
            $snapshot = $sources->capture($directories, $extra);
            $config = RevisionConfiguration::from($snapshot);
            $facts = RevisionFacts::collect($snapshot, $config, scopeOverride: new AuditScope($directories), includeExcluded: true);
            $audit = TargetAudit::collect($facts, $this->base);
            $elements = TargetEvaluation::evaluate($definition, $facts, $audit);
            $reference = null;
            if ($referencePath !== null) {
                $contents = $snapshot->files[$referencePath] ?? null;
                if ($contents === null) {
                    throw new InvalidArgumentException('Declared target reference is unavailable.');
                }
                $reference = TargetReference::decode($contents);
            }
            $fresh = $snapshot->fingerprint === $sources->capture($directories, $extra)->fingerprint
                && TargetDefinition::decode($snapshot->files[TargetDefinition::PATH] ?? '')->fingerprint === $definition->fingerprint;
            $analysisFingerprint = hash('sha256', serialize([$config->fingerprint, $directories]));
            $evaluationLimited = count(array_filter($elements, static fn (array $element): bool => $element['evaluation_limited'])) > 0;
            $dependencyComplete = $facts->channels['execution'] && count(array_filter($elements, static fn (array $element): bool => $element['covered'] && $element['requires_check'])) === 0;
            $complete = $fresh && $facts->channels['structure'] && ! $evaluationLimited && $dependencyComplete;
            $progress = $reference === null ? null : TargetReference::compare($definition, $reference, $elements, $analysisFingerprint, $complete);
            $onlyNew = ($definition->values['only'] ?? 'all') === 'new';
            if ($onlyNew && ($progress === null || ! $progress['comparable'])) {
                return self::error('E_TARGET_REFERENCE', 'New-only enforcement requires a human-accepted reference for the exact target version and scope. No reference is created or updated automatically.');
            }
            $counts = ['conformant' => 0, 'migration' => 0, 'requires_check' => 0, 'outside_scope' => 0];
            $gateIssues = $issueTotal = 0;
            foreach ($elements as $id => &$element) {
                $counts[$element['status']]++;
                foreach ($element['issues'] as &$issue) {
                    $issue['new'] = $progress === null || ! $progress['comparable'] ? null : ! in_array($issue['id'], $reference['elements'][$id]['issues'] ?? [], true);
                    $issueTotal++;
                    if ($issue['certainty'] === 'declared' && (! $onlyNew || $issue['new'])) {
                        $gateIssues++;
                    }
                }
                unset($issue);
            }
            unset($element);
            $notices = [...$facts->notices, ...$audit['notices']];
            if ($evaluationLimited) {
                $notices[] = ['code' => 'E_TARGET_BUDGET', 'path' => '', 'message' => 'Target dependency policy evaluation budget reached; known issues are a lower bound.'];
            }
            $candidate = self::candidate($definition, $elements, $snapshot->fingerprint, $analysisFingerprint, $complete);
            if ($candidate === null) {
                $notices[] = ['code' => 'E_TARGET_REFERENCE_BUDGET', 'path' => '', 'message' => 'Full reference exceeds 1 MB; no truncated reference can be accepted. Use a smaller target area.'];
            }
            if (! $fresh) {
                $notices[] = ['path' => '', 'reason' => 'Target inputs changed during analysis; rerun before using the report.'];
            }
            $mode = $definition->values['mode'] ?? 'info';
            $gateOk = $mode !== 'block' || ($gateIssues === 0 && $complete);
            $selected = self::select($subject, $elements, $definition, $facts, $this->base);
            $proposals = self::proposals($elements, $facts);

            return ['v' => 1, 'cmd' => 'architecture-target', 'ok' => true, 'configured' => true,
                'target' => [...TargetReference::identity($definition), 'source' => TargetDefinition::PATH, 'declarations' => $definition->values],
                'source' => $snapshot->identity(), 'analysis' => ['fresh' => $fresh, 'complete' => $notices === [], 'structure_complete' => $facts->channels['structure'], 'audit_complete' => $audit['complete'], 'dependencies_complete' => $dependencyComplete, 'dependency_status' => $dependencyComplete ? 'complete' : 'requires_check',
                    'scope' => $directories, 'total_is_lower_bound' => ! $facts->channels['structure'] || $evaluationLimited, 'display_truncated' => count($elements) > $limit || count($proposals) > $limit || count($notices) > $limit],
                'gate' => ['mode' => $mode, 'only' => $onlyNew ? 'new' : 'all', 'issues' => $gateIssues, 'warnings' => $mode === 'warn' ? $gateIssues : 0, 'ok' => $gateOk, 'requires_check' => ! $complete],
                'totals' => [...$counts, 'issues' => $issueTotal, 'audit_findings' => count($audit['findings']), 'notices' => count($notices)],
                'elements' => array_slice(array_values($elements), 0, $limit), 'subject' => $selected,
                'migration_order' => array_slice($proposals, 0, $limit),
                'audit' => ['complete' => $audit['complete'], 'findings' => array_slice($audit['findings'], 0, $limit), 'limitations' => ['Current package rules use frozen source facts. Custom rules and runtime route registration are not executed. Suppressed findings remain unresolved.']],
                'progress' => $progress, 'reference_candidate' => $candidate,
                'notices' => array_slice($notices, 0, $limit),
                'limitations' => ['Target expectations do not enable, disable or suppress current audit rules.', 'No source code, reference, audit baseline or application configuration is written.', 'Unknown, dynamic and external dependencies require inspection; absence of findings is not a runtime or security proof.', 'Class role/kind declarations are architectural classification, not proof of framework execution.', 'Freshness covers source paths/mtime/size with content validation. Equal-stat edits are outside the stat guarantee.'],
                'next' => $fresh ? ['inspect:migration_order', 'inspect:subject.expected', 'review:current_audit_divergence'] : ['rerun:architecture-target']];
        } catch (Throwable $error) {
            return self::error('E_TARGET_CONFIGURATION', $error->getMessage());
        }
    }

    /** @param array<string, array<string, mixed>> $elements
     * @return array<string, mixed>|null */
    private static function candidate(TargetDefinition $definition, array $elements, string $source, string $analysis, bool $complete): ?array
    {
        $candidate = TargetReference::candidate($definition, $elements, $source, $analysis, $complete);

        return strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) <= 1000000 ? $candidate : null;
    }

    public static function load(string $base): ?TargetDefinition
    {
        $path = $base.'/'.TargetDefinition::PATH;
        if (! DiscoverySettings::safe($base, TargetDefinition::PATH)) {
            throw new InvalidArgumentException('Unsafe target declaration path.');
        }
        if (! is_file($path)) {
            return null;
        }
        if (filesize($path) > 100000) {
            throw new InvalidArgumentException('Target declaration exceeds 100 KB.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Target declaration is unreadable.');
        }
        try {
            $source = stream_get_contents($handle, 100001);
        } finally {
            fclose($handle);
        }
        if ($source === false) {
            throw new InvalidArgumentException('Target declaration is unreadable.');
        }

        return TargetDefinition::decode($source);
    }

    /** @param array<string, array<string, mixed>> $elements
     * @return array<string, mixed>|null */
    private static function select(string $subject, array $elements, TargetDefinition $definition, RevisionFacts $facts, string $base): ?array
    {
        if ($subject === '') {
            return null;
        }
        $id = strtolower(explode('::', ltrim($subject, '\\'))[0]);
        if (isset($elements[$id])) {
            return $elements[$id];
        }
        if (! str_ends_with(strtolower($subject), '.php')) {
            throw new InvalidArgumentException('Subject must be a declared class, class method or project-relative PHP file.');
        }
        $path = ProjectPath::relative($base, str_starts_with($subject, '/') ? $subject : $base.'/'.$subject);
        if (! SnapshotInputs::safe($path)) {
            throw new InvalidArgumentException('Unsafe target subject path.');
        }
        $matches = array_values(array_filter($elements, static fn (array $element): bool => $element['path'] === $path));
        if ($matches !== []) {
            return ['path' => $path, 'elements' => $matches];
        }
        $current = $facts->configuration->mappings() === null ? ['path' => $path, 'name' => '', 'role' => null, 'application_kind' => null, 'module' => null] : ['path' => $path, 'name' => '', ...(new RoleClassifier($facts->configuration->mappings()))->describe($path, '', 'class')];

        return ['path' => $path, 'status' => in_array($path, $facts->source->paths, true) ? 'requires_check' : 'future_file', 'covered' => $definition->covers($path), 'current' => $current, 'expected' => TargetEvaluation::expected($definition, $current), 'requires_check' => ['Namespace selectors require the new class namespace; no namespace is guessed.']];
    }

    /** @param array<string, array<string, mixed>> $elements
     * @return list<array<string, mixed>> */
    private static function proposals(array $elements, RevisionFacts $facts): array
    {
        $pending = array_filter($elements, static fn (array $element): bool => $element['status'] === 'migration');
        $dependencies = [];
        foreach ($facts->graph->edges as $edge) {
            $from = strtolower($edge->from);
            $to = strtolower($edge->to);
            if ($from !== $to && isset($pending[$from], $pending[$to])) {
                $dependencies[$from][$to] = true;
            }
        }
        $rows = [];
        $rank = 1;
        while ($pending !== []) {
            $ready = array_filter(array_keys($pending), static fn (string $id): bool => array_intersect_key($dependencies[$id] ?? [], $pending) === []);
            sort($ready);
            if ($ready === []) {
                foreach ($pending as $id => $element) {
                    $rows[] = ['symbol' => $element['symbol'], 'path' => $element['path'], 'line' => $element['line'], 'rank' => null, 'prerequisites' => array_keys($dependencies[$id] ?? []), 'reason' => 'Dependency cycle needs a joint migration decision; no arbitrary order is claimed.', 'expected' => $element['expected']];
                }
                break;
            }
            foreach ($ready as $id) {
                $element = $pending[$id];
                $rows[] = ['symbol' => $element['symbol'], 'path' => $element['path'], 'line' => $element['line'], 'rank' => $rank++, 'prerequisites' => array_keys($dependencies[$id] ?? []), 'reason' => 'Migrate known dependencies before their callers; verify dynamic paths and current audit separately.', 'expected' => $element['expected']];
                unset($pending[$id]);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'cmd' => 'architecture-target', 'ok' => false, 'm' => $code, 'msg' => $message, 'next' => ['fix:target_or_reference', 'rerun:architecture-target']];
    }
}
