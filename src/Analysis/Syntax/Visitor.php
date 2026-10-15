<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

/** A visitor observes nodes or returns an immutable replacement. */
abstract class Visitor
{
    public function enterNode(Node $node): Node|int|null
    {
        return null;
    }

    public function leaveNode(Node $node): Node|int|null
    {
        return null;
    }
}
