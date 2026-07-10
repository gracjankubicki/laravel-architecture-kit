<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditFindingTest extends TestCase
{
    public function test_it_rejects_invalid_finding_contract_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditFinding('notice', 'Invalid Rule', '', 0, '');
    }

    public function test_explicit_builtin_code_does_not_depend_on_message_copy(): void
    {
        $finding = new AuditFinding(
            severity: 'error',
            rule: 'thin-controller',
            path: 'app/Http/Controllers/DocumentController.php',
            line: 12,
            message: 'This copy is deliberately different.',
            code: 'E_THIN_CONTROLLER_MODEL_WRITE',
        );

        $registry = new FindingCodeRegistry;

        $this->assertSame('E_THIN_CONTROLLER_MODEL_WRITE', $registry->codeFor($finding));
        $this->assertSame('thin-controller', $registry->explain($registry->codeFor($finding))['rule']);
    }

    public function test_every_generic_builtin_code_has_an_explanation(): void
    {
        $registry = new FindingCodeRegistry;

        $codes = FindingCodeRegistry::explicitCodes();

        foreach (FindingCodeRegistry::ruleIds() as $rule) {
            $suffix = strtoupper(str_replace('-', '_', $rule));
            $codes[] = 'E_'.$suffix;
            $codes[] = 'W_'.$suffix;
        }

        foreach (array_unique($codes) as $code) {
            $explanation = $registry->explain($code);

            $this->assertNotNull($explanation);
            $this->assertSame($code, $explanation['code']);
            $this->assertArrayHasKey('title', $explanation);
            $this->assertArrayHasKey('why', $explanation);
            $this->assertArrayHasKey('fix', $explanation);
        }
    }

    public function test_fallback_code_depends_on_rule_and_severity_not_message_copy(): void
    {
        $registry = new FindingCodeRegistry;
        $first = new AuditFinding('warn', 'thin-controller', 'app/Http/Controllers/A.php', 1, 'First message.');
        $second = new AuditFinding('warn', 'thin-controller', 'app/Http/Controllers/A.php', 1, 'Completely different message.');

        $this->assertSame('W_THIN_CONTROLLER', $registry->codeFor($first));
        $this->assertSame($registry->codeFor($first), $registry->codeFor($second));
    }
}
