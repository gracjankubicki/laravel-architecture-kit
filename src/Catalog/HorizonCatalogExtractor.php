<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Reads topology and worker limits; unrelated Horizon configuration is omitted. */
final class HorizonCatalogExtractor
{
    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visited = 0;

    public function extract(FileContext $file): CatalogFacts
    {
        $this->diagnostics = [];
        $this->visited = 0;
        if ($file->path !== 'config/horizon.php') {
            return new CatalogFacts($file->path);
        }
        $returns = array_values(array_filter($file->ast() ?? [], fn ($node) => $node instanceof Stmt\Return_));
        $config = count($returns) === 1 && $returns[0]->expr instanceof Expr\Array_ ? $this->map($returns[0]->expr) : null;
        if ($config === null) {
            $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon config return is dynamic or ambiguous.', 1);

            return new CatalogFacts($file->path, diagnostics: $this->diagnostics);
        }
        $elements = [];
        foreach (['defaults', 'environments'] as $section) {
            $values = ($config[$section] ?? null) instanceof Expr\Array_ ? $this->map($config[$section]) : null;
            if ($values === null) {
                $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon '.$section.' declaration is absent or dynamic.', 1);

                continue;
            }
            $environments = $section === 'defaults' ? ['' => $values] : $values;
            foreach ($environments as $environment => $supervisors) {
                if ($section === 'environments') {
                    if (! CatalogHorizonOptions::name($environment, true) || ! $supervisors instanceof Expr\Array_) {
                        $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon environment selector or options are unresolved.', 1);

                        continue;
                    }
                    $environmentNode = $supervisors;
                    $supervisors = $this->map($supervisors);
                    $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'horizon-environment', $environment, max(0, $environmentNode->getStartFilePos())),
                        'Horizon environment '.$environment, 'horizon-environment', max(1, $environmentNode->getStartLine()), max(1, $environmentNode->getEndLine()), max(0, $environmentNode->getStartFilePos()),
                        CatalogElement::identity($file->path, 'file', $file->path), metadata: ['environment' => $environment, 'resolved' => $supervisors !== null]);
                }
                foreach ($supervisors ?? [] as $name => $definition) {
                    if (! CatalogHorizonOptions::name($name) || ! $definition instanceof Expr\Array_) {
                        $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon supervisor definition is unresolved.', 1);

                        continue;
                    }
                    $fields = $this->map($definition);
                    $options = [];
                    $unresolved = $fields === null ? ['definition'] : [];
                    foreach ($fields ?? [] as $field => $expression) {
                        if (! in_array($field, CatalogHorizonOptions::FIELDS, true)) {
                            continue;
                        }
                        $value = null;
                        if ($field === 'queue' && $expression instanceof Expr\Array_ && count($expression->items) <= 128) {
                            $value = [];
                            foreach ($expression->items as $item) {
                                if ($item === null || $item->key !== null || $item->unpack || $item->byRef || ! $item->value instanceof Scalar\String_ || ! CatalogHorizonOptions::name($item->value->value)) {
                                    $value = null;
                                    break;
                                }
                                $value[] = $item->value->value;
                            }
                        } elseif ($expression instanceof Scalar\String_) {
                            if ($field === 'queue') {
                                $names = explode(',', $expression->value);
                                if (count($names) <= 128 && count(array_filter($names, fn ($name) => CatalogHorizonOptions::name($name))) === count($names)) {
                                    $value = $expression->value;
                                }
                            } elseif (in_array($field, ['connection', 'balance', 'autoScalingStrategy'], true) && CatalogHorizonOptions::name($expression->value)) {
                                $value = $expression->value;
                            }
                        } elseif ($field === 'balance' && $expression instanceof Expr\ConstFetch && strtolower($expression->name->toString()) === 'false') {
                            $value = false;
                        } elseif (in_array($field, CatalogHorizonOptions::NUMERIC, true)) {
                            $integer = $expression instanceof Scalar\Int_ ? $expression->value : ($expression instanceof Expr\UnaryMinus && $expression->expr instanceof Scalar\Int_ ? -$expression->expr->value : null);
                            if ($integer !== null && $integer >= ($field === 'nice' ? -20 : 0) && $integer <= 2147483647) {
                                $value = $integer;
                            }
                        }
                        $options[$field] = $value;
                        if ($value === null) {
                            $unresolved[] = $field;
                            $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon '.$field.' option is dynamic or unsupported.', max(1, $expression->getStartLine()));
                            if ($field === 'queue' && $expression instanceof Expr\Array_ && count($expression->items) > 128) {
                                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Horizon queue list exceeds its source budget.', max(1, $expression->getStartLine()));
                            }
                        }
                    }
                    $offset = max(0, $definition->getStartFilePos());
                    $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'horizon-definition', $section.':'.$environment.':'.$name, $offset), 'Horizon '.$environment.':'.$name,
                        'horizon-definition', max(1, $definition->getStartLine()), max(1, $definition->getEndLine()), $offset, CatalogElement::identity($file->path, 'file', $file->path),
                        metadata: ['section' => $section, 'environment' => $section === 'defaults' ? null : $environment, 'supervisor' => $name, 'options' => $options, 'unresolved_fields' => $unresolved]);
                }
            }
        }

        return new CatalogFacts($file->path, $elements, [], $this->diagnostics);
    }

    /** @return array<string, Expr>|null */
    private function map(Expr\Array_ $array): ?array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (++$this->visited > 4096 || count($array->items) > 128 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Horizon source map reached its operation or memory budget.', max(1, $array->getStartLine()));

                return null;
            }
            if ($item === null || $item->unpack || $item->byRef || ! $item->key instanceof Scalar\String_) {
                $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Dynamic Horizon map key or unpacking prevents exact source composition.', max(1, $array->getStartLine()));

                return null;
            }
            $key = $item->key->value;
            if (isset($result[$key])) {
                $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Duplicate Horizon map key uses its last source declaration.', max(1, $item->getStartLine()));
            }
            $result[$key] = $item->value;
        }

        return $result;
    }
}
