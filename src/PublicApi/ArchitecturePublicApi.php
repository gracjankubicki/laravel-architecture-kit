<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Revision\RevisionSources;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use InvalidArgumentException;
use RuntimeException;

final class ArchitecturePublicApi
{
    public function __construct(private readonly string $basePath) {}

    /** @param list<string> $publicPaths
     * @return array<string, mixed> */
    public function compare(string $from, string $to = 'working', array $publicPaths = [], ?string $version = null, ?string $zeroPolicy = null, int $limit = 50): array
    {
        if ($from === 'working' || $from === '' || $limit < 0 || $limit > 500 || count($publicPaths) > 50 || count(array_filter($publicPaths, 'is_string')) !== count($publicPaths)
            || ($zeroPolicy !== null && ! in_array($zeroPolicy, SemverAdvice::ZERO_POLICIES, true)) || ($version !== null && strlen($version) > 100)) {
            return self::error('E_PUBLIC_API_INPUT', 'Use a Git from revision, Git/working to state, at most 50 literal public paths, limit 0..500 and an explicit supported 0.x policy.');
        }
        try {
            $reader = new RevisionSources($this->basePath);
            $beforeSource = $reader->capture($from);
            $afterSource = $reader->capture($to);
            $before = $this->inventory($beforeSource, $publicPaths);
            $after = $this->inventory($afterSource, $publicPaths);
            $comparator = new ContractChanges;
            $changes = $comparator->compare($before['entries'], $after['entries'], $before['notices'] === [], $after['notices'] === []);
            $internal = $comparator->compare($before['internal'], $after['internal']);
            foreach ($internal as $row) {
                $row['verdict'] = 'internal';
                $row['reasons'] = ['Private or @internal declaration changed; public exposures are evaluated separately.'];
                $changes[] = $row;
            }
            $exposureChanges = $comparator->compare($before['types'], $after['types']);
            foreach ($exposureChanges as $row) {
                $row['verdict'] = 'check';
                $row['reasons'] = ['An internal type exposed by a public signature changed or became unavailable. Its other members are not publicized.'];
                $changes[] = $row;
            }
            $fresh = $reader->isFresh($afterSource);
            $notices = [...array_map(static fn (array $n): array => [...$n, 'state' => 'before'], $before['notices']),
                ...array_map(static fn (array $n): array => [...$n, 'state' => 'after'], $after['notices'])];
            if (! $fresh) {
                $notices[] = ['code' => 'E_PUBLIC_API_STALE', 'path' => '', 'message' => 'Working sources changed during analysis. Rerun before using the result.', 'state' => 'after'];
            }
            $totals = array_fill_keys(['breaking', 'compatible', 'check', 'internal'], 0);
            foreach ($changes as $row) {
                $totals[$row['verdict']]++;
            }
            $complete = $notices === [];
            $declaredVersion = $before['composer']['version'] ?? null;
            $currentVersion = $version ?? (is_string($declaredVersion) ? $declaredVersion : (preg_match('/^v?\d+\.\d+\.\d+$/D', $from) ? $from : null));
            $advice = SemverAdvice::forChanges($changes, $currentVersion, $zeroPolicy, $complete);
            if (! $fresh) {
                $advice['recommendation'] = null;
                $advice['minimum_only'] = false;
                $advice['reason'] = 'Working sources changed; rerun before version decisions.';
            }

            return ['v' => 1, 'cmd' => 'public-api', 'ok' => true, 'sources' => ['before' => $beforeSource->identity(), 'after' => $afterSource->identity()],
                'analysis' => ['status' => $complete ? 'complete' : 'incomplete', 'fresh' => $fresh, 'total_is_lower_bound' => ! $complete,
                    'limit' => $limit, 'truncated' => count($changes) > $limit || count($notices) > $limit, 'notice_total' => count($notices), 'public_paths' => $publicPaths],
                'changes' => array_slice($changes, 0, $limit), 'totals' => $totals, 'notices' => array_slice($notices, 0, $limit), 'semver' => $advice,
                'limitations' => ['Static declarations do not prove behavioural compatibility, runtime registration or existing installation safety.',
                    'External/vendor inheritance, dynamic autoloading and dynamic declarations are unresolved, never executed.',
                    'Only declared MCP response schemas are analyzed; handler return behaviour is not inferred.',
                    'No source, Git index, checkout, version or registry is modified. No patch release is proved by unchanged contracts.'],
                'next' => $fresh ? ['inspect:changes_and_notices', 'test:affected_consumers', 'review:release_policy'] : ['rerun:public-api']];
        } catch (InvalidArgumentException $e) {
            return self::error('E_PUBLIC_API_INPUT', $e->getMessage());
        } catch (RuntimeException $e) {
            return self::error('E_PUBLIC_API_SOURCE', $e->getMessage());
        }
    }

    /** @param list<string> $areas
     * @return array<string, mixed> */
    private function inventory(SourceSnapshot $source, array $areas): array
    {
        $surface = new AutoloadSurface($source, $areas);
        $php = new PhpContracts($source, $surface);
        $api = new EffectiveApi($php);
        $framework = new FrameworkContracts($source, $surface, $php);
        $internal = $types = [];
        foreach ($php->classes as $key => $class) {
            if ($class['internal']) {
                $internal['internal:class:'.$key] = [...$class, 'members' => []];
            }
            foreach ($class['members'] as $id => $member) {
                if ($class['internal'] || $member['internal'] || $member['visibility'] === 'private') {
                    $internal['internal:'.$key.'::'.$id] = $member;
                }
            }
        }
        foreach ($php->standalone as $id => $entry) {
            if ($entry['internal']) {
                $internal['internal:'.$id] = $entry;
            }
        }
        foreach ($api->typeExposures as $exposure) {
            $class = $php->classes[strtolower($exposure['type'])];
            $types['type:'.$exposure['contract'].'->'.strtolower($exposure['type'])] = [
                'kind' => 'type_identity', 'name' => $class['name'], 'type_kind' => $class['kind'], 'parent' => $class['parent'],
                'interfaces' => $class['interfaces'], 'backing_type' => $class['backing_type'], 'source' => $class['source']];
        }
        $entries = [...$api->entries, ...$framework->entries];
        $notices = [...$source->notices, ...$surface->notices, ...$php->notices, ...$api->notices, ...$framework->notices];
        if (count($entries) + count($internal) + count($types) > 10000) {
            $entries = array_slice($entries, 0, 8000, true);
            $internal = array_slice($internal, 0, 1000, true);
            $types = array_slice($types, 0, 1000, true);
            $notices[] = ['code' => 'E_PUBLIC_API_LIMIT', 'path' => '', 'message' => 'API declaration budget reached; recognized rows are lower bounds.'];
        }

        return ['entries' => $entries, 'internal' => $internal, 'types' => $types, 'notices' => $notices, 'composer' => $surface->composer];
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'cmd' => 'public-api', 'ok' => false, 'm' => $code, 'msg' => $message,
            'next' => ['fix:source_or_input', 'rerun:public-api']];
    }
}
