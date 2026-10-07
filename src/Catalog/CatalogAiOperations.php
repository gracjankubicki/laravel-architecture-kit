<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

final class CatalogAiOperations
{
    public const METHODS = ['prompt', 'stream', 'queue', 'broadcast', 'broadcastnow', 'broadcastonqueue'];

    /** Union of inspected Promptable declarations across the eleven admitted 0.8–0.11 patches. */
    public const TRAIT_METHODS = ['Laravel\\Ai\\Promptable' => [
        'assertneverprompted', 'assertneverqueued', 'assertnotprompted', 'assertnotqueued', 'assertprompted', 'assertpromptedtimes', 'assertqueued',
        'broadcast', 'broadcastnow', 'broadcastonqueue', 'extractpromptinput', 'fake', 'getdefaultmodelfor', 'getprovidersandmodels',
        'getprovidersandmodelsforfailover', 'gettimeout', 'isfaked', 'iterateproviderswithfailover', 'make', 'prompt',
        'providersforapprovalcontinuation', 'queue', 'recordagentfailover', 'stream', 'streamprompt', 'withmodelfailover',
    ]];

    /** @return list<string>|null */
    public static function invocationParameters(string $method): ?array
    {
        return match (strtolower($method)) {
            'prompt', 'stream' => ['prompt', 'attachments', 'provider', 'model', 'timeout'],
            'queue' => ['prompt', 'attachments', 'provider', 'model'],
            'broadcast' => ['prompt', 'channels', 'attachments', 'now', 'provider', 'model'],
            'broadcastnow', 'broadcastonqueue' => ['prompt', 'channels', 'attachments', 'provider', 'model'],
            default => null,
        };
    }

    /** Inspected file factory parameter names; values never enter catalog facts.
     * @return list<string>|null
     */
    public static function fileParameters(string $receiver, string $method): ?array
    {
        $type = strtolower($receiver);
        if (! in_array($type, ['laravel\ai\files\document', 'laravel\ai\files\image', 'laravel\ai\files\audio', 'laravel\ai\files\video'], true)) {
            return null;
        }
        $document = $type === 'laravel\ai\files\document';
        $audio = $type === 'laravel\ai\files\audio';

        return match ($method) {
            'frombase64' => ['base64', 'mimeType'],
            'frompath' => $document ? ['path'] : ['path', 'mimeType'],
            'fromurl' => $document || $type === 'laravel\ai\files\image' ? ['url'] : ['url', 'mimeType'],
            'fromstorage' => ['path', 'disk'],
            'fromupload' => $audio ? null : ['file', 'mimeType'],
            'fromid' => $document || $type === 'laravel\ai\files\image' ? ['id'] : null,
            'fromstring' => $document ? ['content', 'mimeType'] : null,
            default => null,
        };
    }

