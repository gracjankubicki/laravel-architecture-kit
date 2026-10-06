<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use InvalidArgumentException;

/** One-to-one identity evidence. Similar declarations stay candidates until explicitly paired. */
final class SymbolPairs
{
    /** @var array<string, string> */
    public array $pairs = [];

    /** @var array<string, string> */
    public array $basis = [];

    /** @var array<string, true> */
    private array $used = [];

    public bool $limited = false;

    /** @var list<array<string, mixed>> */
    public array $candidates = [];

    /** @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @param array<string, string> $manual */
    public function __construct(array $before, array $after, array $manual = [])
    {
        foreach ($manual as $from => $to) {
            $from = strtolower(ltrim($from, '\\'));
            $to = strtolower(ltrim($to, '\\'));
            if (isset($this->pairs[$from]) || ! isset($before[$from], $after[$to]) || $before[$from]['php_kind'] !== $after[$to]['php_kind'] || isset($this->used[$to])) {
                throw new InvalidArgumentException('Manual pairs must identify available one-to-one symbols of the same kind.');
            }
            $this->pairs[$from] = $to;
            $this->used[$to] = true;
            $this->basis[$from] = 'manual';
        }
        foreach ($before as $id => $entry) {
            if (isset($after[$id]) && ! isset($this->pairs[$id]) && ! isset($this->used[$id])) {
                $this->pairs[$id] = $id;
                $this->used[$id] = true;
                $this->basis[$id] = 'same_symbol';
            }
        }
        $oldShapes = $newShapes = [];
        foreach ($before as $id => $entry) {
            if (! isset($this->pairs[$id]) && ! ($entry['ambiguous'] ?? false)) {
                $oldShapes[$entry['php_kind'].':'.$entry['shape']][] = $id;
            }
        }
        foreach ($after as $id => $entry) {
            if (! isset($this->used[$id]) && ! ($entry['ambiguous'] ?? false)) {
                $newShapes[$entry['php_kind'].':'.$entry['shape']][] = $id;
            }
        }
        foreach ($oldShapes as $shape => $ids) {
            $newIds = $newShapes[$shape] ?? [];
            if (count($ids) === 1 && count($newIds) === 1 && $before[$ids[0]]['auto_pair'] && $after[$newIds[0]]['auto_pair']) {
                $this->pairs[$ids[0]] = $newIds[0];
                $this->used[$newIds[0]] = true;
                $this->basis[$ids[0]] = 'unique_declaration_shape';
            }
        }
        // Class identity also establishes identity for unchanged method names.
        foreach ($before as $id => $entry) {
            if ($entry['php_kind'] !== 'method' || isset($this->pairs[$id])) {
                continue;
            }
            $owner = explode('::', $id)[0];
            $pairedOwner = $this->pairs[$owner] ?? null;
            if ($pairedOwner === null) {
                continue;
            }
            $target = $pairedOwner.substr($id, strlen($owner));
            if (isset($after[$target]) && ! isset($this->used[$target])) {
                $this->pairs[$id] = $target;
                $this->used[$target] = true;
                $this->basis[$id] = 'paired_owner_and_method_name';
            }
        }
        $pathEvidence = [];
        $inversePaths = [];
        foreach ($this->pairs as $from => $to) {
            if (in_array($before[$from]['php_kind'], ['file', 'method'], true)) {
                continue;
            }
            $oldPath = $before[$from]['path'];
            $newPath = $after[$to]['path'];
            $pathEvidence[$oldPath][$newPath] = true;
            $inversePaths[$newPath][$oldPath] = true;
        }
        foreach ($pathEvidence as $oldPath => $targets) {
            if (count($targets) !== 1) {
                continue;
            }
            $newPath = array_key_first($targets);
            $from = strtolower('(file) '.$oldPath);
            $to = strtolower('(file) '.$newPath);
            if (count($inversePaths[$newPath]) === 1 && isset($before[$from], $after[$to]) && ! isset($this->pairs[$from]) && ! isset($this->used[$to])) {
                $this->pairs[$from] = $to;
                $this->used[$to] = true;
                $this->basis[$from] = 'one_to_one_paired_declaration_files';
            }
        }
        $candidates = [];
        foreach ($after as $to => $new) {
            if (! isset($this->used[$to])) {
                $candidates['shape:'.$new['php_kind'].':'.$new['shape']][$to] = true;
                if ($new['php_kind'] !== 'file') {
                    $candidates['path:'.$new['php_kind'].':'.$new['path']][$to] = true;
                }
            }
        }
        foreach ($before as $from => $old) {
            if (isset($this->pairs[$from])) {
                continue;
            }
            $targets = [...($candidates['shape:'.$old['php_kind'].':'.$old['shape']] ?? []), ...($candidates['path:'.$old['php_kind'].':'.$old['path']] ?? [])];
            foreach (array_keys($targets) as $to) {
                if (count($this->candidates) >= 1000) {
                    $this->limited = true;

                    return;
                }
                $this->candidates[] = ['before' => $old, 'after' => $after[$to], 'certainty' => 'candidate', 'reason' => 'Similar declaration does not establish identity; use manual_pairs to confirm.'];
            }
        }
    }
}
