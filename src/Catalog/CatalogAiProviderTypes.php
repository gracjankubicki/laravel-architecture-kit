<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Inspected provider setter capabilities, separate from remote service capabilities. */
final class CatalogAiProviderTypes
{
    /** Inspected lazy factory types; availability is gated by provider channels. */
    private const DEFAULT_GATEWAYS = [
        'Laravel\\Ai\\Providers\\AnthropicProvider::filegateway' => ['Laravel\\Ai\\Gateway\\Anthropic\\AnthropicFileGateway', null],
        'Laravel\\Ai\\Providers\\AzureOpenAiProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiGateway', 'azureGateway'],
        'Laravel\\Ai\\Providers\\AzureOpenAiProvider::filegateway' => ['Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiFileGateway', null],
        'Laravel\\Ai\\Providers\\AzureOpenAiProvider::imagegateway' => ['Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiGateway', 'azureGateway'],
        'Laravel\\Ai\\Providers\\AzureOpenAiProvider::storegateway' => ['Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiStoreGateway', null],
        'Laravel\\Ai\\Providers\\AzureOpenAiProvider::textgateway' => ['Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiGateway', 'azureGateway'],
        'Laravel\\Ai\\Providers\\BedrockProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\Bedrock\\BedrockTextGateway', null],
        'Laravel\\Ai\\Providers\\BedrockProvider::imagegateway' => ['Laravel\\Ai\\Gateway\\Bedrock\\BedrockImageGateway', null],
        'Laravel\\Ai\\Providers\\BedrockProvider::rerankinggateway' => ['Laravel\\Ai\\Gateway\\Bedrock\\BedrockRerankingGateway', null],
        'Laravel\\Ai\\Providers\\BedrockProvider::textgateway' => ['Laravel\\Ai\\Gateway\\Bedrock\\BedrockTextGateway', null],
        'Laravel\\Ai\\Providers\\CohereProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\CohereGateway', null],
        'Laravel\\Ai\\Providers\\CohereProvider::rerankinggateway' => ['Laravel\\Ai\\Gateway\\CohereGateway', null],
        'Laravel\\Ai\\Providers\\DeepSeekProvider::textgateway' => ['Laravel\\Ai\\Gateway\\DeepSeek\\DeepSeekGateway', null],
        'Laravel\\Ai\\Providers\\ElevenLabsProvider::audiogateway' => ['Laravel\\Ai\\Gateway\\ElevenLabsGateway', null],
        'Laravel\\Ai\\Providers\\ElevenLabsProvider::transcriptiongateway' => ['Laravel\\Ai\\Gateway\\ElevenLabsGateway', null],
        'Laravel\\Ai\\Providers\\GeminiProvider::filegateway' => ['Laravel\\Ai\\Gateway\\Gemini\\GeminiFileGateway', null],
        'Laravel\\Ai\\Providers\\GeminiProvider::storegateway' => ['Laravel\\Ai\\Gateway\\Gemini\\GeminiStoreGateway', null],
        'Laravel\\Ai\\Providers\\GroqProvider::textgateway' => ['Laravel\\Ai\\Gateway\\Groq\\GroqGateway', null],
        'Laravel\\Ai\\Providers\\GroqProvider::transcriptiongateway' => ['Laravel\\Ai\\Gateway\\Groq\\GroqGateway', null],
        'Laravel\\Ai\\Providers\\JinaProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\JinaGateway', null],
        'Laravel\\Ai\\Providers\\JinaProvider::rerankinggateway' => ['Laravel\\Ai\\Gateway\\JinaGateway', null],
        'Laravel\\Ai\\Providers\\MistralProvider::audiogateway' => ['Laravel\\Ai\\Gateway\\Mistral\\MistralGateway', 'mistralGateway'],
        'Laravel\\Ai\\Providers\\MistralProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\Mistral\\MistralGateway', 'mistralGateway'],
        'Laravel\\Ai\\Providers\\MistralProvider::textgateway' => ['Laravel\\Ai\\Gateway\\Mistral\\MistralGateway', 'mistralGateway'],
        'Laravel\\Ai\\Providers\\MistralProvider::transcriptiongateway' => ['Laravel\\Ai\\Gateway\\Mistral\\MistralGateway', 'mistralGateway'],
        'Laravel\\Ai\\Providers\\OllamaProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\Ollama\\OllamaGateway', 'ollamaGateway'],
        'Laravel\\Ai\\Providers\\OllamaProvider::textgateway' => ['Laravel\\Ai\\Gateway\\Ollama\\OllamaGateway', 'ollamaGateway'],
        'Laravel\\Ai\\Providers\\OpenAiCompatibleProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\OpenAiCompatible\\OpenAiCompatibleGateway', null],
        'Laravel\\Ai\\Providers\\OpenAiCompatibleProvider::textgateway' => ['Laravel\\Ai\\Gateway\\OpenAiCompatible\\OpenAiCompatibleGateway', null],
        'Laravel\\Ai\\Providers\\OpenAiCompatibleProvider::transcriptiongateway' => ['Laravel\\Ai\\Gateway\\OpenAiCompatible\\OpenAiCompatibleGateway', null],
        'Laravel\\Ai\\Providers\\OpenAiProvider::filegateway' => ['Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway', null],
        'Laravel\\Ai\\Providers\\OpenAiProvider::storegateway' => ['Laravel\\Ai\\Gateway\\OpenAi\\OpenAiStoreGateway', null],
        'Laravel\\Ai\\Providers\\OpenRouterProvider::audiogateway' => ['Laravel\\Ai\\Gateway\\OpenRouter\\OpenRouterGateway', null],
        'Laravel\\Ai\\Providers\\OpenRouterProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\OpenRouter\\OpenRouterGateway', null],
        'Laravel\\Ai\\Providers\\OpenRouterProvider::imagegateway' => ['Laravel\\Ai\\Gateway\\OpenRouter\\OpenRouterGateway', null],
        'Laravel\\Ai\\Providers\\OpenRouterProvider::textgateway' => ['Laravel\\Ai\\Gateway\\OpenRouter\\OpenRouterGateway', null],
        'Laravel\\Ai\\Providers\\OpenRouterProvider::transcriptiongateway' => ['Laravel\\Ai\\Gateway\\OpenRouter\\OpenRouterGateway', null],
        'Laravel\\Ai\\Providers\\VoyageAiProvider::embeddinggateway' => ['Laravel\\Ai\\Gateway\\VoyageAi\\VoyageAiGateway', null],
        'Laravel\\Ai\\Providers\\VoyageAiProvider::rerankinggateway' => ['Laravel\\Ai\\Gateway\\VoyageAi\\VoyageAiGateway', null],
        'Laravel\\Ai\\Providers\\XaiProvider::imagegateway' => ['Laravel\\Ai\\Gateway\\Xai\\XaiImageGateway', null],
        'Laravel\\Ai\\Providers\\XaiProvider::textgateway' => ['Laravel\\Ai\\Gateway\\Xai\\XaiGateway', null],
    ];

    /** @return array<string, list<string>> */
    private static function channels(string $version): array
    {
        $channels = [
            'Anthropic' => ['File', 'Text'],
            'AzureOpenAi' => ['Embedding', 'Image', 'Text'],
            'Bedrock' => ['Embedding', 'Image', 'Text'],
            'Cohere' => ['Embedding', 'Reranking'],
            'DeepSeek' => ['Text'],
            'ElevenLabs' => ['Audio', 'Transcription'],
            'Gemini' => ['Audio', 'Embedding', 'File', 'Image', 'Store', 'Text', 'Transcription'],
            'Groq' => ['Text'],
            'Jina' => ['Embedding', 'Reranking'],
            'Mistral' => ['Embedding', 'Text', 'Transcription'],
            'Ollama' => ['Embedding', 'Text'],
            'OpenAi' => ['Audio', 'Embedding', 'File', 'Image', 'Store', 'Text', 'Transcription'],
            'OpenRouter' => ['Audio', 'Embedding', 'Image', 'Text', 'Transcription'],
            'VoyageAi' => ['Embedding', 'Reranking'],
            'Xai' => ['Image', 'Text'],
        ];
        if (version_compare($version, '0.9.0.0', '>=')) {
            $channels['AzureOpenAi'] = ['Embedding', 'File', 'Image', 'Store', 'Text'];
            $channels['OpenAiCompatible'] = ['Text'];
        }
        if (version_compare($version, '0.10.3.0', '>=')) {
            $channels['OpenAiCompatible'] = ['Embedding', 'Text'];
        }
        if (version_compare($version, '0.11.0.0', '>=')) {
            $channels['Groq'][] = 'Transcription';
            $channels['OpenAiCompatible'][] = 'Transcription';
        }
        if (version_compare($version, '0.11.1.0', '>=')) {
            $channels['Bedrock'][] = 'Reranking';
            $channels['Mistral'][] = 'Audio';
        }
        $types = [];
        foreach ($channels as $name => $supported) {
            $types['Laravel\\Ai\\Providers\\'.$name.'Provider'] = $supported;
        }

        return $types;
    }

    /** @return list<string> */
    public static function ancestors(CatalogIndex $index, string $type, string $version): array
    {
        $ids = $index->namedTypes($type);
        if (count($ids) > 1 || $index->namedTypes('Laravel\\Ai\\Providers\\Provider') !== []) {
            return [];
        }
        $found = [];
        foreach (self::channels($version) as $sdk => $channels) {
            if ($index->namedTypes($sdk) === [] && (strcasecmp($type, $sdk) === 0 || count($ids) === 1 && $index->hasContract($ids[0], $sdk))) {
                $found[] = $sdk;
            }
        }

        return $found;
    }

    public static function matches(CatalogIndex $index, string $type, string $channel, string $version): bool
    {
        $contract = 'Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider';
        $ids = $index->namedTypes($type);
        if (strcasecmp($type, $contract) === 0 || count($ids) === 1 && $index->hasContract($ids[0], $contract)) {
            return true;
        }
        foreach (self::ancestors($index, $type, $version) as $sdk) {
            if (in_array($channel, self::channels($version)[$sdk], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<string>> */
    public static function gatewayTraits(string $version): array
    {
        $inventory = [];
        foreach (['Audio', 'Embedding', 'File', 'Image', 'Reranking', 'Store', 'Text', 'Transcription'] as $channel) {
            $inventory['Laravel\\Ai\\Providers\\Concerns\\Has'.$channel.'Gateway'] = [strtolower($channel).'gateway', 'use'.strtolower($channel).'gateway'];
        }
        if (version_compare($version, '0.9.0.0', '>=')) {
            $inventory['Laravel\\Ai\\Providers\\Concerns\\HasTextGateway'][] = 'textgenerationloop';
        }

        return $inventory;
    }

    public static function standardSetter(CatalogIndex $index, string $type, string $channel, string $version): bool
    {
        if ($index->namedTypes('Laravel\\Ai\\Providers\\Concerns\\Has'.$channel.'Gateway') !== []) {
            return false;
        }
        foreach (self::ancestors($index, $type, $version) as $sdk) {
            if (in_array($channel, self::channels($version)[$sdk], true)) {
                return true;
            }
        }

        return false;
    }

    public static function inheritedGatewayConstructor(CatalogIndex $index, string $type, string $version): bool
    {
        return array_intersect(self::ancestors($index, $type, $version), [
            'Laravel\\Ai\\Providers\\AnthropicProvider', 'Laravel\\Ai\\Providers\\GeminiProvider', 'Laravel\\Ai\\Providers\\OpenAiProvider',
        ]) !== [];
    }

    public static function configConstructor(CatalogIndex $index, string $type, string $version): bool
    {
        return self::ancestors($index, $type, $version) !== [] && ! self::inheritedGatewayConstructor($index, $type, $version);
    }

    /** @return list<string> */
    public static function sdkChannels(string $sdk, string $version): array
    {
        return self::channels($version)[$sdk] ?? [];
    }

    /** @return array{type: string, helper: ?string, source_shadowed: bool}|null */
    public static function defaultGateway(CatalogIndex $index, string $type, string $channel, string $version): ?array
    {
        foreach (self::ancestors($index, $type, $version) as $sdk) {
            if (! in_array($channel, self::channels($version)[$sdk], true)) {
                continue;
            }
            $factory = self::DEFAULT_GATEWAYS[$sdk.'::'.strtolower($channel).'gateway'] ?? null;
            if ($factory !== null) {
                return ['type' => $factory[0], 'helper' => $factory[1], 'source_shadowed' => $index->namedTypes($factory[0]) !== []];
            }
        }

        return null;
    }
}
