<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\ImpactIndex;
use GracjanKubicki\ArchitectureKit\Impact\SignatureCompatibility;

/** Checks today's declarations against today's users, including unchanged source files. */
final class SignatureCompatibilityRule implements ProjectAuditRule
{
    public bool $checksComplete = true;

    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array
    {
        $index = new ImpactIndex($graph);
        $findings = [];
        foreach ($index->calls as $call) {
            if ($call['kind'] !== 'call' || $call['receiver'] === null || $call['method'] === null) {
                continue;
            }
            $receiver = $index->receiver($call['receiver']);
            $target = $receiver === null ? null : $index->method($receiver, $call['method']);
            if ($target === null) {
                continue;
            }
            foreach (SignatureCompatibility::argumentErrors($index->classes[strtolower($target['class'])]['methods'][strtolower($target['name'])]['signature'], $call['site']) as $reason) {
                $findings[] = $this->finding($call['path'], $call['line'], $target['symbol'].': '.$reason);
            }
        }
        foreach ($index->classes as $class) {
            if ($class['kind'] !== 'class') {
                continue;
            }
            foreach ($index->ancestors($class['name']) as $ancestor) {
                $parent = $index->classes[strtolower($ancestor)] ?? null;
                if ($parent === null) {
                    continue;
                }
                foreach ($parent['methods'] as $method) {
                    if (strtolower($method['name']) === '__construct' && $parent['kind'] !== 'interface' && ! $method['signature']['abstract']) {
                        continue;
                    }
                    $child = $index->method($class['name'], $method['name']);
                    $childSignature = $child === null ? null : $index->classes[strtolower($child['class'])]['methods'][strtolower($child['name'])]['signature'];
                    if ($childSignature === null || $childSignature['abstract']) {
                        if (! $class['abstract'] && $method['signature']['abstract']) {
                            $findings[] = $this->finding($class['path'], $class['line'], $class['name'].'::'.$method['name'].': missing_contract_implementation');
                        }

                        continue;
                    }
                    if (strcasecmp($child['symbol'], $parent['name'].'::'.$method['name']) === 0) {
                        continue;
                    }
                    $proof = SignatureCompatibility::contract($method['signature'], $childSignature, $parent['kind'] === 'trait' && $method['signature']['abstract']);
                    foreach ($proof['errors'] as $reason) {
                        $findings[] = $this->finding($child['path'], $child['line'], $child['symbol'].': '.$reason);
                    }
                }
            }
        }
        foreach ($index->notices as $notice) {
            if (preg_match('/limit|unparseable|changed|unsafe/i', $notice['reason'])) {
                $this->checksComplete = false;
            }
        }
        $unique = [];
        foreach ($findings as $finding) {
            $unique[$finding->path.':'.$finding->line.':'.$finding->message] = $finding;
        }

        return array_values($unique);
    }

    private function finding(string $path, int $line, string $reason): AuditFinding
    {
        return new AuditFinding('error', 'signature-compatibility', $path, $line, $reason, code: 'E_SIGNATURE_COMPATIBILITY');
    }
}
