<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Pinned SDK ancestry is opt-in for AI composition, never a global PHP hierarchy. */
final class CatalogAiGatewayTypes
{
    /** @return array<string, list<string>> */
    private static function contracts(string $version): array
    {
        $prefix = 'Laravel\\Ai\\Contracts\\Gateway\\';
        $shared = [$prefix.'Gateway', ...array_map(fn ($name) => $prefix.$name.'Gateway', ['Audio', 'Embedding', 'Image', 'Transcription', version_compare($version, '0.9.0.0', '<') ? 'Text' : 'StepText'])];

        return [
            'Laravel\\Ai\\Gateway\\OpenAi\\OpenAiGateway' => $shared,
            'Laravel\\Ai\\Gateway\\Gemini\\GeminiGateway' => $shared,
            'Laravel\\Ai\\Gateway\\Anthropic\\AnthropicGateway' => $shared,
            'Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway' => [$prefix.'FileGateway'],
            'Laravel\\Ai\\Gateway\\OpenAi\\OpenAiStoreGateway' => [$prefix.'StoreGateway'],
        ];
    }

    public static function matches(CatalogIndex $index, string $type, string $contract, string $version): bool
    {
        $ids = $index->namedTypes($type);
        if (strcasecmp($type, $contract) === 0 || count($ids) === 1 && $index->hasContract($ids[0], $contract)) {
            return true;
        }
        $aggregate = 'Laravel\\Ai\\Contracts\\Gateway\\Gateway';
        if ($index->namedTypes($aggregate) === [] && $index->namedTypes($contract) === []
            && (strcasecmp($type, $aggregate) === 0 || count($ids) === 1 && $index->hasContract($ids[0], $aggregate))
            && in_array($contract, self::contracts($version)['Laravel\\Ai\\Gateway\\OpenAi\\OpenAiGateway'], true)) {
            return true;
        }
        foreach (self::ancestors($index, $type, $version) as $ancestor) {
            if (in_array(strtolower($contract), array_map('strtolower', self::contracts($version)[$ancestor]), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function ancestors(CatalogIndex $index, string $type, string $version): array
    {
        $ids = $index->namedTypes($type);
        if (count($ids) > 1) {
            return [];
        }
        $found = [];
        foreach (self::contracts($version) as $sdk => $contracts) {
            if ($index->namedTypes($sdk) !== [] || array_filter($contracts, fn ($contract) => $index->namedTypes($contract) !== []) !== []) {
                continue;
            }
            if (strcasecmp($type, $sdk) === 0 || count($ids) === 1 && $index->hasContract($ids[0], $sdk)) {
                $found[] = $sdk;
            }
        }

        return $found;
    }
}
