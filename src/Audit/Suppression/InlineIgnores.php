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
        $inlineData = $this->inlineRules($comments);
        $inline = $inlineData['rules'];
        $inlineDirectives = $inlineData['directives'];
        $inlineOrigins = $inlineData['origins'];
        $fileRules = $this->fileRules($comments);
        $invalid = $this->invalidFindings($path, $inlineDirectives, $fileRules, $knownRules);
        $known = array_fill_keys($knownRules, true);
        $inline = array_map(
            fn (array $rules): array => array_values(array_filter($rules, fn (string $rule): bool => isset($known[$rule]))),
            $inline,
        );
        $inlineDirectives = array_map(
            fn (array $rules): array => array_values(array_filter($rules, fn (string $rule): bool => isset($known[$rule]))),
            $inlineDirectives,
        );
        $inlineOrigins = array_map(
            fn (array $origins): array => array_map(
                fn (array $rules): array => array_values(array_filter($rules, fn (string $rule): bool => isset($known[$rule]))),
                $origins,
            ),
            $inlineOrigins,
        );
        $fileRules = array_values(array_filter($fileRules, fn (string $rule): bool => isset($known[$rule])));
        $suppressed = 0;
        $usedInline = [];
        $remaining = [];

        foreach ($findings as $finding) {
            $inlineMatches = array_values(array_unique(array_merge(
                $inline[$finding->line] ?? [],
                $inline[$finding->line - 1] ?? [],
            )));

            if (
                in_array($finding->rule, $fileRules, true)
                || in_array($finding->rule, $inlineMatches, true)
            ) {
                $suppressed++;

                foreach ([$finding->line, $finding->line - 1] as $line) {
                    foreach ($inlineOrigins[$line] ?? [] as $originLine => $originRules) {
                        if (in_array($finding->rule, $originRules, true)) {
                            $usedInline[$originLine][$finding->rule] = true;
                        }
                    }
                }

                continue;
            }

            $remaining[] = $finding;
        }

        foreach ($inlineDirectives as $line => $rules) {
            foreach ($rules as $rule) {
                if (! isset($usedInline[$line][$rule])) {
                    $invalid[] = new AuditFinding(
                        'warn',
                        'invalid-suppression',
                        $path,
                        $line,
                        "Unused Architecture Kit suppression rule [{$rule}]; no finding matched this comment.",
                    );
                }
            }
        }

        return new SuppressionResult(
            findings: array_merge($remaining, $invalid),
            inline: $suppressed,
            baseline: 0,
        );
    }

    /**
     * @return array<int, array{text: string, line: int, end: int}>
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
                'end' => $token[2] + substr_count($token[1], "\n"),
            ];
        }

        return $comments;
    }

    /**
     * @param  array<int, array{text: string, line: int, end: int}>  $comments
     * @return array{
     *     rules: array<int, array<int, string>>,
     *     directives: array<int, array<int, string>>,
     *     origins: array<int, array<int, array<int, string>>>
     * }
     */
    private function inlineRules(array $comments): array
    {
        $rules = [];
        $directives = [];
        $origins = [];

        foreach ($comments as $comment) {
            if (preg_match_all('/@architecture-kit-ignore\s+([a-z0-9-]+)/', $comment['text'], $matches) === false) {
                continue;
            }

            $commentRules = array_values(array_unique($matches[1]));

            if ($commentRules === []) {
                continue;
            }

            $directives[$comment['line']] = array_values(array_unique(array_merge(
                $directives[$comment['line']] ?? [],
                $commentRules,
            )));

            for ($line = $comment['line']; $line <= $comment['end']; $line++) {
                $rules[$line] = array_values(array_unique(array_merge($rules[$line] ?? [], $commentRules)));
                $origins[$line][$comment['line']] = $commentRules;
            }
        }

        return [
            'rules' => $rules,
            'directives' => $directives,
            'origins' => $origins,
        ];
    }

    /**
     * @param  array<int, array{text: string, line: int, end: int}>  $comments
     * @return array<int, string>
     */
    private function fileRules(array $comments): array
    {
        $rules = [];

        foreach ($comments as $comment) {
            if (preg_match_all('/@architecture-kit-ignore-file\s+([a-z0-9-]+)/', $comment['text'], $matches) === false) {
                continue;
            }

            array_push($rules, ...$matches[1]);
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
