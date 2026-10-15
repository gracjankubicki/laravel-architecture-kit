<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis\Syntax;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Codec;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Query;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PortableSyntaxTest extends TestCase
{
    public function test_attribute_and_default_constructors_keep_source_order_on_both_backends(): void
    {
        $engines = [new PhpAnalysisEngine];
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (is_string($binary) && $binary !== '') {
            $engines[] = new MagoAnalysisEngine($binary);
        }
        $source = new SourceInput('lib/Factory.php', '<?php function make(#[Marked(new AttributeArgument())] $value = new DefaultArgument()) {}', '8.3');
        foreach ($engines as $engine) {
            $document = $engine->analyze($source);
            self::assertTrue($document->complete());
            self::assertSame(['AttributeArgument', 'DefaultArgument'], array_map(
                static fn (Node $node): string => $node->class->toString(),
                Query::ofKind($document->syntax, 'expression.new'),
            ));
            $decoded = Codec::decodeList(array_map(Codec::encode(...), $document->syntax), $source);
            self::assertSame(['AttributeArgument', 'DefaultArgument'], array_map(
                static fn (Node $node): string => $node->class->toString(),
                Query::ofKind($decoded, 'expression.new'),
            ));
        }
    }

    public function test_full_declarations_types_arguments_and_aliases_survive_php_boundary(): void
    {
        $this->assertDeclarations(new PhpAnalysisEngine);
    }

    public function test_full_declarations_types_arguments_and_aliases_survive_real_mago_boundary(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '') {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE to the built engine binary.');
        }
        $this->assertDeclarations(new MagoAnalysisEngine($binary));
    }

    private function assertDeclarations(AnalysisEngine $engine): void
    {
        $code = <<<'PHP'
<?php
namespace Billing;
use Contracts\Gateway as Port;
/** Charge a customer. */
final readonly class Charge extends Base implements Port {
    use Logs;
    public function __construct(public Port $gateway) {}
    #[Retry(attempts: 3)]
    public function &handle(?Port &$port, int ...$amounts): Port|null {
        $this->gateway->charge(amount: $amounts[0]);
        return new Receipt();
    }
}
PHP;
        $source = new SourceInput('lib/Charge.php', $code, '8.3');
        $document = $engine->analyze($source);
        self::assertTrue($document->complete());
        $classes = Query::ofKind($document->syntax, 'statement.class');
        self::assertCount(1, $classes);
        $class = $classes[0];
        self::assertSame('Charge', $class->name->toString());
        self::assertSame('Billing\\Charge', $class->getAttribute('namespacedName')->toString());
        self::assertSame('Contracts\\Gateway', $class->implements[0]->getAttribute('resolvedName')->toString());
        self::assertStringContainsString('Charge a customer', $class->getDocComment()->getText());
        $methods = Query::ofKind($class, 'statement.class-method');
        self::assertCount(2, $methods);
        self::assertTrue($methods[1]->byRef);
        self::assertSame('nullable-type', $methods[1]->params[0]->type->kind);
        self::assertTrue($methods[1]->params[0]->byRef);
        self::assertTrue($methods[1]->params[1]->variadic);
        self::assertSame('union-type', $methods[1]->returnType->kind);
        self::assertSame(3, $methods[1]->attrGroups[0]->attrs[0]->args[0]->value->value);
        $call = Query::ofKind($class, 'expression.method-call')[0];
        self::assertSame('amount', $call->args[0]->name->toString());
        self::assertSame('$this->gateway->charge(amount: $amounts[0])', $call->text());
        // The wire representation is closed data, including resolved names and comments.
        $wire = json_decode(json_encode(array_map(Codec::encode(...), $document->syntax), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $decoded = Codec::decodeList($wire, $source);
        self::assertSame(array_map(Codec::encode(...), $document->syntax), array_map(Codec::encode(...), $decoded));
        foreach (Query::walk($decoded) as $node) {
            self::assertSame($source, $node->source);
            self::assertGreaterThanOrEqual(0, $node->start);
            self::assertLessThanOrEqual(strlen($code), $node->end);
        }
    }

    public function test_lines_and_byte_ranges_handle_unicode_crlf_and_eof(): void
    {
        $source = new SourceInput('src/Utf.php', "<?php\r\n// żółw\r\necho 'ą';\n", '8.3');
        self::assertSame(1, $source->lineAt(0));
        self::assertSame(1, $source->lineAt(6));
        self::assertSame(2, $source->lineAt(7));
        self::assertSame(3, $source->lineAt(strpos($source->contents, 'echo')));
        self::assertSame(4, $source->lineAt(strlen($source->contents)));
        $node = (new PhpAnalysisEngine)->analyze($source)->syntax[0];
        self::assertSame(3, $node->getStartLine());
        self::assertSame("echo 'ą';", $node->text());
    }

    public function test_nodes_from_another_snapshot_cannot_enter_a_tree(): void
    {
        $source = new SourceInput('one.php', '<?php', '8.3');
        $child = new Node('identifier', ['name' => 'name'], new SourceInput('two.php', '<?php', '8.3'), 0, 0);
        $this->expectException(InvalidArgumentException::class);
        new Node('identifier', ['child' => $child], $source, 0, 0);
    }

    public function test_decoder_rejects_parser_objects_or_unversioned_extra_fields(): void
    {
        $source = new SourceInput('one.php', '<?php', '8.3');
        $node = Codec::encode(new Node('identifier', ['name' => 'name'], $source, 0, 0));
        $node['parserObject'] = new \stdClass;
        $this->expectException(InvalidArgumentException::class);
        Codec::decode($node, $source);
    }
}
