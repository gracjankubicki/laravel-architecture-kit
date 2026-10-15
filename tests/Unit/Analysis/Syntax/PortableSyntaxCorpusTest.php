<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis\Syntax;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Codec;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortableSyntaxCorpusTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function cases(): iterable
    {
        yield 'callback-and-routes' => ['use Web\Router as Route; Route::get("/pay", static fn () => resolve(Payment::class)?->run());',
            ['statement.use', 'expression.static-call', 'expression.arrow-function', 'expression.class-const-fetch', 'expression.nullsafe-method-call']];
        yield 'closure-capture' => ['$factor = 2; $map = static function (int $value) use (&$factor): int { return $factor * $value; };',
            ['expression.closure', 'closure-use', 'expression.binary-op.mul']];
        yield 'condition-and-loops' => ['function total(array $rows): int { $sum=0; foreach ($rows as $id => &$row) { if ($id > 2) { $sum += $row; } elseif ($id === 0) { continue; } else { break; } } return $sum; }',
            ['statement.foreach', 'statement.if', 'statement.else-if', 'statement.else', 'statement.continue', 'statement.break', 'expression.assign-op.plus']];
        yield 'for-while-do' => ['for ($i=0; $i<3; $i++) { echo $i; } while (true) { break; } do { echo 1; } while (false);',
            ['statement.for', 'statement.while', 'statement.do', 'expression.post-inc']];
        yield 'match-and-switch' => ['function label($id) { switch ($id) { case 1: return "one"; default: return match ($id) { 2, 3 => "few", default => "other" }; } }',
            ['statement.switch', 'statement.case', 'expression.match', 'match-arm']];
        yield 'try-and-errors' => ['try { throw new \RuntimeException("bad"); } catch (\RuntimeException|\LogicException $e) { report($e); } finally { cleanup(); }',
            ['statement.try-catch', 'statement.catch', 'statement.finally', 'expression.throw']];
        yield 'enums-and-properties' => ['enum State: string { case Paid="paid"; } interface Gateway { public function send(): bool; } trait Logs {} final class Receipt implements Gateway { use Logs; private int $id=1; public const string TYPE="bill"; public function send(): bool { return true; } }',
            ['statement.enum', 'statement.enum-case', 'statement.interface', 'statement.trait', 'statement.property', 'statement.class-const']];
        yield 'anonymous-class' => ['$service = new class(new Dependency()) implements \Stringable { public function __toString(): string { return "ok"; } };',
            ['expression.new', 'statement.class', 'statement.class-method']];
        yield 'arrays-and-references' => ['$data=["key" => $value, ...$extra]; $data[] = 1; $reference =& $data; unset($data["key"]);',
            ['expression.array', 'array-item', 'expression.array-dim-fetch', 'expression.assign-ref', 'statement.unset']];
        yield 'generators' => ['function sequence() { yield 1; yield "id" => 2; yield from [3]; }',
            ['expression.yield', 'expression.yield-from']];
        yield 'strings-and-constants' => ['const NAME="kit"; function show($name) { return __METHOD__." hello {$name}"; }',
            ['statement.const', 'scalar.magic-const.method', 'scalar.interpolated-string', 'interpolated-string-part']];
        yield 'declare-static-and-global' => ['declare(strict_types=1); function counter() { global $external; static $count=0; return ++$count; }',
            ['statement.declare', 'declare-item', 'statement.global', 'statement.static', 'static-var', 'expression.pre-inc']];
    }

    #[DataProvider('cases')]
    public function test_php_syntax_covers_expected_structures(string $code, array $kinds): void
    {
        $this->assertCorpus(new PhpAnalysisEngine, $code, $kinds);
    }

    #[DataProvider('cases')]
    public function test_real_mago_syntax_covers_expected_structures(string $code, array $kinds): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '') {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE to the built engine binary.');
        }
        $this->assertCorpus(new MagoAnalysisEngine($binary), $code, $kinds);
    }

    public function test_each_backend_rejects_syntax_newer_than_project_target_and_can_continue(): void
    {
        $engines = [new PhpAnalysisEngine];
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (is_string($binary) && $binary !== '') {
            $engines[] = new MagoAnalysisEngine($binary);
        }
        foreach ($engines as $engine) {
            $old = $engine->analyze(new SourceInput('src/Version.php', '<?php readonly class Version {}', '8.1'));
            self::assertFalse($old->complete());
            self::assertSame('syntax_error', $old->diagnostics[0]['category']);
            self::assertSame([], $old->syntax);
            self::assertSame([], $old->facts);
            self::assertTrue($engine->analyze(new SourceInput('src/Version.php', '<?php readonly class Version {}', '8.3'))->complete());
        }
    }

    /** @param list<string> $kinds */
    private function assertCorpus(AnalysisEngine $engine, string $code, array $kinds): void
    {
        $source = new SourceInput('lib/Example.php', '<?php '.$code, '8.3');
        $document = $engine->analyze($source);
        self::assertTrue($document->complete(), json_encode($document->diagnostics, JSON_THROW_ON_ERROR));
        foreach ($kinds as $kind) {
            self::assertNotEmpty(Query::ofKind($document->syntax, $kind), 'Missing expected '.$kind);
        }
        $decoded = Codec::decodeList(array_map(Codec::encode(...), $document->syntax), $source);
        self::assertCount(count(iterator_to_array(Query::walk($document->syntax))), iterator_to_array(Query::walk($decoded)));
    }
}
