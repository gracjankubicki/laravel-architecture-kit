<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Suppression;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\InlineIgnores;
use PHPUnit\Framework\TestCase;

class InlineIgnoresTest extends TestCase
{
    public function test_multiline_docblock_suppresses_findings_inside_and_after_the_comment(): void
    {
        $contents = <<<'PHP'
<?php

/**
 * @architecture-kit-ignore thin-controller -- reviewed legacy boundary
 */
final class LegacyController
{
}
PHP;

        $result = (new InlineIgnores)->apply('app/LegacyController.php', $contents, [
            new AuditFinding('error', 'thin-controller', 'app/LegacyController.php', 4, 'Finding inside the suppression docblock.'),
            new AuditFinding('error', 'thin-controller', 'app/LegacyController.php', 6, 'Finding after the suppression docblock.'),
        ], ['thin-controller']);

        $this->assertSame([], $result->findings);
        $this->assertSame(2, $result->inline);
    }

    public function test_one_comment_can_suppress_multiple_rules(): void
    {
        $contents = <<<'PHP'
<?php

// @architecture-kit-ignore thin-controller @architecture-kit-ignore actions -- reviewed legacy boundary
final class LegacyController
{
}
PHP;

        $result = (new InlineIgnores)->apply('app/LegacyController.php', $contents, [
            new AuditFinding('error', 'thin-controller', 'app/LegacyController.php', 4, 'Thin controller finding.'),
            new AuditFinding('warn', 'actions', 'app/LegacyController.php', 4, 'Actions finding.'),
        ], ['thin-controller', 'actions']);

        $this->assertSame([], $result->findings);
        $this->assertSame(2, $result->inline);
    }

    public function test_unused_known_suppression_is_reported_without_hiding_the_original_finding(): void
    {
        $contents = <<<'PHP'
<?php

// @architecture-kit-ignore thin-controller -- no matching finding
final class LegacyController
{
}
PHP;

        $result = (new InlineIgnores)->apply('app/LegacyController.php', $contents, [
            new AuditFinding('error', 'actions', 'app/LegacyController.php', 4, 'Actions finding.'),
        ], ['thin-controller', 'actions']);

        $this->assertCount(2, $result->findings);
        $this->assertNotEmpty(array_filter(
            $result->findings,
            fn (AuditFinding $finding): bool => $finding->rule === 'actions',
        ));
        $this->assertNotEmpty(array_filter(
            $result->findings,
            fn (AuditFinding $finding): bool => $finding->rule === 'invalid-suppression'
                && str_contains($finding->message, 'Unused')
                && ! str_contains($finding->message, 'Unknown'),
        ));
        $this->assertSame(0, $result->inline);
    }
}
