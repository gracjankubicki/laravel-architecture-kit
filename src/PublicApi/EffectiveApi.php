<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use PhpParser\Node\Stmt\Class_;

/** Public exposure is keyed by its public owner, even when declared in an internal base. */
final class EffectiveApi
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];

    /** @var list<array<string, mixed>> */
    public array $typeExposures = [];

    /** @var list<array{code: string, path: string, message: string}> */
    public array $notices = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $resolved = [];

    private DeclarationBudget $budget;

    public function __construct(private readonly PhpContracts $catalog)
    {
        $this->budget = new DeclarationBudget;
        foreach ($catalog->classes as $key => $class) {
            if (! $this->claim($class['source']['path'])) {
                break;
            }
            $members = $this->members($key, []);
            if ($class['internal']) {
                continue;
            }
            $this->entries['class:'.$key] = [...$class, 'members' => [], 'traits' => $class['traits'], 'exposure' => $class['name']];
            foreach ($members as $id => $member) {
                if ($member['visibility'] === 'private' || $member['internal']) {
                    continue;
                }
                if (! $this->claim($class['source']['path'])) {
                    break;
                }
                $entryId = $key.'::'.$id;
                $this->entries[$entryId] = [...$member, 'owner' => $class['name'], 'exposure' => $class['name'],
                    'conditional' => $class['conditional'], 'ambiguous' => $class['ambiguous'] || ($member['ambiguous'] ?? false)];
                $this->types($entryId, $this->entries[$entryId]);
            }
        }
        foreach ($catalog->standalone as $id => $entry) {
            if (! $this->claim($entry['source']['path'])) {
                break;
            }
            if (! $entry['internal']) {
                $this->entries[$id] = $entry;
                $this->types($id, $entry);
            }
        }
        ksort($this->entries);
    }

    /** @internal Framework exposure selects a limited payload from these declaration facts.
     * @return array<string, array<string, mixed>> */
    public function exposedMembers(string $class): array
    {
        return $this->members(strtolower($class), []);
    }

    /** @param list<string> $visiting
     * @return array<string, array<string, mixed>> */
    private function members(string $key, array $visiting): array
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        $class = $this->catalog->classes[$key] ?? null;
        if ($class === null) {
            $this->notice('', 'External or unavailable inherited declaration: '.$key);

            return [];
        }
        if (in_array($key, $visiting, true) || count($visiting) >= 32) {
            $this->notice($class['source']['path'], 'Inheritance cycle or depth limit: '.$class['name']);

            return [];
        }
        $visiting[] = $key;
        $members = [];
        $parents = $class['kind'] === 'interface' ? $class['interfaces'] : ($class['parent'] === null ? [] : [$class['parent']]);
        foreach ($parents as $parent) {
            foreach ($this->members(strtolower($parent), $visiting) as $id => $member) {
                if (! $this->claim($class['source']['path'])) {
                    break 2;
                }
                if ($member['visibility'] !== 'private') {
                    $members[$id] = $this->expose($member, $class['name']);
                }
            }
        }
        $candidates = [];
        foreach ($class['traits'] as $trait) {
            foreach ($this->members(strtolower($trait), $visiting) as $id => $member) {
                if (! $this->claim($class['source']['path'])) {
                    break 2;
                }
                $candidates[$id][strtolower($trait)] = $this->expose($member, $class['name'], $trait);
            }
        }
        foreach ($class['adaptations'] as $adaptation) {
            if (! $this->claim($class['source']['path'])) {
                break;
            }
            if ($adaptation['kind'] !== 'precedence') {
                continue;
            }
            $id = 'method:'.strtolower($adaptation['method']);
            foreach ($adaptation['instead_of'] as $excluded) {
                unset($candidates[$id][strtolower($excluded)]);
            }
        }
        $traitMembers = [];
        foreach ($candidates as $id => $options) {
            if ($options === []) {
                continue;
            }
            $values = array_values($options);
            $member = $values[0];
            if (count($values) > 1 && ! isset($class['members'][$id])) {
                $contracts = array_map(static fn (array $value): array => array_diff_key($value, array_flip(['source', 'declared_in', 'via'])), $values);
                if (count(array_unique(array_map('serialize', $contracts))) > 1) {
                    $member['ambiguous'] = true;
                    $this->notice($class['source']['path'], 'Unresolved trait conflict: '.$class['name'].'::'.$id);
                }
            }
            $traitMembers[$id] = $member;
        }
        foreach ($class['adaptations'] as $adaptation) {
            if (! $this->claim($class['source']['path'])) {
                break;
            }
            if ($adaptation['kind'] !== 'alias') {
                continue;
            }
            $id = 'method:'.strtolower($adaptation['method']);
            $options = $candidates[$id] ?? [];
            // Qualified aliases may expose a method excluded by insteadof.
            if ($adaptation['trait'] !== null) {
                $trait = $adaptation['trait'];
                $selected = $this->members(strtolower($trait), $visiting)[$id] ?? null;
                $member = $selected === null ? null : $this->expose($selected, $class['name'], $trait);
            } else {
                $member = count($options) === 1 ? array_values($options)[0] : null;
            }
            if ($member === null) {
                $this->notice($class['source']['path'], 'Unresolved trait alias: '.$class['name'].'::'.$id);

                continue;
            }
            $modifier = $adaptation['modifier'] ?? 0;
            foreach (['private' => Class_::MODIFIER_PRIVATE, 'protected' => Class_::MODIFIER_PROTECTED, 'public' => Class_::MODIFIER_PUBLIC] as $visibility => $flag) {
                if (($modifier & $flag) !== 0) {
                    $member['visibility'] = $member['signature']['visibility'] = $visibility;
                }
            }
            if (($modifier & Class_::MODIFIER_FINAL) !== 0) {
                $member['signature']['final'] = true;
            }
            $alias = $adaptation['alias'];
            if ($alias !== null) {
                $member['name'] = $alias;
                $traitMembers['method:'.strtolower($alias)] = $member;
            } else {
                $traitMembers[$id] = $member;
            }
        }
        $members = [...$members, ...$traitMembers];
        foreach ($class['members'] as $id => $member) {
            if (! $this->claim($class['source']['path'])) {
                break;
            }
            $members[$id] = [...$member, 'declared_in' => $class['name'], 'via' => [$class['name']]];
        }
        ksort($members);

        return $this->resolved[$key] = $members;
    }

    /** @param array<string, mixed> $member
     * @return array<string, mixed> */
    private function expose(array $member, string $owner, ?string $trait = null): array
    {
        $member['via'] = [$owner, ...($member['via'] ?? [])];
        $rebind = static function (?string $type) use ($owner, $trait): ?string {
            if ($type === null) {
                return null;
            }
            $type = preg_replace('/@static:[a-z0-9_\\\\]+/i', '@static:'.strtolower($owner), $type);
            if ($trait !== null) {
                $type = preg_replace('/(?<![a-z0-9_\\\\])'.preg_quote(strtolower($trait), '/').'(?![a-z0-9_\\\\])/i', strtolower($owner), $type);
            }

            return $type;
        };
        if (isset($member['signature'])) {
            $member['signature']['return_type'] = $rebind($member['signature']['return_type']);
            foreach ($member['signature']['parameters'] as &$parameter) {
                $parameter['type'] = $rebind($parameter['type']);
            }
            unset($parameter);
        } elseif (isset($member['type'])) {
            $member['type'] = $rebind($member['type']);
        }

        return $member;
    }

    /** @param array<string, mixed> $entry */
    private function types(string $id, array $entry): void
    {
        $types = isset($entry['signature']) ? [...array_column($entry['signature']['parameters'], 'type'), $entry['signature']['return_type']] : [$entry['type'] ?? null];
        foreach ($types as $type) {
            if (! is_string($type)) {
                continue;
            }
            foreach (preg_split('/[?()|&]/', $type) ?: [] as $name) {
                $name = preg_replace('/^@static:/', '', $name);
                $class = $this->catalog->classes[strtolower($name)] ?? null;
                if ($class !== null && $class['internal']) {
                    if (! $this->claim($entry['source']['path'])) {
                        return;
                    }
                    $this->typeExposures[] = ['contract' => $id, 'type' => $class['name'], 'source' => $entry['source'],
                        'type_source' => $class['source'], 'scope' => 'type_identity_only'];
                }
            }
        }
    }

    /** @phpstan-impure */
    private function claim(string $path): bool
    {
        if ($this->budget->claim()) {
            return true;
        }
        if (! in_array('API exposure budget reached; recognized facts are partial.', array_column($this->notices, 'message'), true)) {
            $this->notice($path, 'API exposure budget reached; recognized facts are partial.');
        }

        return false;
    }

    private function notice(string $path, string $message): void
    {
        $this->notices[] = ['code' => 'E_API_EXPOSURE_UNRESOLVED', 'path' => $path, 'message' => $message];
    }
}
