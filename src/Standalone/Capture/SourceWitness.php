<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use InvalidArgumentException;

/** Length and newline witnesses validate locations without retaining source bytes. */
final class SourceWitness
{
    public const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

    /**
     * @return array<array-key, mixed>
     */
    public static function fromSource(BasicSource $source): array
    {
        $witness = ['original' => self::positions($source->original), 'prepared' => self::positions($source->prepared),
            'mapping_version' => $source->mappingVersion, 'segments' => $source->segments, 'mapping_fingerprint' => $source->mappingFingerprint];
        self::validate($witness);

        return $witness;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function positions(SourceInput $source): array
    {
        $newlines = [];
        $cursor = 0;
        while (($at = strpos($source->contents, "\n", $cursor)) !== false) {
            $newlines[] = $at;
            $cursor = $at + 1;
        }

        return ['length' => strlen($source->contents), 'sha256' => $source->hash, 'newlines' => $newlines];
    }

    /**
     * @param  array<array-key, mixed>  $witness
     */
    public static function validate(array $witness): void
    {
        if (array_keys($witness) !== ['original', 'prepared', 'mapping_version', 'segments', 'mapping_fingerprint']) {
            throw new InvalidArgumentException('capture_witness_shape');
        }
        foreach (['original', 'prepared'] as $kind) {
            $value = $witness[$kind];
            if (! is_array($value) || array_keys($value) !== ['length', 'sha256', 'newlines']
                || ! is_int($value['length']) || $value['length'] < 0 || $value['length'] > self::MAX_SOURCE_BYTES * 4
                || ! is_string($value['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $value['sha256']) !== 1
                || ! is_array($value['newlines']) || ! array_is_list($value['newlines'])) {
                throw new InvalidArgumentException('capture_position_witness');
            }
            $prior = -1;
            foreach ($value['newlines'] as $position) {
                if (! is_int($position) || $position <= $prior || $position >= $value['length']) {
                    throw new InvalidArgumentException('capture_newline_witness');
                }
                $prior = $position;
            }
        }
        $segments = $witness['segments'];
        if (! is_array($segments) || ! array_is_list($segments) || ! is_string($witness['mapping_version']) || ! is_string($witness['mapping_fingerprint'])) {
            throw new InvalidArgumentException('capture_mapping_shape');
        }
        if ($segments === []) {
            if ($witness['original'] !== $witness['prepared'] || $witness['mapping_version'] !== '' || $witness['mapping_fingerprint'] !== '') {
                throw new InvalidArgumentException('capture_plain_identity');
            }

            return;
        }
        $cursor = 0;
        foreach ($segments as $segment) {
            if (! is_array($segment) || array_keys($segment) !== ['start', 'original', 'length', 'copied']
                || $segment['start'] !== $cursor || ! is_int($segment['original']) || $segment['original'] < 0
                || $segment['original'] > $witness['original']['length'] || ! is_int($segment['length']) || $segment['length'] < 1
                || ! is_bool($segment['copied']) || $segment['copied'] && $witness['original']['length'] < $segment['original'] + $segment['length']) {
                throw new InvalidArgumentException('capture_mapping_bounds');
            }
            $cursor += $segment['length'];
        }
        $fingerprint = hash('sha256', json_encode([$witness['original']['sha256'], $witness['prepared']['sha256'], $witness['mapping_version'], $segments], JSON_THROW_ON_ERROR));
        if ($cursor !== $witness['prepared']['length'] || $witness['mapping_version'] === '' || $fingerprint !== $witness['mapping_fingerprint']) {
            throw new InvalidArgumentException('capture_preparation_identity');
        }
    }

    /**
     * @param  array<array-key, mixed>  $witness
     */
    public static function input(string $path, string $php, array $witness): SourceInput
    {
        self::validate($witness);
        $positions = $witness['original'];
        $bytes = str_repeat(' ', $positions['length']);
        foreach ($positions['newlines'] as $at) {
            $bytes[$at] = "\n";
        }

        return new SourceInput($path, $bytes, $php);
    }
}
