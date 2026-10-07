<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Pinned Rule APIs from Laravel 12.41.1 and 13.18; consumer vendor PHP is never loaded. */
final class CatalogRuleFactories
{
    public const TYPES = [
        'can' => 'Can', 'array' => 'ArrayRule', 'unique' => 'Unique', 'exists' => 'Exists',
        'in' => 'In', 'notin' => 'NotIn', 'requiredif' => 'RequiredIf', 'requiredunless' => 'RequiredUnless',
        'excludeif' => 'ExcludeIf', 'excludeunless' => 'ExcludeUnless', 'prohibitedif' => 'ProhibitedIf', 'prohibitedunless' => 'ProhibitedUnless',
        'date' => 'Date', 'datetime' => 'Date', 'email' => 'Email', 'enum' => 'Enum', 'file' => 'File',
        'imagefile' => 'ImageFile', 'dimensions' => 'Dimensions', 'string' => 'StringRule', 'numeric' => 'Numeric',
        'anyof' => 'AnyOf', 'contains' => 'Contains', 'doesntcontain' => 'DoesntContain',
    ];

    public const CONDITIONS = ['requiredif', 'requiredunless', 'excludeif', 'excludeunless', 'prohibitedif', 'prohibitedunless'];

    public const CONDITIONABLE = ['unique', 'exists', 'enum', 'file', 'imagefile', 'date', 'datetime', 'email', 'numeric', 'dimensions', 'string'];

    public const LARAVEL_13_ONLY = ['requiredunless', 'excludeunless', 'prohibitedunless', 'datetime', 'string'];

    /** Only source-verified methods preserving the rule object, excluding validation/accessor results.
     * @return list<string>
     */
    public static function modifiers(string $factory): array
    {
        $database = ['where', 'wherenot', 'wherenull', 'wherenotnull', 'wherein', 'wherenotin', 'withouttrashed', 'onlytrashed', 'using'];
        $files = ['extensions', 'size', 'between', 'min', 'max', 'encoding', 'rules', 'setvalidator', 'setdata'];

        return match ($factory) {
            'unique' => [...$database, 'ignore', 'ignoremodel'], 'exists' => $database,
            'enum' => ['only', 'except', 'setvalidator'],
            'file' => $files, 'imagefile' => [...$files, 'dimensions'],
            'date', 'datetime' => ['format', 'beforetoday', 'aftertoday', 'todayorbefore', 'todayorafter', 'before', 'after', 'beforeorequal', 'afterorequal', 'between', 'betweenorequal', 'past', 'future', 'noworpast', 'noworfuture'],
            'email' => ['rfccompliant', 'strict', 'validatemxrecord', 'preventspoofing', 'withnativevalidation', 'rules', 'setvalidator', 'setdata'],
            'numeric' => ['between', 'decimal', 'different', 'digits', 'digitsbetween', 'greaterthan', 'greaterthanorequalto', 'integer', 'lessthan', 'lessthanorequalto', 'max', 'maxdigits', 'min', 'mindigits', 'multipleof', 'same', 'exactly'],
            'dimensions' => ['width', 'height', 'minwidth', 'minheight', 'maxwidth', 'maxheight', 'ratio', 'minratio', 'maxratio', 'ratiobetween'],
            'string' => ['alpha', 'alphanumeric', 'alphadash', 'ascii', 'between', 'doesntendwith', 'doesntstartwith', 'endswith', 'exactly', 'lowercase', 'max', 'min', 'startswith', 'uppercase'],
            default => [],
        };
    }

    /** @return list<int> */
    public static function modifierVersions(string $factory, string $method): array
    {
        if (in_array($factory, ['date', 'datetime'], true) && in_array($method, ['past', 'future', 'noworpast', 'noworfuture'], true) || $factory === 'string') {
            return [13];
        }

        return [12, 13];
    }

    public static function requiredArgument(string $method): ?string
    {
        return match ($method) {
            'can' => 'ability', 'unique', 'exists' => 'table', 'enum' => 'type', 'anyof' => 'rules',
            'in', 'notin', 'contains', 'doesntcontain' => 'values',
            'requiredif', 'requiredunless', 'excludeif', 'excludeunless', 'prohibitedif', 'prohibitedunless' => 'callback',
            default => null,
        };
    }

    public static function type(string $method): ?string
    {
        return isset(self::TYPES[$method]) ? 'Illuminate\\Validation\\Rules\\'.self::TYPES[$method] : null;
    }
}
