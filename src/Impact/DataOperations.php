<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

/** Shared terminal query semantics. Query construction alone has no data effect. */
final class DataOperations
{
    public const READ = ['get', 'first', 'firstorfail', 'find', 'findorfail', 'findmany', 'sole', 'value', 'pluck', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntexist', 'paginate', 'simplepaginate', 'cursorpaginate', 'cursor', 'lazy', 'lazybyid', 'chunk', 'chunkbyid', 'each', 'eachbyid'];

    public const WRITE = ['save', 'savequietly', 'saveorfail', 'push', 'pushquietly', 'create', 'createquietly', 'insert', 'insertorignore', 'insertusing', 'update', 'updatequietly', 'updateorfail', 'upsert', 'delete', 'deletequietly', 'destroy', 'forcedelete', 'forcedeletequietly', 'forcedestroy', 'restore', 'restorequietly', 'increment', 'incrementquietly', 'decrement', 'decrementquietly', 'truncate', 'attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot'];

    public const BOTH = ['firstorcreate', 'updateorcreate', 'createorfirst', 'updateorinsert', 'incrementorcreate'];

    /** @return list<string> */
    public static function kinds(string $method): array
    {
        if (in_array($method, [...self::BOTH, 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle'], true)) {
            return ['read', 'write'];
        }
        if (in_array($method, self::READ, true)) {
            return ['read'];
        }

        return in_array($method, self::WRITE, true) ? ['write'] : [];
    }
}