    public static function validateAttachments(mixed $attachments): void
    {
        if (! is_array($attachments) || array_keys($attachments) !== ['resolved', 'limited', 'files'] || ! is_bool($attachments['resolved']) || ! is_bool($attachments['limited'])
            || ! is_array($attachments['files']) || ! array_is_list($attachments['files']) || count($attachments['files']) > 128) {
            throw new InvalidArgumentException('Invalid AI attachment facts.');
        }
        foreach ($attachments['files'] as $file) {
            if (! is_array($file) || array_keys($file) !== ['receiver', 'method', 'valid', 'offset', 'line', 'end_line']
                || ! is_string($file['receiver']) || ! is_string($file['method']) || self::fileParameters($file['receiver'], $file['method']) === null
                || ! is_bool($file['valid']) || ! is_int($file['offset']) || $file['offset'] < 0
                || ! is_int($file['line']) || $file['line'] < 1 || ! is_int($file['end_line']) || $file['end_line'] < $file['line']) {
                throw new InvalidArgumentException('Invalid AI attachment factory descriptor.');
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    public static function validate(string $kind, array $metadata): void
    {
        if (in_array($kind, ['ai-response-callback', 'source-response-callback'], true) && (array_keys($metadata) !== ['method', 'chain', 'chain_valid', 'invocation_offset', 'target', 'end_offset', 'argument_forms', 'argument_offset', 'named_hash', 'named_method', 'chain_ends']
            || ! in_array($metadata['method'], ['each', 'then', 'catch'], true) || $metadata['invocation_offset'] !== null && (! is_int($metadata['invocation_offset']) || $metadata['invocation_offset'] < 0)
            || ! is_bool($metadata['chain_valid']) || ! is_int($metadata['end_offset']) || $metadata['end_offset'] < 0
            || ! is_array($metadata['chain']) || ! array_is_list($metadata['chain']) || $metadata['chain'] === [] || count($metadata['chain']) > 16
            || count(array_filter($metadata['chain'], fn ($method) => in_array($method, ['each', 'then', 'catch'], true))) !== count($metadata['chain'])
            || ! is_array($metadata['argument_forms']) || ! array_is_list($metadata['argument_forms']) || count($metadata['argument_forms']) !== count($metadata['chain'])
            || count(array_filter($metadata['argument_forms'], fn ($form) => in_array($form, ['closure', 'first-class', 'named', 'array', 'dynamic'], true))) !== count($metadata['argument_forms'])
            || $metadata['argument_offset'] !== null && (! is_int($metadata['argument_offset']) || $metadata['argument_offset'] < 0)
            || $metadata['named_hash'] !== null && (! is_string($metadata['named_hash']) || preg_match('/^[a-f0-9]{64}$/D', $metadata['named_hash']) !== 1)
            || ! is_array($metadata['chain_ends']) || ! array_is_list($metadata['chain_ends']) || count($metadata['chain_ends']) !== count($metadata['chain'])
            || count(array_filter($metadata['chain_ends'], fn ($offset) => is_int($offset) && $offset >= 0)) !== count($metadata['chain_ends'])
            || $metadata['target'] !== null && (! is_string($metadata['target']) || $metadata['target'] === ''))) {
            throw new InvalidArgumentException('Invalid AI response callback descriptor.');
        }
        if (in_array($kind, ['ai-response-callback', 'source-response-callback'], true) && $metadata['named_method'] !== null) {
            $selector = $metadata['named_method'];
            if (! is_array($selector) || array_keys($selector) !== ['receiver_hash', 'method_hash']) {
                throw new InvalidArgumentException('Invalid AI named method selector.');
            }
            foreach ($selector as $hash) {
                if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new InvalidArgumentException('Invalid AI named method hash.');
                }
            }
        }
        if ($kind === 'ai-declaration' && (array_keys($metadata) !== ['agent', 'tool'] || ! is_bool($metadata['agent']) || ! is_bool($metadata['tool']))) {
            throw new InvalidArgumentException('Invalid AI source relationship.');
        }
        if ($kind === 'ai-call-site' && (array_keys($metadata) !== ['method', 'valid', 'attachments_present', 'end_offset', 'attachments']
            || ! in_array($metadata['method'], self::METHODS, true) || ! is_bool($metadata['valid']) || ! is_bool($metadata['attachments_present'])
            || ! is_int($metadata['end_offset']) || $metadata['end_offset'] < 0)) {
            throw new InvalidArgumentException('Invalid AI source call shape.');
        }
        if ($kind === 'ai-call-site') {
            self::validateAttachments($metadata['attachments']);
        }
        if (in_array($kind, ['ai-tools', 'source-method-object-list'], true)) {
            if (array_keys($metadata) !== ['targets', 'resolved', 'conditional'] || ! is_array($metadata['targets']) || ! array_is_list($metadata['targets'])
                || count($metadata['targets']) > 128 || ! is_bool($metadata['resolved']) || ! is_bool($metadata['conditional'])) {
                throw new InvalidArgumentException('Invalid AI tools descriptor.');
            }
            foreach ($metadata['targets'] as $target) {
                if (! is_string($target) || $target === '' || strlen($target) > 1000) {
                    throw new InvalidArgumentException('Invalid AI tool target.');
                }
            }
        }
    }
}
