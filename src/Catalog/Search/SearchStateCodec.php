<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Search;

use InvalidArgumentException;
use JsonException;

/** Independent derived product. Never evaluated as PHP or added to legacy CatalogState. */
final class SearchStateCodec
{
    public static function encode(SearchState $state): string
    {
        $data = $state->export();

        return json_encode(['digest' => self::digest($data), 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<int|string, array<string, mixed>> $elements */
    public static function decode(string $bytes, array $elements, string $generation, string $identity): SearchState
    {
        try {
            $envelope = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('search_state_json', previous: $exception);
        }
        if (! is_array($envelope) || array_keys($envelope) !== ['digest', 'data'] || ! is_string($envelope['digest'])
            || ! is_array($envelope['data']) || ! hash_equals(self::digest($envelope['data']), $envelope['digest'])) {
            throw new InvalidArgumentException('search_state_digest');
        }

        return SearchState::restore($envelope['data'], $elements, $generation, $identity);
    }

    /** Missing/corrupt/foreign data returns an explicit exact fallback, not an empty index.
     * W04 owns actual cache IO results and final response metadata.
     *
     * @param  array<int|string, array<string, mixed>>  $elements
     * @return array{state: SearchState, status: string, reason: ?string}
     */
    public static function load(?string $bytes, array $elements, string $generation, string $identity): array
    {
        try {
            if ($bytes === null) {
                throw new InvalidArgumentException('search_state_missing');
            }

            return ['state' => self::decode($bytes, $elements, $generation, $identity), 'status' => 'restored', 'reason' => null];
        } catch (InvalidArgumentException $exception) {
            return ['state' => SearchState::build($elements, $generation, $identity, 0), 'status' => 'exact_fallback', 'reason' => $exception->getMessage()];
        }
    }

    /** @param array<string, mixed> $data */
    private static function digest(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
