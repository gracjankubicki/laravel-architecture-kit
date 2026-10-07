<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

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
            $pending = $file->ast() ?? [];
            while ($pending !== []) {
                if (++$this->visited > 25000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Broadcast connection facts reached their AST or memory budget.', 1);
                    break;
                }
                $node = array_pop($pending);
                if ($node instanceof Stmt\ClassMethod && isset($owners[$node->getStartFilePos()])) {
                    $this->method($file, $owners[$node->getStartFilePos()], $node);
                }
                foreach ($node->getSubNodeNames() as $key) {
                    foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                        if ($child instanceof Node) {
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
        $returns = array_values(array_filter($file->ast() ?? [], fn ($node) => $node instanceof Stmt\Return_));
        $config = count($returns) === 1 && $returns[0]->expr instanceof Expr\Array_ ? $this->map($returns[0]->expr) : null;
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
            $connections = ($config['connections'] ?? null) instanceof Expr\Array_ ? $this->map($config['connections']) : null;
            foreach ($connections ?? [] as $name => $node) {
                $options = $node instanceof Expr\Array_ ? $this->map($node) : null;
                $driver = $options['driver'] ?? null;
                if ($driver instanceof Scalar\String_ && $driver->value !== 'reverb') {
                    continue;
                }
                if (! $driver instanceof Scalar\String_ || $driver->value !== 'reverb' || ! CatalogHorizonOptions::name($name)) {
                    $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Broadcast connection driver is dynamic or unsupported.', max(1, $node->getStartLine()));

                    continue;
                }
                $address = ($options['options'] ?? null) instanceof Expr\Array_ ? $this->map($options['options']) : [];
                $this->definition($file, $node, 'connection', $name, $address ?? []);
            }

            return;
        }
        $servers = ($config['servers'] ?? null) instanceof Expr\Array_ ? $this->map($config['servers']) : null;
        foreach ($servers ?? [] as $name => $node) {
            $options = $node instanceof Expr\Array_ ? $this->map($node) : null;
            if ($name !== 'reverb' || $options === null) {
                $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb server definition is dynamic or has an unsupported server driver.', max(1, $node->getStartLine()));

                continue;
            }
            $this->definition($file, $node, 'server', $name, $options);
        }
        $apps = ($config['apps'] ?? null) instanceof Expr\Array_ ? $this->map($config['apps']) : null;
        $provider = $apps['provider'] ?? null;
        if (! $provider instanceof Scalar\String_ || $provider->value !== 'config' || ! ($apps['apps'] ?? null) instanceof Expr\Array_ || count($apps['apps']->items) > 128) {
            $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb application provider or list requires source resolution.', 1);

            return;
        }
        foreach ($apps['apps']->items as $position => $item) {
            $options = $item !== null && ! $item->unpack && ! $item->byRef && $item->value instanceof Expr\Array_ ? $this->map($item->value) : null;
            if ($options === null) {
                $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb application definition is dynamic.', 1);

                continue;
            }
            $address = ($options['options'] ?? null) instanceof Expr\Array_ ? $this->map($options['options']) : [];
            $this->definition($file, $item->value, 'application', 'app-'.$position, $address ?? []);
        }
    }

    /** @param array<string, Expr> $options */
    private function definition(FileContext $file, Node $site, string $form, ?string $selector, array $options): void
    {
        $host = $options['host'] ?? null;
        $host = $host instanceof Scalar\String_ && preg_match('/\A[a-zA-Z0-9_.:\[\]-]{1,253}\z/D', $host->value) === 1 ? $host->value : null;
        $port = $options['port'] ?? null;
        $port = $port instanceof Scalar\Int_ && $port->value > 0 && $port->value <= 65535 ? $port->value : null;
        $scheme = $options['scheme'] ?? null;
        $scheme = $scheme instanceof Scalar\String_ && in_array($scheme->value, ['http', 'https'], true) ? $scheme->value : null;
        $offset = max(0, $site->getStartFilePos());
        $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'reverb-definition', $form.':'.($selector ?? 'unknown'), $offset), 'Reverb '.$form.' '.($selector ?? 'unknown'),
            'reverb-definition', max(1, $site->getStartLine()), max(1, $site->getEndLine()), $offset, CatalogElement::identity($file->path, 'file', $file->path),
            metadata: ['form' => $form, 'selector' => $selector, 'host' => $host, 'port' => $port, 'scheme' => $scheme, 'resolved' => $selector !== null]);
        if ($selector === null || $host === null && isset($options['host']) || $port === null && isset($options['port']) || $scheme === null && isset($options['scheme'])) {
            $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Reverb address or connection selector depends on dynamic configuration.', max(1, $site->getStartLine()));
        }
    }

    private function method(FileContext $file, CatalogElement $owner, Stmt\ClassMethod $node): void
    {
        $value = count($node->stmts ?? []) === 1 && $node->stmts[0] instanceof Stmt\Return_ ? $node->stmts[0]->expr : null;
        $connections = [];
        $resolved = $value instanceof Expr\Array_ && count($value->items) <= 128 && $node->isPublic() && ! $node->isStatic();
        foreach ($node->params as $parameter) {
            $resolved = $resolved && ($parameter->default !== null || $parameter->variadic);
        }
        foreach ($value instanceof Expr\Array_ && count($value->items) <= 128 ? $value->items : [] as $item) {
            if ($item === null) {
                $resolved = false;

                continue;
            }
            $name = $this->name($item->value);
            $null = $item->value instanceof Expr\ConstFetch && strtolower($item->value->name->toString()) === 'null';
            $resolved = $resolved && ! $item->unpack && ! $item->byRef && ($name !== null || $null);
            if ($name !== null || $null) {
                $connections[] = $name;
            }
        }
        $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'broadcast-connection-selector', $owner->name, $owner->offset), 'Connections for '.$owner->name,
            'broadcast-connection-selector', $owner->line, $owner->endLine, $owner->offset, $owner->id, metadata: ['connections' => $connections, 'resolved' => $resolved]);
    }

    private function name(Expr $value): ?string
    {
        return $value instanceof Scalar\String_ && CatalogHorizonOptions::name($value->value) ? $value->value : null;
    }

    /** @return array<string, Expr>|null */
    private function map(Expr\Array_ $array): ?array
    {
        $result = [];
        foreach ($array->items as $item) {
            if (++$this->visited > 25000 || count($array->items) > 128 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Reverb config map reached its source budget.', max(1, $array->getStartLine()));

                return null;
            }
            if ($item === null || $item->unpack || $item->byRef || ! $item->key instanceof Scalar\String_) {
                $this->diagnostics[] = new CatalogDiagnostic('reverb_config_analysis', 'Dynamic Reverb config key or unpacking prevents exact composition.', max(1, $array->getStartLine()));

                return null;
            }
            $result[$item->key->value] = $item->value;
        }

        return $result;
    }
}
