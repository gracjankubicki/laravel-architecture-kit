<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

/** Source-reading regression check. Audit/Deep implementations remain legal PHP consumers. */
final class NativeConsumerGuard
{
    /** @param array<string, string> $sources
     * @return list<string> */
    public static function violations(array $sources): array
    {
        $errors = [];
        foreach ($sources as $path => $source) {
            if (str_contains($path, 'NativeGraph/') && ! in_array(basename($path), ['NativeBasic.php', 'NativeReplay.php', 'NativeConsumerGuard.php'], true)) {
                $tokens = token_get_all($source);
                foreach ($tokens as $token) {
                    if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                        && preg_match('/(^|\\\\)(CatalogIndex|CatalogGeneration|CatalogUpdater|SearchState)$/D', $token[1])) {
                        $errors[] = $path.':'.$token[2].':full_php_catalog';
                    }
                }
                if (preg_match('/->(?:state|query)\s*\(|[\'"]read_product[\'"]/', $source)) {
                    $errors[] = $path.':unbounded_native_collector';
                }
            }
        }

        return $errors;
    }
}
