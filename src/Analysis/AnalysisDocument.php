<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use InvalidArgumentException;

/** Parser-independent facts. All byte ranges are zero-based and end-exclusive. */
final readonly class AnalysisDocument
{
    public const KINDS = ['class', 'interface', 'trait', 'enum', 'anonymous', 'method',
        'function-call', 'method-call', 'nullsafe-call', 'static-call',
        'function-callable', 'method-callable', 'static-callable', 'new'];

    /** @param list<array{kind: string, name: string, start: int, end: int, owner: int|null, receiver?: array{int, int}}> $facts
     * @param list<array{category: string, message: string, start: int|null, end: int|null}> $diagnostics
     * @param list<Node> $syntax */
    public function __construct(
        public SourceInput $source,
        public EngineDescriptor $engine,
        public array $facts,
        public array $diagnostics = [],
        public array $syntax = [],
    ) {
        if (! array_is_list($facts) || ! array_is_list($diagnostics)) {
            throw new InvalidArgumentException('Analysis facts and diagnostics must be lists.');
        }
        foreach ($facts as $fact) {
            if (! is_array($fact) || array_diff(array_keys($fact), ['kind', 'name', 'start', 'end', 'owner', 'receiver']) !== []
                || ! in_array($fact['kind'] ?? null, self::KINDS, true) || ! is_string($fact['name'] ?? null)
                || ! array_key_exists('owner', $fact)) {
                throw new InvalidArgumentException('Invalid neutral analysis fact.');
            }
            $this->assertRange($fact['start'] ?? null, $fact['end'] ?? null);
            if ($fact['owner'] !== null && (! is_int($fact['owner']) || $fact['owner'] < 0 || $fact['owner'] >= strlen($source->contents))) {
                throw new InvalidArgumentException('Invalid declaration owner offset.');
            }
            if (isset($fact['receiver'])) {
                if (! is_array($fact['receiver']) || ! array_is_list($fact['receiver']) || count($fact['receiver']) !== 2) {
                    throw new InvalidArgumentException('Invalid receiver range.');
                }
                $this->assertRange($fact['receiver'][0], $fact['receiver'][1]);
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (! is_array($diagnostic) || array_diff(array_keys($diagnostic), ['category', 'message', 'start', 'end']) !== []
                || ! in_array($diagnostic['category'] ?? null, ['syntax_error', 'unsupported_syntax'], true)
                || ! is_string($diagnostic['message'] ?? null) || $diagnostic['message'] === ''
                || ! array_key_exists('start', $diagnostic) || ! array_key_exists('end', $diagnostic)) {
                throw new InvalidArgumentException('Invalid analysis diagnostic.');
            }
            if ($diagnostic['start'] !== null || $diagnostic['end'] !== null) {
                $this->assertRange($diagnostic['start'], $diagnostic['end']);
            }
        }
        // Parser recovery can produce plausible but incomplete relationships. They
        // must never be mistaken for facts extracted from a valid file.
        if ($diagnostics !== [] && ($facts !== [] || $syntax !== [])) {
            throw new InvalidArgumentException('A file with syntax diagnostics must not expose recovered facts.');
        }
        if (! array_is_list($syntax)) {
            throw new InvalidArgumentException('Portable syntax must be a list.');
        }
        foreach ($syntax as $node) {
            if (! $node instanceof Node || $node->source !== $source) {
                throw new InvalidArgumentException('Portable syntax must belong to the analyzed snapshot.');
            }
        }
    }

    public function complete(): bool
    {
        return $this->diagnostics === [];
    }

    private function assertRange(mixed $start, mixed $end): void
    {
        if (! is_int($start) || ! is_int($end) || $start < 0 || $end < $start || $end > strlen($this->source->contents)) {
            throw new InvalidArgumentException('Analysis range is outside the source snapshot.');
        }
    }
}
