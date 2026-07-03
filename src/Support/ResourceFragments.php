<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use RuntimeException;
use Taqie\ArchitectureKit\Architecture;

final readonly class ResourceFragments
{
    /**
     * @param  array<int, string>  $enabledSlugs
     * @param  array<int, string>  $fragmentNames
     * @return array<int, string>
     */
    public function select(array $enabledSlugs, array $fragmentNames): array
    {
        $enabledSlugs = array_values(array_unique($enabledSlugs));
        $fragments = array_map(fn (string $name): array => $this->parse($name), $fragmentNames);
        $groups = [];

        foreach ($fragments as $fragment) {
            $groups[$fragment['group']][] = $fragment;
        }

        uasort($groups, function (array $left, array $right): int {
            $leftFirst = $left[0];
            $rightFirst = $right[0];

            return [$leftFirst['order'], $leftFirst['key']]
                <=> [$rightFirst['order'], $rightFirst['key']];
        });

        $selected = [];

        foreach ($groups as $group) {
            $winner = $this->selectFromGroup($group, $enabledSlugs);

            if ($winner !== null) {
                $selected[] = $winner['name'];
            }
        }

        return $selected;
    }

    /**
     * @return array<int, string>
     */
    public function knownSlugs(): array
    {
        return array_map(
            fn (Architecture $architecture): string => $architecture->value,
            Architecture::guidelineOrder(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $name): array
    {
        if (! preg_match('/^(?<order>\d+)-(?<key>[a-z0-9-]+)(?:\.(?<condition>default|when-[a-z0-9-]+))?\.md$/', $name, $matches)) {
            throw new RuntimeException("Architecture Kit resource fragment [{$name}] must match <order>-<key>[.default|.when-<slug>].md.");
        }

        $condition = ($matches['condition'] ?? '') !== '' ? $matches['condition'] : null;
        $slug = null;

        if (is_string($condition) && str_starts_with($condition, 'when-')) {
            $slug = substr($condition, 5);

            if (! in_array($slug, $this->knownSlugs(), true)) {
                throw new RuntimeException("Architecture Kit resource fragment [{$name}] references unknown architecture slug [{$slug}].");
            }
        }

        return [
            'name' => $name,
            'order' => (int) $matches['order'],
            'key' => $matches['key'],
            'condition' => $condition,
            'slug' => $slug,
            'group' => ((int) $matches['order']).'-'.$matches['key'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $group
     * @param  array<int, string>  $enabledSlugs
     * @return array<string, mixed>|null
     */
    private function selectFromGroup(array $group, array $enabledSlugs): ?array
    {
        $matching = array_values(array_filter(
            $group,
            fn (array $fragment): bool => is_string($fragment['slug']) && in_array($fragment['slug'], $enabledSlugs, true),
        ));

        if ($matching !== []) {
            usort($matching, fn (array $left, array $right): int => $this->priority((string) $right['slug']) <=> $this->priority((string) $left['slug']));

            return $matching[0];
        }

        foreach ($group as $fragment) {
            if ($fragment['condition'] === null || $fragment['condition'] === 'default') {
                return $fragment;
            }
        }

        return null;
    }

    private function priority(string $slug): int
    {
        $index = array_search($slug, $this->knownSlugs(), true);

        return is_int($index) ? $index : -1;
    }
}
