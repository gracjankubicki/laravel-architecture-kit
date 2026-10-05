<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Reach;

use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;

final readonly class ReachSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $schema = ImpactSchema::get();
        $schema['title'] = 'Architecture Kit reach agent output';
        $schema['oneOf'] = array_slice($schema['oneOf'], 0, 2);
        foreach ($schema['oneOf'] as &$variant) {
            $variant['properties']['cmd'] = ['const' => 'reach'];
        }
        unset($variant);
        $schema['oneOf'][0]['required'][] = 'reach';
        $schema['oneOf'][0]['properties']['analysis']['properties']['limit']['maximum'] = 1000;

        return $schema;
    }
}
