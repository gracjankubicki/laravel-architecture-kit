<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Reads only authentication topology; credentials and unrelated config values are omitted. */
final class AuthConfigCatalogExtractor
{
    private int $visited = 0;

    /** @var list<CatalogDiagnostic> */
    private array $mapDiagnostics = [];

    public function extract(FileContext $file): CatalogFacts
    {
        $this->visited = 0;
        $this->mapDiagnostics = [];
        if ($file->path !== 'config/auth.php') {
            return new CatalogFacts($file->path);
        }
        $elements = $relations = $diagnostics = [];
        $owner = CatalogElement::identity($file->path, 'file', $file->path);
        $returns = array_values(array_filter($file->ast() ?? [], fn ($node) => $node instanceof Stmt\Return_));
        if (count($returns) !== 1 || ! $returns[0]->expr instanceof Expr\Array_) {
            return new CatalogFacts($file->path, diagnostics: [new CatalogDiagnostic('auth_config_dynamic', 'Authentication config return is dynamic or ambiguous.', 1, $owner)]);
        }
        $config = $this->map($returns[0]->expr);
        if (($config['defaults'] ?? null) instanceof Expr\Array_) {
            $defaults = $this->map($config['defaults']);
            $guard = $this->name($file, $defaults['guard'] ?? null);
            if ($guard !== null) {
                $relations[] = new CatalogRelation($owner, CatalogElement::resourceIdentity('auth-guard', $guard), 'default-auth-guard',
                    $defaults['guard']->getStartLine(), $defaults['guard']->getEndLine(), 'conditional', ['execution_proven' => false]);
            } elseif (isset($defaults['guard'])) {
                $diagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Default authentication guard selector is unresolved.', $defaults['guard']->getStartLine(), $owner);
            }
        } elseif (isset($config['defaults'])) {
            $diagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Default authentication settings are dynamic.', $config['defaults']->getStartLine(), $owner);
        }
        foreach (['guards' => 'auth-guard', 'providers' => 'auth-user-provider'] as $section => $kind) {
            if (! ($config[$section] ?? null) instanceof Expr\Array_) {
                $diagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Authentication '.$section.' declaration is absent or dynamic.', 1, $owner);

                continue;
            }
            foreach ($this->map($config[$section]) as $name => $definition) {
                if (! $definition instanceof Expr\Array_) {
                    $diagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Authentication resource definition is dynamic.', $definition->getStartLine(), $owner);

                    continue;
                }
                $values = $this->map($definition);
                $metadata = ['driver' => $this->name($file, $values['driver'] ?? null), 'execution_proven' => false];
                $id = CatalogElement::resourceIdentity($kind, $name);
                $elements[] = new CatalogElement($id, $name, $kind, $definition->getStartLine(), $definition->getEndLine(), $definition->getStartFilePos(), metadata: $metadata);
                $relations[] = new CatalogRelation($owner, $id, 'declares-auth-resource', $definition->getStartLine(), $definition->getEndLine(), 'conditional');
                $field = $section === 'guards' ? 'provider' : 'model';
                $target = $this->name($file, $values[$field] ?? null);
                if ($target !== null) {
                    $relations[] = new CatalogRelation($id, $section === 'guards' ? CatalogElement::resourceIdentity('auth-user-provider', $target) : 'php:'.$target,
                        $section === 'guards' ? 'uses-user-provider' : 'provides-user-model', $values[$field]->getStartLine(), $values[$field]->getEndLine(), 'conditional', ['execution_proven' => false]);
                } elseif (isset($values[$field]) || $section === 'guards') {
                    $diagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Authentication '.$field.' selector is unresolved.', $definition->getStartLine(), $id);
                }
                if ($metadata['driver'] === null) {
                    $diagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Authentication driver is unresolved.', $definition->getStartLine(), $id);
                }
            }
        }

        $known = array_column(array_map(fn ($element) => $element->toArray(), $elements), 'id');
        foreach ($relations as $relation) {
            if (in_array($relation->kind, ['uses-user-provider', 'default-auth-guard'], true) && ! in_array($relation->to, $known, true)) {
                $diagnostics[] = new CatalogDiagnostic('auth_config_missing', 'Selected authentication resource is absent from literal config declarations.', $relation->line, $relation->from);
            }
        }

        return new CatalogFacts($file->path, $elements, $relations, [...$diagnostics, ...$this->mapDiagnostics]);
    }

    /** @return array<string, Expr> */
    private function map(Expr\Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (++$this->visited > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->mapDiagnostics[] = new CatalogDiagnostic('auth_config_limit', 'Authentication config extraction reached its operation or memory budget.', $array->getStartLine());
                break;
            }
            if ($item !== null && ! $item->unpack && $item->key instanceof Node\Scalar\String_) {
                if (array_key_exists($item->key->value, $result)) {
                    $this->mapDiagnostics[] = new CatalogDiagnostic('auth_config_duplicate', 'Duplicate literal config key uses its final source declaration.', $item->getStartLine());
                }
                $result[$item->key->value] = $item->value;
            } elseif ($item !== null) {
                $this->mapDiagnostics[] = new CatalogDiagnostic('auth_config_dynamic', 'Dynamic config key or unpacking may add or replace source declarations.', $item->getStartLine());
            }
        }

        return $result;
    }

    private function name(FileContext $file, ?Expr $value): ?string
    {
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }
        if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class') {
            return $file->resolvedName($value->class);
        }

        return null;
    }
}
