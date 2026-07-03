<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ArchitectureResourceLanguageTest extends TestCase
{
    public function test_architecture_resources_are_english_only(): void
    {
        $root = dirname(__DIR__, 2).'/resources/architectures';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('PL:', $contents, $file->getPathname());
            $this->assertStringNotContainsString('Reguły PL:', $contents, $file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $contents, $file->getPathname());
        }
    }
}
