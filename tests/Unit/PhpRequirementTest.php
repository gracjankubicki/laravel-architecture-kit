<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Taqie\ArchitectureKit\Support\PhpRequirement;

class PhpRequirementTest extends TestCase
{
    /**
     * @return iterable<string, array{constraint: string, expected: bool}>
     */
    public static function phpConstraints(): iterable
    {
        yield '^8.5 allows PHP 8.5+' => ['constraint' => '^8.5', 'expected' => true];
        yield 'greater than or equal allows PHP 8.5+' => ['constraint' => '>=8.5', 'expected' => true];
        yield '^8.6 allows only newer supported runtimes' => ['constraint' => '^8.6', 'expected' => true];
        yield '^9.0 allows only newer supported runtimes' => ['constraint' => '^9.0', 'expected' => true];
        yield '^8.2 does not require PHP 8.5+' => ['constraint' => '^8.2', 'expected' => false];
        yield 'union allowing PHP 8.2 does not require PHP 8.5+' => ['constraint' => '^8.2|^8.5', 'expected' => false];
    }

    /**
     * @dataProvider phpConstraints
     */
    public function test_it_detects_constraints_that_require_php_85_or_newer(string $constraint, bool $expected): void
    {
        $this->assertSame($expected, PhpRequirement::constraintRequiresPhp85($constraint));
    }
}
