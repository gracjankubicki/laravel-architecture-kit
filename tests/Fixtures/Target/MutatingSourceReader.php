<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

/** Loaded only in the isolated mutation test, never in the main suite process. */
final class MutatingSourceReader
{
    public static string $path;

    public static int $reads = 0;
}

/** @param resource $stream */
function stream_get_contents($stream, ?int $length = null, int $offset = -1): string|false
{
    $source = \stream_get_contents($stream, $length, $offset);
    if ((\stream_get_meta_data($stream)['uri'] ?? null) === MutatingSourceReader::$path) {
        $revision = ++MutatingSourceReader::$reads;
        \file_put_contents(MutatingSourceReader::$path, '<?php namespace App\\Actions; class CreateInvoice { public function handle() { return '.$revision.'; } }'.\str_repeat(' ', $revision));
    }

    return $source;
}
