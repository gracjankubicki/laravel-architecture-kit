<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Query;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\RuleRegistry;
use InvalidArgumentException;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortableRuleTest extends TestCase
{
    public function test_legacy_rule_is_rejected_before_constructor_runs_for_mago(): void
    {
        LegacyRuleFixture::$constructed = 0;
        try {
            new RuleRegistry([LegacyRuleFixture::class], new EngineDescriptor('mago', 'test', ['syntax-tree-v1']));
            self::fail('A rule without portability declaration must fail preflight.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString(LegacyRuleFixture::class, $error->getMessage());
            self::assertStringContainsString('Select PHP explicitly', $error->getMessage());
            self::assertSame(0, LegacyRuleFixture::$constructed);
        }
    }

    public function test_legacy_rule_still_reads_php_parser_objects_when_php_is_selected(): void
    {
        $session = new AnalysisSession(new PhpAnalysisEngine, '8.3');
        $file = new FileContext('lib/Example.php', '<?php class Example {}', analysis: $session);
        $rule = new LegacyRuleFixture;
        $registry = new RuleRegistry([$rule], $session->engine);
        self::assertSame([$rule], $registry->customRules());
        self::assertInstanceOf(Class_::class, $file->ast()[0]);
        self::assertCount(1, $rule->check($file));
    }

    public function test_one_portable_rule_runs_against_both_engines_with_the_same_expected_finding(): void
    {
        $backends = [new PhpAnalysisEngine];
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (is_string($binary) && $binary !== '') {
            $backends[] = new MagoAnalysisEngine($binary);
        }
        foreach ($backends as $backend) {
            $session = new AnalysisSession($backend, '8.3');
            $attempts = 0;
            $file = new FileContext('lib/Example.php', "<?php\nclass Example {}", static function () use (&$attempts): void {
                $attempts++;
            }, analysis: $session);
            $rule = new PortableRuleFixture;
            $registry = new RuleRegistry([$rule], $session->engine);
            $findings = $registry->customRules()[0]->check($file);
            self::assertCount(1, $findings);
            self::assertSame('lib/Example.php', $findings[0]->path);
            self::assertSame(2, $findings[0]->line);
            self::assertSame('Class Example needs a final declaration.', $findings[0]->message);
            self::assertSame(1, $attempts);
            $view = $file->withSyntax($file->syntax());
            self::assertSame($file->syntax(), $view->syntax());
            self::assertSame(1, $attempts);
            $file->releaseAst();
            self::assertCount(1, $file->syntax());
            self::assertSame(2, $attempts);
        }
    }

    public function test_mago_rule_cannot_sneak_into_php_through_the_legacy_ast_or_view(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '') {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE to the built engine binary.');
        }
        $file = new FileContext('lib/Example.php', '<?php class Example {}', analysis: new AnalysisSession(new MagoAnalysisEngine($binary), '8.3'));
        try {
            $file->ast();
            self::fail('Mago must not produce a legacy PHP AST.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('requires the PHP engine', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy AST views require the PHP engine');
        $file->withAst([]);
    }
}

final class LegacyRuleFixture implements AuditRule
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function supports(string $path, array $enabled): bool
    {
        return true;
    }

    public function check(FileContext $file): array
    {
        return ($file->ast()[0] ?? null) instanceof Class_ ? [new AuditFinding('error', 'legacy-example', $file->path, 1, 'Legacy class rule.')] : [];
    }
}

final class PortableRuleFixture implements PortableAuditRule
{
    public function supports(string $path, array $enabled): bool
    {
        return true;
    }

    public function check(FileContext $file): array
    {
        $findings = [];
        foreach (Query::ofKind($file->syntax() ?? [], 'statement.class') as $class) {
            if (($class->flags & 32) === 0) {
                $findings[] = new AuditFinding('error', 'portable-example', $file->path, $class->getStartLine(), 'Class '.$class->name->toString().' needs a final declaration.');
            }
        }

        return $findings;
    }
}
