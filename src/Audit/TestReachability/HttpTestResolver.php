<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final readonly class HttpTestResolver
{
    public function __construct(private RouteMap $routes) {}

    /** @return RouteEntry|string Handler or reason why dispatch cannot be established. */
    public function resolve(TestInvocation $call): RouteEntry|string
    {
        if ($this->routes->unavailable !== null || $this->routes->entries === null) {
            return $this->routes->unavailable ?? 'Route map contains verbs only; HTTP templates are unavailable.';
        }
        if ($call->reason !== null || $call->verb === null) {
            return $call->reason ?? 'Unknown HTTP method.';
        }
        $uri = $call->uri;
        $domain = null;
        if ($call->route !== null) {
            $named = array_values(array_filter($this->routes->entries, fn (RouteEntry $entry): bool => $entry->name === $call->route));
            if (count($named) !== 1) {
                return 'Named route is missing or ambiguous: '.$call->route;
            }
            $position = 0;
            $missing = false;
            $substitute = function (array $match) use ($call, &$position, &$missing): string {
                $optional = str_ends_with($match[1], '?');
                $name = rtrim($match[1], '?');
                $value = $call->parameters[$name] ?? $call->parameters[$position++] ?? null;
                if ($value === null && ! $optional) {
                    $missing = true;
                }

                return $value ?? '';
            };
            $domain = $named[0]->domain !== null ? preg_replace_callback('/\{([^}]+)\}/', $substitute, $named[0]->domain) : null;
            $uri = preg_replace_callback('/\{([^}]+)\}/', $substitute, $named[0]->uri);
            if ($missing) {
                return 'Named route parameters cannot be resolved.';
            }
        }
        if ($uri === null) {
            return 'Dynamic HTTP address.';
        }
        // Do not give parse_url a NUL marker: PHP replaces control bytes in a URL.
        $parts = explode('?', explode('#', $uri, 2)[0], 2);
        $address = $parts[0];
        if (preg_match('#^https?://([^/]+)(/.*)?$#i', $address, $match)) {
            $domain = explode(':', $match[1], 2)[0];
            $address = $match[2] ?? '/';
        }
        $path = rtrim('/'.ltrim($address, '/'), '/') ?: '/';
        $symbolic = str_contains($path, TestInvocationExtractor::SYMBOLIC);
        $candidates = [];
        foreach ($this->routes->entries as $entry) {
            if (! in_array($call->verb, $entry->verbs, true)) {
                continue;
            }
            // Use Laravel's compiled path regex for literal requests, without dispatch.
            $route = new Route($entry->verbs, $entry->uri, fn () => null);
            $route->where($entry->constraints);
            $route->bind(Request::create('/'));
            $matches = $symbolic ? $this->symbolicMatch($path, $entry) : preg_match($route->getCompiled()->getRegex(), rawurldecode($path)) === 1;
            if (! $matches) {
                continue;
            }
            if ($entry->domain !== null) {
                if ($domain === null || str_contains($domain, TestInvocationExtractor::SYMBOLIC)) {
                    return 'Host-dependent route cannot be resolved without a concrete host.';
                }
                $hostRoute = new Route($entry->verbs, $entry->uri, fn () => null);
                $hostRoute->domain($entry->domain)->where($entry->constraints);
                $hostRoute->bind(Request::create('/'));
                if (preg_match($hostRoute->getCompiled()->getHostRegex(), $domain) !== 1) {
                    continue;
                }
            }
            if ($symbolic && $entry->constraints !== []) {
                return 'Symbolic route parameter has constraints that cannot be established statically.';
            }
            $candidates[] = $entry;
            if (! $symbolic) {
                break;
            }
        }
        if (count($candidates) !== 1) {
            return $candidates === [] ? 'No route matches the HTTP method and address.' : 'Symbolic address matches multiple routes.';
        }
        $entry = $candidates[0];

        return $entry->class !== null && $entry->method !== null ? $entry : ($entry->unresolved ?? 'Unresolved route handler.');
    }

    private function symbolicMatch(string $path, RouteEntry $entry): bool
    {
        $actual = explode('/', trim($path, '/'));
        $template = explode('/', trim($entry->uri, '/'));
        if (count($actual) > count($template)) {
            return false;
        }
        foreach ($template as $i => $segment) {
            if (! isset($actual[$i])) {
                if (! str_ends_with($segment, '?}')) {
                    return false;
                }

                continue;
            }
            if (str_contains($actual[$i], TestInvocationExtractor::SYMBOLIC)) {
                // A concrete sibling route is also a possible match for an unknown ID.
                continue;
            }
            if (! preg_match('/^\{[^}]+\}$/', $segment) && $actual[$i] !== $segment) {
                return false;
            }
        }

        return true;
    }
}
