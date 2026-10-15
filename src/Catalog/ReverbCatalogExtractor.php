<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Credentials, application IDs and message payloads are never retained. */
final class ReverbCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visited = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = [];
        $this->visited = 0;
        $owners = [];
        foreach ($php->elements as $element) {
            if ($element->kind === 'method' && str_ends_with(strtolower($element->name), '::broadcastconnections')) {
                $owners[$element->offset] = $element;
            }
        }
        if ($owners !== []) {
            $pending = $file->syntax() ?? [];
            while ($pending !== []) {
                if (++$this->visited > 25000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Broadcast connection facts reached their AST or memory budget.', 1);
                    break;
                }
                $node = array_pop($pending);
                if (Node::is($node, 'statement.class-method') && isset($owners[$node->getStartFilePos()])) {
                    $this->method($file, $owners[$node->getStartFilePos()], $node);
                }
                foreach ($node->getSubNodeNames() as $key) {
                    foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                        if (Node::is($child, 'node')) {
                            $pending[] = $child;
                        }
                    }
                }
            }
        }
        if (in_array($file->path, ['config/reverb.php', 'config/broadcasting.php'], true)) {
            $this->configuration($file);
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function configuration(FileContext $file): void
    {
        $returns = array_values(array_filter($file->syntax() ?? [], fn ($node) => Node::is($node, 'statement.return')));
        $config = count($returns) === 1 && Node::is($returns[0]->expr, 'expression.array') ? $this->map($returns[0]->expr) : null;
        if ($config === null) {
            $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Broadcast/Reverb config return is dynamic or ambiguous.', 1);

            return;
        }
        if ($file->path === 'config/broadcasting.php') {
            if (isset($config['default'])) {
                $selector = $this->name($config['default']);
                $site = $config['default'];
                $offset = max(0, $site->getStartFilePos());
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'broadcast-connection-default', 'default', $offset), 'Default broadcast connection',
                    'broadcast-connection-default', max(1, $site->getStartLine()), max(1, $site->getEndLine()), $offset, CatalogElement::identity($file->path, 'file', $file->path),
                    metadata: ['selector' => $selector, 'resolved' => $selector !== null]);
            }
            $connections = Node::is(($config['connections'] ?? null), 'expression.array') ? $this->map($config['connections']) : null;
            foreach ($connections ?? [] as $name => $node) {
                $options = Node::is($node, 'expression.array') ? $this->map($node) : null;
                $driver = $options['driver'] ?? null;
                if (Node::is($driver, 'scalar.string') && $driver->value !== 'reverb') {
                    continue;
                }
                if (! Node::is($driver, 'scalar.string') || $driver->value !== 'reverb' || ! CatalogHorizonOptions::name($name)) {
                    $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Broadcast connection driver is dynamic or unsupported.', max(1, $node->getStartLine()));

                    continue;
                }
                $address = Node::is(($options['options'] ?? null), 'expression.array') ? $this->map($options['options']) : [];
                $this->definition($file, $node, 'connection', $name, $address ?? []);
            }

            return;
        }
        $servers = Node::is(($config['servers'] ?? null), 'expression.array') ? $this->map($config['servers']) : null;
        foreach ($servers ?? [] as $name => $node) {
            $options = Node::is($node, 'expression.array') ? $this->map($node) : null;
            if ($name !== 'reverb' || $options === null) {
                $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb server definition is dynamic or has an unsupported server driver.', max(1, $node->getStartLine()));

                continue;
            }
            $this->definition($file, $node, 'server', $name, $options);
        }
        $apps = Node::is(($config['apps'] ?? null), 'expression.array') ? $this->map($config['apps']) : null;
        $provider = $apps['provider'] ?? null;
        if (! Node::is($provider, 'scalar.string') || $provider->value !== 'config' || ! Node::is(($apps['apps'] ?? null), 'expression.array') || count($apps['apps']->items) > 128) {
            $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb application provider or list requires source resolution.', 1);

            return;
        }
        foreach ($apps['apps']->items as $position => $item) {
            $options = $item !== null && ! $item->unpack && ! $item->byRef && Node::is($item->value, 'expression.array') ? $this->map($item->value) : null;
            if ($options === null) {
                $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb application definition is dynamic.', 1);

                continue;
            }
            $address = Node::is(($options['options'] ?? null), 'expression.array') ? $this->map($options['options']) : [];
            $this->definition($file, $item->value, 'application', 'app-'.$position, $address ?? []);
        }
    }

    /** @param array<string, Node> $options */
    private function definition(FileContext $file, Node $site, string $form, ?string $selector, array $options): void
    {
        $host = $options['host'] ?? null;
        $host = Node::is($host, 'scalar.string') && preg_match('/\A[a-zA-Z0-9_.:\[\]-]{1,253}\z/D', $host->value) === 1 ? $host->value : null;
        $port = $options['port'] ?? null;
        $port = Node::is($port, 'scalar.int') && $port->value > 0 && $port->value <= 65535 ? $port->value : null;
        $scheme = $options['scheme'] ?? null;
        $scheme = Node::is($scheme, 'scalar.string') && in_array($scheme->value, ['http', 'https'], true) ? $scheme->value : null;
        $offset = max(0, $site->getStartFilePos());
        $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'reverb-definition', $form.':'.($selector ?? 'unknown'), $offset), 'Reverb '.$form.' '.($selector ?? 'unknown'),
            'reverb-definition', max(1, $site->getStartLine()), max(1, $site->getEndLine()), $offset, CatalogElement::identity($file->path, 'file', $file->path),
            metadata: ['form' => $form, 'selector' => $selector, 'host' => $host, 'port' => $port, 'scheme' => $scheme, 'resolved' => $selector !== null]);
        if ($selector === null || $host === null && isset($options['host']) || $port === null && isset($options['port']) || $scheme === null && isset($options['scheme'])) {
            $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb address or connection selector depends on dynamic configuration.', max(1, $site->getStartLine()));
        }
    }

    private function method(FileContext $file, CatalogElement $owner, Node $node): void
    {
        $value = count($node->stmts ?? []) === 1 && Node::is($node->stmts[0], 'statement.return') ? $node->stmts[0]->expr : null;
        $connections = [];
        $resolved = Node::is($value, 'expression.array') && count($value->items) <= 128 && $node->isPublic() && ! $node->isStatic();
        foreach ($node->params as $parameter) {
            $resolved = $resolved && ($parameter->default !== null || $parameter->variadic);
        }
        foreach (Node::is($value, 'expression.array') && count($value->items) <= 128 ? $value->items : [] as $item) {
            if ($item === null) {
                $resolved = false;

                continue;
            }
            $name = $this->name($item->value);
            $null = Node::is($item->value, 'expression.const-fetch') && strtolower($item->value->name->toString()) === 'null';
            $resolved = $resolved && ! $item->unpack && ! $item->byRef && ($name !== null || $null);
            if ($name !== null || $null) {
                $connections[] = $name;
            }
        }
        $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'broadcast-connection-selector', $owner->name, $owner->offset), 'Connections for '.$owner->name,
            'broadcast-connection-selector', $owner->line, $owner->endLine, $owner->offset, $owner->id, metadata: ['connections' => $connections, 'resolved' => $resolved]);
    }

    private function name(Node $value): ?string
    {
        return Node::is($value, 'scalar.string') && CatalogHorizonOptions::name($value->value) ? $value->value : null;
    }

    /** @return array<string, Node>|null */
    private function map(Node $array): ?array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (++$this->visited > 25000 || count($array->items) > 128 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Reverb config map reached its source budget.', max(1, $array->getStartLine()));

                return null;
            }
            if ($item === null || $item->unpack || $item->byRef || ! Node::is($item->key, 'scalar.string')) {
                $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Dynamic Reverb config key or unpacking prevents exact composition.', max(1, $array->getStartLine()));

                return null;
            }
            $result[$item->key->value] = $item->value;
        }

        return $result;
    }
}
