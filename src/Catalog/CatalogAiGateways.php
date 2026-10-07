<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Inspected gateway signatures across the admitted SDK patches, without argument values. */
final class CatalogAiGateways
{
    public const GETTERS = ['audiogateway', 'embeddinggateway', 'filegateway', 'imagegateway', 'rerankinggateway', 'storegateway', 'textgateway', 'transcriptiongateway'];

    public const SETTERS = ['useaudiogateway', 'useembeddinggateway', 'usefilegateway', 'useimagegateway', 'usererankinggateway', 'usestoregateway', 'usetextgateway', 'usetranscriptiongateway'];

    public const METHODS = ['addfile', 'createstore', 'deletefile', 'deletestore', 'generateaudio', 'generateembeddings', 'generateimage', 'generatestreamstep', 'generatetext', 'generatetextstep', 'generatetranscription', 'getfile', 'getstore', 'ontoolinvocation', 'putfile', 'removefile', 'rerank', 'streamtext'];

    private const STEPTEXTGATEWAY = [
        'generatetextstep' => ['parameters' => ['provider', 'model', 'instructions', 'messages', 'tools', 'schema', 'options', 'timeout', 'stepContext'], 'required' => ['provider', 'model', 'instructions', 'messages', 'tools', 'schema', 'options', 'timeout', 'stepContext']],
        'generatestreamstep' => ['parameters' => ['invocationId', 'provider', 'model', 'instructions', 'messages', 'tools', 'schema', 'options', 'timeout', 'stepContext'], 'required' => ['invocationId', 'provider', 'model', 'instructions', 'messages', 'tools', 'schema', 'options', 'timeout', 'stepContext']],
    ];

    private const FILEGATEWAY = [
        'getfile' => ['parameters' => ['provider', 'fileId'], 'required' => ['provider', 'fileId']],
        'putfile' => ['parameters' => ['provider', 'file'], 'required' => ['provider', 'file']],
        'deletefile' => ['parameters' => ['provider', 'fileId'], 'required' => ['provider', 'fileId']],
    ];

    private const AUDIOGATEWAY = [
        'generateaudio' => ['parameters' => ['provider', 'model', 'text', 'voice', 'instructions', 'timeout'], 'required' => ['provider', 'model', 'text', 'voice']],
    ];

    private const IMAGEGATEWAY = [
        'generateimage' => ['parameters' => ['provider', 'model', 'prompt', 'attachments', 'size', 'quality', 'timeout'], 'required' => ['provider', 'model', 'prompt']],
    ];

    private const EMBEDDINGGATEWAY = [
        'generateembeddings' => ['parameters' => ['provider', 'model', 'inputs', 'dimensions', 'timeout', 'providerOptions'], 'required' => ['provider', 'model', 'inputs', 'dimensions']],
    ];

    private const TRANSCRIPTIONGATEWAY = [
        'generatetranscription' => ['parameters' => ['provider', 'model', 'audio', 'language', 'diarize', 'timeout', 'providerOptions'], 'required' => ['provider', 'model', 'audio']],
    ];

    private const STOREGATEWAY = [
        'getstore' => ['parameters' => ['provider', 'storeId'], 'required' => ['provider', 'storeId']],
        'createstore' => ['parameters' => ['provider', 'name', 'description', 'fileIds', 'expiresWhenIdleFor'], 'required' => ['provider', 'name']],
        'addfile' => ['parameters' => ['provider', 'storeId', 'fileId', 'metadata'], 'required' => ['provider', 'storeId', 'fileId']],
        'removefile' => ['parameters' => ['provider', 'storeId', 'documentId'], 'required' => ['provider', 'storeId', 'documentId']],
        'deletestore' => ['parameters' => ['provider', 'storeId'], 'required' => ['provider', 'storeId']],
    ];

    private const RERANKINGGATEWAY = [
        'rerank' => ['parameters' => ['provider', 'model', 'documents', 'query', 'limit'], 'required' => ['provider', 'model', 'documents', 'query']],
    ];

    private const TEXTGATEWAY = [
        'generatetext' => ['parameters' => ['provider', 'model', 'instructions', 'messages', 'tools', 'schema', 'options', 'timeout'], 'required' => ['provider', 'model', 'instructions']],
        'streamtext' => ['parameters' => ['invocationId', 'provider', 'model', 'instructions', 'messages', 'tools', 'schema', 'options', 'timeout'], 'required' => ['invocationId', 'provider', 'model', 'instructions']],
        'ontoolinvocation' => ['parameters' => ['invoking', 'invoked'], 'required' => ['invoking', 'invoked']],
    ];

