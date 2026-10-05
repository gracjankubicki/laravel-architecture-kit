<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

/** Bounded lexical SQL recognition. Unknown syntax never becomes a complete verdict. */
final class DataSql
{
    /** @return array{effects: list<array<string, string>>, unknown: bool} */
    public function inspect(string $sql): array
    {
        if (strlen($sql) > 100000) {
            return ['effects' => [], 'unknown' => true];
        }
        $pattern = '~\G(?:\s+|--[^\r\n]*|/\*.*?\*/|\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`(?:``|[^`])*`|\[[^\]]*\]|[a-zA-Z_][a-zA-Z0-9_$]*|[0-9]+(?:\.[0-9]+)?|[(),.;=*<>+/?:%!|&-])~s';
        $tokens = [];
        $offset = 0;
        $lexicalUnknown = false;
        while ($offset < strlen($sql)) {
            if (! preg_match($pattern, $sql, $m, 0, $offset) || count($tokens) >= 10000) {
                $lexicalUnknown = true;
                break;
            }
            $offset += strlen($m[0]);
            $token = trim($m[0]);
            if ($token === '' || str_starts_with($token, '--') || str_starts_with($token, '/*')) {
                continue;
            }
            $tokens[] = $token;
        }
        $effects = [];
        $unknown = $lexicalUnknown || $tokens === [];
        $ctes = [];
        $first = strtolower($tokens[0] ?? '');
        if ($first === 'with') {
            // CTE names, including optional column lists, are not physical tables.
            $i = strtolower($tokens[1] ?? '') === 'recursive' ? 2 : 1;
            while ($i < count($tokens)) {
                $name = $this->identifier($tokens[$i]);
                if ($name === null) {
                    $unknown = true;
                    break;
                }
                $ctes[strtolower($name)] = true;
                $i++;
                if (($tokens[$i] ?? '') === '(') {
                    while ($i < count($tokens) && $tokens[$i] !== ')') {
                        $i++;
                    }
                    $i++;
                }
                if (strtolower($tokens[$i] ?? '') !== 'as' || ($tokens[$i + 1] ?? '') !== '(') {
                    $unknown = true;
                    break;
                }
                $i += 2;
                $nesting = 1;
                while ($i < count($tokens) && $nesting > 0) {
                    $nesting += $tokens[$i] === '(' ? 1 : ($tokens[$i] === ')' ? -1 : 0);
                    $i++;
                }
                if (($tokens[$i] ?? '') !== ',') {
                    break;
                }
                $i++;
            }
        }
        if (! in_array($first, ['select', 'with', 'insert', 'update', 'delete', 'replace', 'create', 'alter', 'drop', 'truncate'], true)) {
            $unknown = true;
        }
        $level = 0;
        $fromLevels = [];
        $statementsAtLevel = [];
        $statements = 0;
        foreach ($tokens as $i => $token) {
            $word = strtolower($token);
            if ($token === '(') {
                $level++;
            } elseif ($token === ')') {
                unset($fromLevels[$level]);
                unset($statementsAtLevel[$level]);
                $level--;
                $unknown = $unknown || $level < 0;
            } elseif ($token === ';' && $i < count($tokens) - 1) {
                $statements++;
                $unknown = true;
            }
            if (in_array($word, ['select', 'update', 'delete', 'insert', 'replace'], true)) {
                $statementsAtLevel[$level] = $word;
            }
            if (in_array($word, ['where', 'group', 'order', 'having', 'limit', 'union', 'set', 'returning'], true)) {
                unset($fromLevels[$level]);
            }
            $kind = null;
            if (($word === 'from' && in_array($statementsAtLevel[$level] ?? '', ['select', 'delete', 'update'], true)) || ($word === 'join' && isset($fromLevels[$level])) || ($token === ',' && isset($fromLevels[$level]))) {
                $kind = $word === 'from' && strtolower($tokens[$i - 1] ?? '') === 'delete' ? 'write' : 'read';
                $fromLevels[$level] = true;
            } elseif ($word === 'update' && in_array($first, ['update', 'with'], true) && strtolower($tokens[$i - 1] ?? '') !== 'key') {
                $kind = 'write';
                $fromLevels[$level] = true;
            } elseif ($word === 'into' && in_array($first, ['insert', 'replace', 'with'], true)) {
                $kind = 'write';
            } elseif ($word === 'table' && in_array($first, ['create', 'alter', 'drop'], true)) {
                $kind = 'schema';
            } elseif ($word === 'truncate') {
                $kind = 'write';
            }
            if ($kind === null) {
                continue;
            }
            $j = $i + 1;
            while (in_array(strtolower($tokens[$j] ?? ''), ['if', 'not', 'exists', 'table', 'only'], true)) {
                $j++;
            }
            if (($tokens[$j] ?? '') === '(') {
                if (strtolower($tokens[$j + 1] ?? '') !== 'select' && strtolower($tokens[$j + 1] ?? '') !== 'with') {
                    $unknown = true;
                }

                continue;
            }
            $table = $this->identifier($tokens[$j] ?? '');
            if ($table === null) {
                $unknown = true;

                continue;
            }
            while (($tokens[$j + 1] ?? '') === '.') {
                $part = $this->identifier($tokens[$j + 2] ?? '');
                if ($part === null) {
                    $unknown = true;
                    break;
                }
                $table .= '.'.$part;
                $j += 2;
            }
            if (($tokens[$j + 1] ?? '') === '(' && $kind === 'read') {
                $unknown = true;

                continue;
            }
            if (! isset($ctes[strtolower($table)])) {
                $effects[$kind.'|'.$table] = ['table' => $table, 'kind' => $kind];
            }
        }
        // Dialect-specific constructs, CTE column lists and multi-statements require inspection.
        $unknown = $unknown || $level !== 0 || $statements > 0 || (bool) preg_match('/\b(execute|merge|call|copy|lateral|using|recursive|conflict)\b/i', implode(' ', array_filter($tokens, fn ($t) => ! str_starts_with($t, "'"))));

        return ['effects' => array_values($effects), 'unknown' => $unknown || $effects === []];
    }

    private function identifier(string $token): ?string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]*$/D', $token)) {
            return $token;
        }
        if (preg_match('/^(?:`([^`]+)`|"([^"]+)"|\[([^\]]+)\])$/D', $token, $m)) {
            return end($m);
        }

        return null;
    }
}
