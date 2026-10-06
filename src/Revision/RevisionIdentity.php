<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;

/** Content evidence for moves ignores comments and layout, never executes source. */
final readonly class RevisionIdentity
{
    /** @return array<string, string> */
    public static function declarations(FileContext $file): array
    {
        $shapes = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], ClassLike::class) as $class) {
            if ($class->name === null || ! isset($class->namespacedName)) {
                continue;
            }
            $name = $class->namespacedName->toString();
            $declaration = substr($file->contents, $class->getStartFilePos(), $class->getEndFilePos() - $class->getStartFilePos() + 1);
            $shapes[strtolower($name)] = self::shape('<?php '.$declaration, $name);
        }

        return $shapes;
    }

    public static function shape(string $source, ?string $class = null): string
    {
        $short = $class === null ? null : substr($class, (int) strrpos('\\'.$class, '\\'));
        $parts = [];
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $parts[] = $token;

                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text = $token[0] === T_OPEN_TAG ? '<?php' : $token[1];
            if ($class !== null && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && (strcasecmp($text, $short ?? '') === 0 || strcasecmp(ltrim($text, '\\'), $class) === 0)) {
                $text = '@declaration';
            }
            $parts[] = [$token[0], $text];
        }

        return hash('sha256', serialize($parts));
    }
}
