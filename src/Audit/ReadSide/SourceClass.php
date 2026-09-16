<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node\Stmt\ClassLike;

final readonly class SourceClass
{
    public function __construct(public string $name, public FileContext $file, public ClassLike $node) {}
}
