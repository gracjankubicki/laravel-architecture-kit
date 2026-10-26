<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** Select exactly one verified member of the configured Git family. */
final readonly class WorkspaceResolver
{
    public function __construct(private string $familyRoot) {}

    public function resolve(string $workspace): string
    {
        if (! str_starts_with($workspace, '/') || preg_match('~/\.\.?(/|$)~', $workspace)) {
            throw new RuntimeException('context_invalid: workspace must be an absolute directory without traversal.');
        }
        $workspace = realpath($workspace) ?: throw new RuntimeException('context_invalid: workspace is unavailable.');
        if (! is_dir($workspace)) {
            throw new RuntimeException('context_invalid: workspace must be a directory.');
        }
        $family = GitFamily::inspect($this->familyRoot);
        $candidates = [];
        foreach ($family->members as $member) {
            if ($workspace !== $member && ! str_starts_with($workspace, $member.'/') && ! str_starts_with($member, $workspace.'/')) {
                continue;
            }
            $verified = GitFamily::inspect($member);
            if ($verified->commonDirectory !== $family->commonDirectory || $verified->codeRoot !== $member || ! is_file($member.'/composer.json')) {
                throw new RuntimeException('context_invalid: inconsistent Git family member.');
            }
            $candidates[] = $member;
        }
        if (count($candidates) !== 1) {
            throw new RuntimeException('context_invalid: workspace must identify exactly one member of this Git family; found '.count($candidates).'.');
        }

        return $candidates[0];
    }
}
