<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Support\Str;

/** Only known helpers/facades prove these operations. Payload arguments are omitted. */
final class ResourceCatalogExtractor
{
    /** @var array<string, CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogRelation> */
    private array $relations = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, string> */
    private array $owners = [];

    private int $visited = 0;

    private bool $limited = false;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->relations = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        $this->limited = false;
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'interface', 'trait', 'enum', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element->id;
            }
        }
        if (count($php->elements) === 1 && array_filter($php->diagnostics, fn ($diagnostic) => in_array($diagnostic->code, ['source_limit', 'parse_error', 'catalog_limit'], true)) !== []) {
            return new CatalogFacts($file->path);
        }
        $owner = CatalogElement::identity($file->path, 'file', $file->path);
        foreach ($file->syntax() ?? [] as $node) {
            $this->visit($file, $node, $owner);
        }

        $packages = (new PackageCatalogExtractor)->extract($file, $php);
        $inertia = (new InertiaCatalogExtractor)->extract($file, $php);
        $fortify = (new FortifyCatalogExtractor)->extract($file, $php);
        $ai = (new AiCatalogExtractor)->extract($file, $php);
        $pennant = (new PennantCatalogExtractor)->extract($file, $php);
        $scout = (new ScoutCatalogExtractor)->extract($file, $php);
        $socialite = (new SocialiteCatalogExtractor)->extract($file, $php);
        $cashier = (new CashierCatalogExtractor)->extract($file, $php);
        $horizon = (new HorizonCatalogExtractor)->extract($file);
        $reverb = (new ReverbCatalogExtractor)->extract($file, $php);
        $livewire = (new LivewireCatalogExtractor)->extract($file, $php);
        $saloonPool = (new SaloonPoolCatalogExtractor)->extract($file, $php);

        return new CatalogFacts($file->path, [...array_values($this->elements), ...$packages->elements, ...$inertia->elements, ...$fortify->elements, ...$ai->elements, ...$pennant->elements, ...$scout->elements, ...$socialite->elements, ...$cashier->elements, ...$horizon->elements, ...$reverb->elements, ...$livewire->elements, ...$saloonPool->elements], $this->relations, [...$this->diagnostics, ...$packages->diagnostics, ...$inertia->diagnostics, ...$fortify->diagnostics, ...$ai->diagnostics, ...$pennant->diagnostics, ...$scout->diagnostics, ...$socialite->diagnostics, ...$cashier->diagnostics, ...$horizon->diagnostics, ...$reverb->diagnostics, ...$livewire->diagnostics, ...$saloonPool->diagnostics]);
    }

    private function visit(FileContext $file, Node $node, string $owner): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || ($this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null)) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('resource_limit', 'Resource analysis reached its node or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
        }
        $handled = $this->storeOperation($file, $owner, $node);
        if (! $handled && Node::is($node, 'expression.func-call') && Node::is($node->name, 'name')) {
            $name = strtolower($file->resolvedName($node->name));
            if (in_array($name, ['view', 'config', 'cache'], true) && ! $node->isFirstClassCallable()) {
                $kind = ['view' => 'view', 'config' => 'config-key', 'cache' => 'cache-key'][$name];
                $this->resource($file, $owner, $node, $kind, $this->argument($node, 0, $name === 'view' ? 'view' : 'key'), $name === 'view' ? 'renders' : 'reads', ['api_helper' => $name, 'api_functions' => $this->functionCandidates($file, $node->name)]);
            }
        } elseif (! $handled && (Node::is($node, 'expression.static-call') || Node::is($node, 'expression.method-call')) && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()) {
            $receiver = $node;
            while (Node::is($receiver, 'expression.method-call')) {
                $receiver = $receiver->var;
            }
            if (Node::is($receiver, 'expression.static-call') && Node::is($receiver->class, 'name')) {
                $class = strtolower($file->resolvedName($receiver->class));
                $method = strtolower($node->name->toString());
                if ($class === 'illuminate\\support\\facades\\view') {
                    if (in_array($method, ['make', 'exists', 'first'], true)) {
                        $this->resource($file, $owner, $node, 'view', $this->argument($node, 0, $method === 'first' ? 'views' : 'view'), $method === 'exists' ? 'checks-view' : 'renders', ['api_receiver' => $file->resolvedName($receiver->class)]);
                    } elseif (in_array($method, ['composer', 'creator'], true)) {
                        $this->viewCallbacks($file, $owner, $node, $method);
                    }
                } elseif ($class === 'illuminate\\support\\facades\\broadcast' && $method === 'channel') {
                    $this->channelAuthorization($file, $owner, $node);
                } elseif ($class === 'illuminate\\support\\facades\\blade' && $method === 'component') {
                    $this->component($file, $owner, $node);
                } elseif ($class === 'illuminate\\support\\facades\\http' && in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                    $this->httpOperation($file, $owner, $node, $method);
                } elseif ($class === 'illuminate\\support\\facades\\mail' && in_array($method, ['send', 'sendnow', 'queue', 'later'], true)) {
                    $argument = $this->argument($node, $method === 'later' ? 1 : 0, Node::is($node, 'expression.method-call') || $method === 'sendnow' ? 'mailable' : 'view');
                    if (Node::is($argument, 'expression.new') && Node::is($argument->class, 'name')) {
                        $this->relations[] = new CatalogRelation($owner, 'php:'.$file->resolvedName($argument->class), 'sends-mail', max(1, $node->getStartLine()), max(1, $node->getEndLine()), metadata: ['queued' => $method === 'sendnow' ? false : ($method === 'send' ? null : true), 'mode' => $method, 'execution_proven' => false]);
                    } else {
                        $this->resource($file, $owner, $node, 'view', $argument, 'sends-mail', ['queued' => $method !== 'send', 'mode' => $method, 'execution_proven' => false]);
                    }
                } elseif ($class === 'illuminate\\support\\facades\\notification' && in_array($method, ['send', 'sendnow'], true)) {
                    $notification = $this->argument($node, 1, 'notification');
                    if (Node::is($notification, 'expression.new') && Node::is($notification->class, 'name')) {
                        $this->relations[] = new CatalogRelation($owner, 'php:'.$file->resolvedName($notification->class), 'sends-notification', max(1, $node->getStartLine()), max(1, $node->getEndLine()), metadata: ['mode' => $method, 'queued' => $method === 'sendnow' ? false : null, 'execution_proven' => false]);
                    } else {
                        $this->diagnostics[] = new CatalogDiagnostic('dynamic_notification', 'Notification target requires source inspection.', max(1, $node->getStartLine()), $owner);
                    }
                }
            }
        }
        if (Node::is($node, 'expression.new') && Node::is($node->class, 'name') && strcasecmp($file->resolvedName($node->class), 'Illuminate\\Mail\\Mailables\\Content') === 0) {
            foreach (['view' => 0, 'html' => 1, 'text' => 2, 'markdown' => 3] as $name => $position) {
                $view = $this->argument($node, $position, $name);
                if ($view !== null && ! (Node::is($view, 'expression.const-fetch') && strtolower($view->name->toString()) === 'null')) {
                    $this->resource($file, $owner, $node, 'view', $view, 'mail-view-candidate', ['view_api' => 'content', 'view_parameter' => $name, 'execution_proven' => false]);
                }
            }
        } elseif (Node::is($node, 'expression.method-call') && Node::is($node->var, 'expression.variable') && $node->var->name === 'this'
            && Node::is($node->name, 'identifier') && in_array(strtolower($node->name->toString()), ['view', 'text', 'markdown'], true) && ! $node->isFirstClassCallable()) {
            $this->resource($file, $owner, $node, 'view', $this->argument($node, 0, 'view'), 'mail-view-candidate',
                ['view_api' => 'mailable-method', 'view_parameter' => strtolower($node->name->toString()), 'execution_proven' => false]);
        }
        if (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier') && in_array(strtolower($node->name->toString()), ['view', 'text', 'markdown'], true) && ! $node->isFirstClassCallable()) {
            $message = $node->var;
            while (Node::is($message, 'expression.method-call')) {
                $message = $message->var;
            }
            if (Node::is($message, 'expression.new') && Node::is($message->class, 'name') && strcasecmp($file->resolvedName($message->class), 'Illuminate\\Notifications\\Messages\\MailMessage') === 0) {
                $this->resource($file, $owner, $node, 'view', $this->argument($node, 0, strtolower($node->name->toString()) === 'text' ? 'textView' : 'view'), 'notification-view-candidate',
                    ['view_api' => 'notification-mail-message', 'view_parameter' => strtolower($node->name->toString()), 'execution_proven' => false]);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }

    private function viewCallbacks(FileContext $file, string $owner, Node $call, string $method): void
    {
        $selector = $this->argument($call, 0, 'views');
        $selectors = Node::is($selector, 'expression.array') ? array_map(fn ($item) => $item !== null && ! $item->unpack ? $item->value : null, $selector->items) : [$selector];
        foreach (array_slice($selectors, 0, 256) as $selector) {
            $view = $this->resource($file, $owner, $call, 'view', $selector, 'registers-'.$method);
            if ($view === null) {
                continue;
            }
            $handler = $this->argument($call, 1, 'callback');
            $target = null;
            $callbackMethod = $method === 'composer' ? 'compose' : 'create';
            if (Node::is($handler, 'expression.class-const-fetch') && Node::is($handler->class, 'name') && Node::is($handler->name, 'identifier') && strtolower($handler->name->toString()) === 'class') {
                $target = $file->resolvedName($handler->class);
            } elseif (Node::is($handler, 'scalar.string') && preg_match('/\\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*(?:@[a-zA-Z_][a-zA-Z0-9_]*)?\\z/D', $handler->value) === 1) {
                $parts = explode('@', ltrim($handler->value, '\\'), 2);
                $target = $parts[0];
                $callbackMethod = $parts[1] ?? $callbackMethod;
            }
            if ($target !== null) {
                $this->relations[] = new CatalogRelation($view, 'php:'.$target, 'registers-view-'.$method.'-handler', max(1, $call->getStartLine()), max(1, $call->getEndLine()),
                    metadata: ['callback_method' => $callbackMethod, 'execution_stage' => $method === 'composer' ? 'view-composing' : 'view-creating', 'registration_activation_known' => false, 'execution_proven' => false]);
            } elseif (Node::is($handler, 'expression.closure') || Node::is($handler, 'expression.arrow-function')) {
                $callback = $this->owners[$handler->getStartFilePos()] ?? null;
                if ($callback !== null) {
                    $this->relations[] = new CatalogRelation($view, $callback, 'view-'.$method, max(1, $call->getStartLine()), max(1, $call->getEndLine()),
                        metadata: ['execution_stage' => $method === 'composer' ? 'view-composing' : 'view-creating', 'registration_activation_known' => false, 'execution_proven' => false]);
                }
            } else {
                $this->diagnostics[] = new CatalogDiagnostic('dynamic_view_callback', 'View callback cannot be resolved from this declaration.', max(1, $call->getStartLine()), $owner);
            }
        }
        if (count($selectors) > 256) {
            $this->diagnostics[] = new CatalogDiagnostic('resource_limit', 'View registration selector budget reached.', max(1, $call->getStartLine()), $owner);
        }
    }

    private function channelAuthorization(FileContext $file, string $owner, Node $call): void
    {
        $selector = $this->argument($call, 0, 'channel');
        if (! Node::is($selector, 'scalar.string') || preg_match('/\\A[a-zA-Z0-9_.{}*-]{1,256}\\z/D', $selector->value) !== 1) {
            $this->diagnostics[] = new CatalogDiagnostic('dynamic_channel_authorization', 'Channel authorization selector is dynamic or has an unsupported shape.', max(1, $call->getStartLine()), $owner);

            return;
        }
        $id = CatalogElement::resourceIdentity('broadcast-subscription', $selector->value);
        $options = $this->argument($call, 2, 'options');
        $guards = [];
        $guardsResolved = $options === null || Node::is($options, 'expression.array');
        foreach (Node::is($options, 'expression.array') ? $options->items : [] as $item) {
            if ($item === null || $item->unpack) {
                $guardsResolved = false;
            } elseif (Node::is($item->key, 'scalar.string') && $item->key->value === 'guards') {
                if (! Node::is($item->value, 'expression.array')) {
                    $guardsResolved = false;

                    continue;
                }
                foreach ($item->value->items as $guard) {
                    if ($guard !== null && ! $guard->unpack && Node::is($guard->value, 'scalar.string') && preg_match('/\\A[a-zA-Z0-9_.-]{1,128}\\z/D', $guard->value->value) === 1) {
                        $guards[] = $guard->value->value;
                    } else {
                        $guardsResolved = false;
                    }
                }
            } elseif (! Node::is($item->key, 'scalar.string')) {
                $guardsResolved = false;
            }
        }
        $this->elements[$id] = new CatalogElement($id, $selector->value, 'broadcast-subscription', max(1, $call->getStartLine()), max(1, $call->getEndLine()), max(0, $call->getStartFilePos()),
            metadata: ['logical_resource' => true, 'guards' => array_values(array_unique($guards)), 'guards_resolved' => $guardsResolved, 'runtime_activation_known' => false]);
        $this->relations[] = new CatalogRelation($owner, $id, 'registers-channel-authorization', max(1, $call->getStartLine()), max(1, $call->getEndLine()), metadata: ['execution_proven' => false]);
        $callback = $this->argument($call, 1, 'callback');
        $target = null;
        $kind = 'registers-channel-class';
        if (Node::is($callback, 'expression.class-const-fetch') && Node::is($callback->class, 'name') && Node::is($callback->name, 'identifier') && strtolower($callback->name->toString()) === 'class') {
            $target = 'php:'.$file->resolvedName($callback->class);
        } elseif (Node::is($callback, 'scalar.string') && preg_match('/\\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\\z/D', $callback->value) === 1) {
            $target = 'php:'.ltrim($callback->value, '\\');
        } elseif (Node::is($callback, 'expression.closure') || Node::is($callback, 'expression.arrow-function')) {
            $target = $this->owners[$callback->getStartFilePos()] ?? null;
            $kind = 'registers-channel-callback';
        }
        if ($target !== null) {
            $this->relations[] = new CatalogRelation($id, $target, $kind, max(1, $call->getStartLine()), max(1, $call->getEndLine()), metadata: ['execution_proven' => false]);
        } else {
            $this->diagnostics[] = new CatalogDiagnostic('dynamic_channel_authorization', 'Channel authorization callback is unresolved.', max(1, $call->getStartLine()), $id);
        }
    }

    private function component(FileContext $file, string $owner, Node $call): void
    {
        $class = $this->argument($call, 0, 'class');
        $alias = $this->argument($call, 1, 'alias');
        $prefix = $this->argument($call, 2, 'prefix');
        if (! Node::is($class, 'expression.class-const-fetch') || ! Node::is($class->class, 'name') || ! Node::is($class->name, 'identifier') || strtolower($class->name->toString()) !== 'class'
            || $alias !== null && ! Node::is($alias, 'scalar.string') || $prefix !== null && ! Node::is($prefix, 'scalar.string')) {
            $this->diagnostics[] = new CatalogDiagnostic('dynamic_component_registration', 'Component class, alias or prefix is unresolved.', max(1, $call->getStartLine()), $owner);

            return;
        }
        $name = $file->resolvedName($class->class);
        $tag = $alias->value ?? (str_contains($name, '\\View\\Components\\')
            ? implode(':', array_map(Str::kebab(...), explode('\\', Str::after($name, '\\View\\Components\\')))) : Str::kebab(class_basename($name)));
        $tag = ($prefix === null || $prefix->value === '' ? '' : $prefix->value.'-').$tag;
        if (preg_match('/\\A[a-zA-Z0-9_.:-]{1,256}\\z/D', $tag) !== 1) {
            $this->diagnostics[] = new CatalogDiagnostic('dynamic_component_registration', 'Component alias has an unsupported shape.', max(1, $call->getStartLine()), $owner);

            return;
        }
        $id = CatalogElement::resourceIdentity('blade-component-tag', $tag);
        $this->elements[$id] = new CatalogElement($id, $tag, 'blade-component-tag', max(1, $call->getStartLine()), max(1, $call->getEndLine()), max(0, $call->getStartFilePos()), metadata: ['logical_resource' => true]);
        $this->relations[] = new CatalogRelation($id, 'php:'.$name, 'registers-component', max(1, $call->getStartLine()), max(1, $call->getEndLine()),
            metadata: ['registration_owner' => $owner, 'registration_activation_known' => false, 'execution_proven' => false]);
    }

    private function httpOperation(FileContext $file, string $owner, Node $node, string $method): void
    {
        $receiver = Node::is($node, 'expression.method-call') ? $node->var : null;
        $baseUrl = null;
        $baseUrlSelected = false;
        $preparations = ['withtoken', 'withheaders', 'withoptions', 'accept', 'acceptjson', 'asjson', 'asform', 'withbody', 'withbasicauth', 'withdigestauth', 'withcookies', 'timeout', 'connecttimeout', 'retry', 'withoutverifying', 'baseurl', 'beforesending', 'withmiddleware', 'withrequestmiddleware', 'withresponsemiddleware'];
        while (Node::is($receiver, 'expression.method-call') || Node::is($receiver, 'expression.static-call')) {
            if (! Node::is($receiver->name, 'identifier') || $receiver->isFirstClassCallable() || ! in_array(strtolower($receiver->name->toString()), $preparations, true)) {
                $this->diagnostics[] = new CatalogDiagnostic('unsupported_resource_chain', 'HTTP receiver is no longer a known pending request.', max(1, $node->getStartLine()), $owner);

                return;
            }
            if (strtolower($receiver->name->toString()) === 'baseurl' && ! $baseUrlSelected) {
                $baseUrlSelected = true;
                $baseUrl = CatalogSelector::literal($this->argument($receiver, 0, 'url'));
            }
            $receiver = Node::is($receiver, 'expression.method-call') ? $receiver->var : null;
        }
        $url = CatalogSelector::literal($this->argument($node, 0, 'url'));
        if ($url !== null && ! preg_match('~\Ahttps?://~i', $url) && $baseUrl !== null) {
            $url = rtrim($baseUrl, '/').'/'.ltrim($url, '/');
        }
        if ($url !== null && str_contains($url, '{')) {
            $url = null; // URI templates need source substitution, not invented endpoint names.
        }
        $this->resource($file, $owner, $node, 'external-endpoint', $url === null ? null : Derived::string($node, $url), 'uses-external-service', ['http_method' => strtoupper($method), 'api_receiver' => 'Illuminate\\Support\\Facades\\Http', 'execution_proven' => false]);
    }

    /** @return list<string> */
    private function functionCandidates(FileContext $file, Node $name): array
    {
        $namespace = $name->getAttribute('namespacedName');

        return array_values(array_unique(Node::is($namespace, 'name') ? [$namespace->toString(), $file->resolvedName($name)] : [$file->resolvedName($name)]));
    }

    /** Collect cache/config/storage effects without persisting keys' values or file paths. */
    private function storeOperation(FileContext $file, string $owner, Node $node): bool
    {
        if (! Node::is($node, 'expression.call-like') || $node->isFirstClassCallable()) {
            return false;
        }
        $chain = [];
        $root = $node;
        while (Node::is($root, 'expression.method-call')) {
            array_unshift($chain, $root);
            $root = $root->var;
        }
        $receiver = null;
        $helper = null;
        if (Node::is($root, 'expression.static-call') && Node::is($root->class, 'name')) {
            $receiver = $file->resolvedName($root->class);
            $family = ['illuminate\\support\\facades\\cache' => 'cache', 'illuminate\\support\\facades\\storage' => 'storage', 'illuminate\\support\\facades\\config' => 'config'][strtolower($receiver)] ?? null;
            array_unshift($chain, $root);
        } elseif (Node::is($root, 'expression.func-call') && Node::is($root->name, 'name')) {
            $helper = strtolower($file->resolvedName($root->name));
            $family = in_array($helper, ['cache', 'config'], true) ? $helper : null;
            if ($chain === []) {
                $key = $this->argument($root, 0, 'key');
                if ($family === null || $key === null || Node::is($key, 'expression.const-fetch') && strtolower($key->name->toString()) === 'null') {
                    return $family !== null;
                }
                $metadata = ['api_helper' => $helper, 'api_functions' => $this->functionCandidates($file, $root->name), 'execution_proven' => false, ...($family === 'cache' ? ['store' => '(default)'] : [])];
                if (Node::is($key, 'expression.array')) {
                    if (count($key->items) > 128) {
                        $this->diagnostics[] = new CatalogDiagnostic('resource_limit', 'Resource array key budget reached.', max(1, $node->getStartLine()), $owner);

                        return true;
                    }
                    foreach ($key->items as $item) {
                        if ($item === null || $item->unpack || $item->key === null) {
                            $this->diagnostics[] = new CatalogDiagnostic('dynamic_resource', 'Configuration/cache array key requires inspection.', max(1, $node->getStartLine()), $owner);
                            if ($family === 'cache') {
                                break;
                            }

                            continue;
                        }
                        $this->resource($file, $owner, $root, $family.'-key', $item->key, 'writes', $metadata);
                        if ($family === 'cache') {
                            break; // cache(array) writes only the first pair.
                        }
                    }
                } else {
                    $this->resource($file, $owner, $root, $family.'-key', $key, 'reads', $metadata);
                }

                return true;
            }
            if ($root->getArgs() !== []) {
                return false; // cache('key') returns a value, not a repository.
            }
        } else {
            return false;
        }
        if ($family === null) {
            return false;
        }
        $metadata = ['execution_proven' => false, ...($receiver !== null ? ['api_receiver' => $receiver] : ['api_helper' => $helper, 'api_functions' => $this->functionCandidates($file, $root->name)])];
        $store = '(default)';
        $lock = null;
        $lockPrepared = false;
        $preparations = 0;
        foreach ($chain as $call) {
            if (! Node::is($call->name, 'identifier') || $call->isFirstClassCallable()) {
                $this->diagnostics[] = new CatalogDiagnostic('dynamic_resource', 'Dynamic resource operation requires inspection.', max(1, $node->getStartLine()), $owner);

                return true;
            }
            $method = strtolower($call->name->toString());
            if (in_array($method, $family === 'cache' ? ['store', 'driver'] : ($family === 'storage' ? ['disk', 'drive', 'cloud'] : []), true)) {
                if (++$preparations > 1) {
                    break;
                }
                $selector = $this->argument($call, 0, 'name');
                $value = $selector === null || Node::is($selector, 'expression.const-fetch') && strtolower($selector->name->toString()) === 'null'
                    ? ($method === 'cloud' ? '(cloud)' : '(default)') : CatalogSelector::literal($selector);
                if (! is_string($value) || preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/D', $value) !== 1 && ! in_array($value, ['(default)', '(cloud)'], true)) {
                    $this->diagnostics[] = new CatalogDiagnostic('dynamic_resource', 'Store/disk selector is unresolved.', max(1, $call->getStartLine()), $owner);

                    return true;
                }
                $store = $value;
                if ($call === $node) {
                    $this->resource($file, $owner, $call, $family === 'cache' ? 'cache-store' : 'storage-disk', Derived::string($node, $store), $family === 'cache' ? 'selects-store' : 'selects-disk', $metadata);

                    return true;
                }

                continue;
            }
            if ($family === 'cache' && in_array($method, ['lock', 'restorelock'], true)) {
                $lockPrepared = true;
                $lock = $this->argument($call, 0, 'name');
                if ($call === $node) {
                    $this->resource($file, $owner, $node, 'cache-lock', $lock, 'prepares-lock', [...$metadata, 'store' => $store]);

                    return true;
                }

                continue;
            }
            if ($call !== $node) {
                break; // An unknown intermediate method may change the receiver.
            }
            $metadata += ['operation' => $method, 'store' => $store, 'runtime_configuration_known' => false];
            if ($lockPrepared && in_array($method, ['get', 'block', 'release', 'forcerelease', 'owner'], true)) {
                $relation = in_array($method, ['get', 'block'], true) ? 'acquires-lock' : ($method === 'owner' ? 'reads-lock' : 'releases-lock');
                $this->resource($file, $owner, $node, 'cache-lock', $lock, $relation, $metadata);
                if (in_array($method, ['get', 'block'], true)) {
                    $this->resourceCallback($owner, $node, $this->argument($call, $method === 'block' ? 1 : 0, 'callback'), $metadata, 'Lock acquisition must succeed.');
                }

                return true;
            }
            if ($family === 'storage') {
                $reads = ['get', 'readstream', 'exists', 'missing', 'size', 'lastmodified', 'mimetype', 'files', 'allfiles', 'directories', 'alldirectories', 'url', 'temporaryurl', 'path'];
                $writes = ['put', 'putfile', 'putfileas', 'write', 'writestream', 'delete', 'deletedirectory', 'makedirectory', 'setvisibility', 'append', 'prepend', 'copy', 'move'];
                $effects = in_array($method, ['append', 'prepend', 'copy', 'move'], true) ? ['reads-storage', 'writes-storage']
                    : (in_array($method, $reads, true) ? ['reads-storage'] : (in_array($method, $writes, true) ? ['writes-storage'] : []));
                foreach ($effects as $effect) {
                    $this->resource($file, $owner, $node, 'storage-disk', Derived::string($node, $store), $effect, $metadata);
                }
                if ($effects !== []) {
                    return true;
                }
            } elseif ($family === 'config') {
                if (in_array($method, ['get', 'has', 'set', 'string', 'integer', 'float', 'boolean', 'array', 'collection'], true)) {
                    $this->resource($file, $owner, $node, 'config-key', $this->argument($call, 0, 'key'), $method === 'set' ? 'writes' : 'reads', array_diff_key($metadata, ['store' => true]));

                    return true;
                }
            } elseif (! $lockPrepared) {
                $reads = ['get', 'has', 'missing'];
                $writes = ['put', 'add', 'forever', 'forget', 'delete'];
                $both = ['pull', 'remember', 'rememberforever', 'sear', 'increment', 'decrement'];
                $effects = in_array($method, $reads, true) ? ['reads'] : (in_array($method, $writes, true) ? ['writes'] : (in_array($method, $both, true) ? ['reads', 'writes'] : []));
                foreach ($effects as $effect) {
                    $this->resource($file, $owner, $node, 'cache-key', $this->argument($call, 0, 'key'), $effect, $metadata);
                }
                if (in_array($method, ['remember', 'rememberforever', 'sear'], true)) {
                    $this->resourceCallback($owner, $node, $this->argument($call, $method === 'remember' ? 2 : 1, 'callback'), $metadata, 'Cache key must be absent.');
                }
                if ($effects !== []) {
                    return true;
                }
            }
            break;
        }
        $this->diagnostics[] = new CatalogDiagnostic('unsupported_resource_chain', 'Resource chain changes its receiver or uses an unsupported operation.', max(1, $node->getStartLine()), $owner);

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function resourceCallback(string $owner, Node $site, ?Node $callback, array $metadata, string $condition): void
    {
        if ((Node::is($callback, 'expression.closure') || Node::is($callback, 'expression.arrow-function')) && isset($this->owners[$callback->getStartFilePos()])) {
            $this->relations[] = new CatalogRelation($owner, $this->owners[$callback->getStartFilePos()], 'resource-callback', max(1, $site->getStartLine()), max(1, $site->getEndLine()), 'conditional', [...$metadata, 'conditions' => [$condition]]);
        } elseif ($callback !== null) {
            $this->diagnostics[] = new CatalogDiagnostic('dynamic_resource_callback', 'Resource callback requires source resolution.', max(1, $site->getStartLine()), $owner);
        }
    }

    private function argument(Node $call, int $position, string $name): ?Node
    {
        foreach ($call->getArgs() as $index => $argument) {
            if ($argument->name?->toString() === $name || $argument->name === null && $index === $position && ! $argument->unpack) {
                return $argument->value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $metadata */
    private function resource(FileContext $file, string $owner, Node $site, string $kind, ?Node $value, string $relation, array $metadata = []): ?string
    {
        $literal = CatalogSelector::literal($value);
        if (! is_string($literal) || $literal === '' || strlen($literal) > 500) {
            $this->diagnostics[] = new CatalogDiagnostic('dynamic_resource', 'The '.$kind.' identifier is not a supported literal.', max(1, $site->getStartLine()), $owner);

            return null;
        }
        $name = $literal;
        if ($kind === 'external-endpoint') {
            $url = parse_url($name);
            if (! is_array($url) || ! isset($url['scheme'], $url['host']) || ! in_array(strtolower($url['scheme']), ['http', 'https'], true)) {
                $this->diagnostics[] = new CatalogDiagnostic('dynamic_endpoint', 'External endpoint must have a literal HTTP origin.', max(1, $site->getStartLine()), $owner);

                return null;
            }
            $name = strtolower($url['scheme']).'://'.strtolower($url['host']).(isset($url['port']) ? ':'.$url['port'] : '').($url['path'] ?? '/');
            $origin = strtolower($url['scheme']).'://'.strtolower($url['host']).(isset($url['port']) ? ':'.$url['port'] : '');
            $service = CatalogElement::resourceIdentity('external-service', $origin);
            $this->elements[$service] = new CatalogElement($service, $origin, 'external-service', max(1, $site->getStartLine()), max(1, $site->getEndLine()), max(0, $site->getStartFilePos()), metadata: ['logical_resource' => true]);
        }
        $id = CatalogElement::resourceIdentity($kind, isset($metadata['store']) && in_array($kind, ['cache-key', 'cache-lock'], true) ? $metadata['store'].'::'.$name : $name);
        $this->elements[$id] = new CatalogElement($id, $name, $kind, max(1, $site->getStartLine()), max(1, $site->getEndLine()), max(0, $site->getStartFilePos()), metadata: ['logical_resource' => true, ...(isset($metadata['store']) ? ['store' => $metadata['store']] : [])]);
        $this->relations[] = new CatalogRelation($owner, $id, $relation, max(1, $site->getStartLine()), max(1, $site->getEndLine()), metadata: $metadata);
        if (isset($metadata['store']) && in_array($kind, ['cache-key', 'cache-lock'], true)) {
            $storeId = CatalogElement::resourceIdentity('cache-store', $metadata['store']);
            $this->elements[$storeId] = new CatalogElement($storeId, $metadata['store'], 'cache-store', max(1, $site->getStartLine()), max(1, $site->getEndLine()), max(0, $site->getStartFilePos()), metadata: ['logical_resource' => true, 'runtime_configuration_known' => false]);
            $this->relations[] = new CatalogRelation($id, $storeId, 'references-cache-store', max(1, $site->getStartLine()), max(1, $site->getEndLine()), metadata: ['execution_proven' => false]);
        }

        if (isset($service)) {
            $this->relations[] = new CatalogRelation($id, $service, 'endpoint-of', max(1, $site->getStartLine()), max(1, $site->getEndLine()));
        }

        return $id;
    }
}
