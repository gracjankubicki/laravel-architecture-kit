<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Suppression;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;

final class InlineIgnores
{
    /**
     * @param  array<int, AuditFinding>  $findings
     * @param  array<int, string>  $knownRules
     */
    public function apply(string $path, string $contents, array $findings, array $knownRules): SuppressionResult
    {
        $comments = $this->comments($contents);
        $inline = $this->inlineRules($comments);
        $fileRules = $this->fileRules($comments);
        $invalid = $this->invalidFindings($path, $inline, $fileRules, $knownRules);
        $suppressed = 0;
        $remaining = [];

        foreach ($findings as $finding) {
            if (
                in_array($finding->rule, $fileRules, true)
                || in_array($finding->rule, $inline[$finding->line] ?? [], true)
                || in_array($finding->rule, $inline[$finding->line - 1] ?? [], true)
            ) {
                $suppressed++;

                continue;
            }

            $remaining[] = $finding;
        }

        return new SuppressionResult(
            findings: array_merge($remaining, $invalid),
            inline: $suppressed,
            baseline: 0,
        );
    }

    /**
     * @return array<int, array{text: string, line: int}>
     */
    private function comments(string $contents): array
    {
        $comments = [];

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $comments[] = [
                'text' => $token[1],
                'line' => $token[2],
            ];
        }

        return $comments;
    }

    /**
     * @param  array<int, array{text: string, line: int}>  $comments
     * @return array<int, array<int, string>>
     */
    private function inlineRules(array $comments): array
    {
        $rules = [];

        foreach ($comments as $comment) {
            if (! preg_match('/@architecture-kit-ignore\s+([a-z0-9-]+)/', $comment['text'], $match)) {
                continue;
            }

            $rules[$comment['line']][] = $match[1];
        }

        return $rules;
    }

    /**
     * @param  array<int, array{text: string, line: int}>  $comments
     * @return array<int, string>
     */
    private function fileRules(array $comments): array
    {
        $rules = [];

        foreach ($comments as $comment) {
            if (! preg_match('/@architecture-kit-ignore-file\s+([a-z0-9-]+)/', $comment['text'], $match)) {
                continue;
            }

            $rules[] = $match[1];
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param  array<int, array<int, string>>  $inline
     * @param  array<int, string>  $fileRules
     * @param  array<int, string>  $knownRules
     * @return array<int, AuditFinding>
     */
    private function invalidFindings(string $path, array $inline, array $fileRules, array $knownRules): array
    {
        $findings = [];

        foreach ($inline as $line => $rules) {
            foreach ($rules as $rule) {
                if (! in_array($rule, $knownRules, true)) {
                    $findings[] = new AuditFinding('warn', 'invalid-suppression', $path, $line, "Unknown Architecture Kit suppression rule [{$rule}].");
                }
            }
        }

        foreach ($fileRules as $rule) {
            if (! in_array($rule, $knownRules, true)) {
                $findings[] = new AuditFinding('warn', 'invalid-suppression', $path, 1, "Unknown Architecture Kit file suppression rule [{$rule}].");
            }
        }

        return $findings;
    }
}
