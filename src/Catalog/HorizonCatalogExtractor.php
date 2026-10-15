<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

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
        $returns = array_values(array_filter($file->syntax() ?? [], fn ($node) => Node::is($node, 'statement.return')));
        $config = count($returns) === 1 && Node::is($returns[0]->expr, 'expression.array') ? $this->map($returns[0]->expr) : null;
        if ($config === null) {
            $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon config return is dynamic or ambiguous.', 1);

            return new CatalogFacts($file->path, diagnostics: $this->diagnostics);
        }
        $elements = [];
        foreach (['defaults', 'environments'] as $section) {
            $values = Node::is(($config[$section] ?? null), 'expression.array') ? $this->map($config[$section]) : null;
            if ($values === null) {
                $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon '.$section.' declaration is absent or dynamic.', 1);

                continue;
            }
            $environments = $section === 'defaults' ? ['' => $values] : $values;
            foreach ($environments as $environment => $supervisors) {
                if ($section === 'environments') {
                    if (! CatalogHorizonOptions::name($environment, true) || ! Node::is($supervisors, 'expression.array')) {
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
                    if (! CatalogHorizonOptions::name($name) || ! Node::is($definition, 'expression.array')) {
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
                        if ($field === 'queue' && Node::is($expression, 'expression.array') && count($expression->items) <= 128) {
                            $value = [];
                            foreach ($expression->items as $item) {
                                if ($item === null || $item->key !== null || $item->unpack || $item->byRef || ! Node::is($item->value, 'scalar.string') || ! CatalogHorizonOptions::name($item->value->value)) {
                                    $value = null;
                                    break;
                                }
                                $value[] = $item->value->value;
                            }
                        } elseif (Node::is($expression, 'scalar.string')) {
                            if ($field === 'queue') {
                                $names = explode(',', $expression->value);
                                if (count($names) <= 128 && count(array_filter($names, fn ($name) => CatalogHorizonOptions::name($name))) === count($names)) {
                                    $value = $expression->value;
                                }
                            } elseif (in_array($field, ['connection', 'balance', 'autoScalingStrategy'], true) && CatalogHorizonOptions::name($expression->value)) {
                                $value = $expression->value;
                            }
                        } elseif ($field === 'balance' && Node::is($expression, 'expression.const-fetch') && strtolower($expression->name->toString()) === 'false') {
                            $value = false;
                        } elseif (in_array($field, CatalogHorizonOptions::NUMERIC, true)) {
                            $integer = Node::is($expression, 'scalar.int') ? $expression->value : (Node::is($expression, 'expression.unary-minus') && Node::is($expression->expr, 'scalar.int') ? -$expression->expr->value : null);
                            if ($integer !== null && $integer >= ($field === 'nice' ? -20 : 0) && $integer <= 2147483647) {
                                $value = $integer;
                            }
                        }
                        $options[$field] = $value;
                        if ($value === null) {
                            $unresolved[] = $field;
                            $this->diagnostics[] = new CatalogDiagnostic('horizon_config_analysis', 'Horizon '.$field.' option is dynamic or unsupported.', max(1, $expression->getStartLine()));
                            if ($field === 'queue' && Node::is($expression, 'expression.array') && count($expression->items) > 128) {
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

    /** @return array<string, Node>|null */
    private function map(Node $array): ?array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (++$this->visited > 4096 || count($array->items) > 128 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Horizon source map reached its operation or memory budget.', max(1, $array->getStartLine()));

                return null;
            }
            if ($item === null || $item->unpack || $item->byRef || ! Node::is($item->key, 'scalar.string')) {
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