    /** @return array<string, array<string, array{parameters: list<string>, required: list<string>}>> */
    public static function contracts(string $version): array
    {
        $text = version_compare($version, '0.9.0.0', '<') ? self::TEXTGATEWAY : self::STEPTEXTGATEWAY;
        $contracts = [
            'Laravel\\Ai\\Contracts\\Gateway\\AudioGateway' => self::AUDIOGATEWAY,
            'Laravel\\Ai\\Contracts\\Gateway\\EmbeddingGateway' => self::EMBEDDINGGATEWAY,
            'Laravel\\Ai\\Contracts\\Gateway\\ImageGateway' => self::IMAGEGATEWAY,
            'Laravel\\Ai\\Contracts\\Gateway\\TranscriptionGateway' => self::TRANSCRIPTIONGATEWAY,
            'Laravel\\Ai\\Contracts\\Gateway\\FileGateway' => self::FILEGATEWAY,
            'Laravel\\Ai\\Contracts\\Gateway\\StoreGateway' => self::STOREGATEWAY,
            'Laravel\\Ai\\Contracts\\Gateway\\RerankingGateway' => self::RERANKINGGATEWAY,
        ];
        $contracts[version_compare($version, '0.9.0.0', '<') ? 'Laravel\\Ai\\Contracts\\Gateway\\TextGateway' : 'Laravel\\Ai\\Contracts\\Gateway\\StepTextGateway'] = $text;
        $contracts['Laravel\\Ai\\Contracts\\Gateway\\Gateway'] = [...self::AUDIOGATEWAY, ...self::EMBEDDINGGATEWAY, ...self::IMAGEGATEWAY, ...self::TRANSCRIPTIONGATEWAY, ...$text];

        return $contracts;
    }

    public static function validateSite(mixed $metadata): void
    {
        if (! is_array($metadata) || array_keys($metadata) !== ['method', 'arguments', 'limited', 'end_offset']
            || ! in_array($metadata['method'], [...self::METHODS, ...self::SETTERS, ...self::GETTERS], true) || ! is_bool($metadata['limited'])
            || ! is_int($metadata['end_offset']) || $metadata['end_offset'] < 0
            || ! is_array($metadata['arguments']) || ! array_is_list($metadata['arguments']) || count($metadata['arguments']) > 128) {
            throw new \InvalidArgumentException('Invalid AI gateway source call.');
        }
        foreach ($metadata['arguments'] as $argument) {
            if (! is_array($argument) || array_keys($argument) !== ['name', 'unpack', 'by_ref', 'callback']
                || $argument['name'] !== null && (! is_string($argument['name']) || $argument['name'] === '' || strlen($argument['name']) > 1000)
                || ! is_bool($argument['unpack']) || ! is_bool($argument['by_ref'])
                || $argument['callback'] !== null && (! is_string($argument['callback']) || $argument['callback'] === '')) {
                throw new \InvalidArgumentException('Invalid AI gateway argument shape.');
            }
        }
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>|null  $signature
     */
    public static function sourceArguments(array $site, ?array $signature): bool
    {
        if ($signature === null || ! $signature['complete'] || $site['metadata']['limited']) {
            return false;
        }
        $parameters = $signature['parameters'];
        $names = array_column($parameters, 'name');
        $seen = [];
        $named = false;
        $variadic = $parameters !== [] && $parameters[array_key_last($parameters)]['variadic'];
        foreach ($site['metadata']['arguments'] as $position => $argument) {
            $name = $argument['name'] ?? ($names[$position] ?? null);
            if ($argument['unpack'] || $argument['by_ref'] || $named && $argument['name'] === null || $name !== null && isset($seen[$name])) {
                return false;
            }
            if (($name === null || ! in_array($name, $names, true)) && ! $variadic) {
                return false;
            }
            if ($name !== null) {
                $seen[$name] = true;
            }
            $named = $named || $argument['name'] !== null;
        }
        foreach ($parameters as $parameter) {
            if ($parameter['required'] && ! isset($seen[$parameter['name']])) {
                return false;
            }
        }

        return true;
    }
}
