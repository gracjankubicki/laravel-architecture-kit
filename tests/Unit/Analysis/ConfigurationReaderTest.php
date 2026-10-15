<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\ConfigurationReader;
use GracjanKubicki\ArchitectureKit\Architecture;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationReaderTest extends TestCase
{
    public static function literals(): iterable
    {
        yield 'arrays' => ["<?php declare(strict_types=1); return array('enabled'=>['actions'], 'n'=>-15, 'hex'=>0xff, 'bool'=>true, 'nil'=>null);", ['enabled' => ['actions'], 'n' => -15, 'hex' => 255, 'bool' => true, 'nil' => null]];
        yield 'group imports' => ['<?php use GracjanKubicki\\ArchitectureKit\\{Architecture as A}; return ["enabled"=>[A::Actions], "rule"=>Unknown\\Rule::class];', ['enabled' => [Architecture::Actions], 'rule' => 'Unknown\\Rule']];
        yield 'literal concat' => ["<?php return ['path'=>('lib/'.'Domain'), 'n'=>+1.5];", ['path' => 'lib/Domain', 'n' => 1.5]];
        yield 'nowdoc' => ["<?php return ['text'=><<<'END'\n  hello \$name\n  END\n];", ['text' => 'hello $name']];
        yield 'escapes' => ['<?php return ["text"=>"a\\n\\u{105}\\x21"];', ['text' => "a\ną!"]];
    }

    #[DataProvider('literals')]
    public function test_data_is_read_without_loading_project_php(string $source, array $expected): void
    {
        self::assertSame($expected, ConfigurationReader::read($source));
    }

    public static function rejected(): iterable
    {
        foreach (['<?php file_put_contents("/not-allowed", "x"); return [];', '<?php return [Unknown::VALUE];', '<?php return [getenv("KEY")];', '<?php return ["x"=>"$dynamic"];', '<?php return true ? [] : [];', '<?php return []; return [];', '<?php return [1;', '<?php return ["x"=>'.str_repeat('[', 70).'1'.str_repeat(']', 70).'];'] as $source) {
            yield [$source];
        }
    }

    #[DataProvider('rejected')]
    public function test_dynamic_or_invalid_configuration_is_rejected(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConfigurationReader::read($source);
    }
}
