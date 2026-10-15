<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;

/** Native metadata only. Never instantiate reflected code or evaluate reflected values. */
final class ReflectionSemantics
{
    public static function describe(string $type, string $method, Node $call): FrameworkCallResult
    {
        $type = strtolower(ltrim($type, '\\'));
        $method = strtolower($method);
        // IS_INSTANCEOF filtering can autoload attribute classes. Only the default
        // exact-name filter is metadata-only without loading application PHP.
        if ($method === 'getattributes') {
            foreach ($call->getArgs() as $index => $argument) {
                if ($argument->unpack || $argument->name?->toString() === 'flags' || ($argument->name === null && $index === 1)) {
                    if (! Node::is($argument->value, 'scalar.int') || $argument->value->value !== 0 || $argument->unpack) {
                        return FrameworkCallResult::unhandled();
                    }
                }
            }
        }
        $scalar = match ($type) {
            'reflectionclass', 'reflectionobject' => ['getname', 'getshortname', 'getnamespacename', 'getfilename', 'getstartline', 'getendline', 'getdoccomment', 'getmodifiers', 'isabstract', 'isfinal', 'isreadonly', 'isinstantiable', 'iscloneable', 'isinterface', 'istrait', 'isenum', 'isinternal', 'isuserdefined', 'isanonymous', 'innamespace', 'hasproperty', 'hasmethod'],
            'reflectionproperty' => ['getname', 'getdoccomment', 'getmodifiers', 'ispublic', 'isprotected', 'isprivate', 'isstatic', 'isreadonly', 'isdefault', 'ispromoted', 'hastype'],
            'reflectiontype' => ['allowsnull'],
            'reflectionnamedtype' => ['allowsnull', 'getname', 'isbuiltin'],
            'reflectionuniontype', 'reflectionintersectiontype' => ['allowsnull'],
            'reflectionattribute' => ['getname', 'gettarget', 'isrepeated'],
            default => [],
        };
        if (in_array($method, $scalar, true)) {
            return FrameworkCallResult::value(FrameworkValue::scalar());
        }

        $value = match (true) {
            in_array($type, ['reflectionclass', 'reflectionobject'], true) => match ($method) {
                'getproperties' => self::arrayOf('ReflectionProperty'),
                'getproperty' => FrameworkValue::type('ReflectionProperty'),
                'getattributes' => self::arrayOf('ReflectionAttribute'),
                default => null,
            },
            $type === 'reflectionproperty' => match ($method) {
                'gettype' => FrameworkValue::type('ReflectionType')->nullable(),
                'getattributes' => self::arrayOf('ReflectionAttribute'),
                'getdeclaringclass' => FrameworkValue::type('ReflectionClass'),
                default => null,
            },
            $type === 'reflectionuniontype' && $method === 'gettypes' => self::arrayOf('ReflectionType'),
            $type === 'reflectionintersectiontype' && $method === 'gettypes' => self::arrayOf('ReflectionNamedType'),
            default => null,
        };

        return $value === null ? FrameworkCallResult::unhandled() : FrameworkCallResult::value($value);
    }

    /** ReflectionType is an abstract native result. An instanceof test can refine it. */
    public static function refinesType(string $type, string $candidate): bool
    {
        return strcasecmp(ltrim($type, '\\'), 'ReflectionType') === 0
            && in_array(strtolower(ltrim($candidate, '\\')), ['reflectionnamedtype', 'reflectionuniontype', 'reflectionintersectiontype'], true);
    }

    private static function arrayOf(string $type): FrameworkValue
    {
        return new FrameworkValue(type: '@array', element: FrameworkValue::type($type));
    }
}
