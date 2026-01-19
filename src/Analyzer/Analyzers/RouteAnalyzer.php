<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * RouteAnalyzer - Analyzes Laravel routes for test coverage and controller mapping
 * 
 * Detects:
 * - Route definitions (get, post, put, patch, delete, resource, apiResource)
 * - Route parameters ({id}, {slug})
 * - Route model binding
 * - Controller mappings
 * - Route middleware
 * - Route names
 * - Route groups
 * - Resource routes
 */
class RouteAnalyzer
{
    private string $projectPath;
    private array $routes = [];
    private array $routeGroups = [];
    private array $controllerMethodMap = [];

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, '/');
    }

    /**
     * Analyze all route files
     */
    public function analyze(): array
    {
        $this->routes = [];
        $this->routeGroups = [];
        $this->controllerMethodMap = [];

        // Parse route files - scan routes directory for all PHP files
        $routesDir = $this->projectPath . '/routes';
        $routeFiles = [];

        if (is_dir($routesDir)) {
            // Scan all PHP files in routes directory
            $files = glob($routesDir . '/*.php');
            if ($files) {
                sort($files); // Sort for consistent ordering
                foreach ($files as $file) {
                    $basename = basename($file, '.php');
                    // Determine type from filename
                    if ($basename === 'web') {
                        $type = 'web';
                    } elseif ($basename === 'api') {
                        $type = 'api';
                    } elseif (str_contains($basename, 'api')) {
                        $type = 'api';  // Files like mock-api.php, admin-api.php
                    } else {
                        $type = $basename;
                    }
                    // Use basename as key to avoid overwriting
                    $routeFiles[$basename] = ['file' => $file, 'type' => $type];
                }
            }
        }

        // Fallback to known files if directory scan didn't work
        if (empty($routeFiles)) {
            $routeFiles = [
                'web' => ['file' => $this->projectPath . '/routes/web.php', 'type' => 'web'],
                'api' => ['file' => $this->projectPath . '/routes/api.php', 'type' => 'api'],
                'channels' => ['file' => $this->projectPath . '/routes/channels.php', 'type' => 'channels'],
            ];
        }

        foreach ($routeFiles as $basename => $info) {
            $file = $info['file'];
            $type = $info['type'];
            if (file_exists($file)) {
                $this->parseRouteFile($file, $type);
            }
        }

        return [
            'routes' => $this->routes,
            'route_groups' => $this->routeGroups,
            'controller_method_map' => $this->controllerMethodMap,
            'statistics' => $this->generateStatistics(),
        ];
    }

    /**
     * Parse a single route file
     */
    private function parseRouteFile(string $file, string $type): void
    {
        $content = file_get_contents($file);
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new RouteVisitor($type);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            // Merge results
            $this->routes = array_merge($this->routes, $visitor->routes);
            $this->routeGroups = array_merge($this->routeGroups, $visitor->routeGroups);

            // Build controller method map
            foreach ($visitor->routes as $route) {
                if (isset($route['controller']) && isset($route['method'])) {
                    $key = $route['controller'] . '@' . $route['method'];
                    if (!isset($this->controllerMethodMap[$key])) {
                        $this->controllerMethodMap[$key] = [];
                    }
                    $this->controllerMethodMap[$key][] = $route;
                }
            }

        } catch (\Exception $e) {
            // Skip files with parse errors
        }
    }

    /**
     * Generate statistics
     */
    private function generateStatistics(): array
    {
        $httpMethods = [];
        $withMiddleware = 0;
        $withParameters = 0;
        $resourceRoutes = 0;
        $apiRoutes = 0;
        $webRoutes = 0;

        foreach ($this->routes as $route) {
            // Count HTTP methods
            foreach ($route['http_methods'] as $method) {
                $httpMethods[$method] = ($httpMethods[$method] ?? 0) + 1;
            }

            // Count middleware
            if (!empty($route['middleware'])) {
                $withMiddleware++;
            }

            // Count parameters
            if (!empty($route['parameters'])) {
                $withParameters++;
            }

            // Count resource routes
            if ($route['is_resource'] ?? false) {
                $resourceRoutes++;
            }

            // Count by type
            if ($route['file_type'] === 'api') {
                $apiRoutes++;
            } elseif ($route['file_type'] === 'web') {
                $webRoutes++;
            }
        }

        return [
            'total_routes' => count($this->routes),
            'total_route_groups' => count($this->routeGroups),
            'unique_controllers' => count(array_unique(array_column($this->routes, 'controller'))),
            'http_methods' => $httpMethods,
            'with_middleware' => $withMiddleware,
            'with_parameters' => $withParameters,
            'resource_routes' => $resourceRoutes,
            'api_routes' => $apiRoutes,
            'web_routes' => $webRoutes,
        ];
    }

    /**
     * Get routes for a specific controller
     */
    public function getRoutesForController(string $controller): array
    {
        return array_filter($this->routes, fn($route) => 
            ($route['controller'] ?? '') === $controller
        );
    }

    /**
     * Get routes for a specific controller method
     */
    public function getRoutesForControllerMethod(string $controller, string $method): array
    {
        $key = $controller . '@' . $method;
        return $this->controllerMethodMap[$key] ?? [];
    }

    /**
     * Get all routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

/**
 * AST Visitor for route detection
 */
class RouteVisitor extends NodeVisitorAbstract
{
    public array $routes = [];
    public array $routeGroups = [];
    private string $fileType;
    private array $groupStack = [];

    public function __construct(string $fileType)
    {
        $this->fileType = $fileType;
    }

    public function enterNode(Node $node)
    {
        // Route::get(), Route::post(), etc.
        if ($node instanceof StaticCall && $this->isRouteCall($node)) {
            $this->handleRouteDefinition($node);
        }

        // Route::resource() / Route::apiResource()
        if ($node instanceof StaticCall && $this->isResourceRoute($node)) {
            $this->handleResourceRoute($node);
        }

        // Route::group()
        if ($node instanceof StaticCall && $this->isGroupRoute($node)) {
            $this->handleRouteGroup($node);
        }

        // Method chaining: ->middleware(), ->name()
        if ($node instanceof MethodCall) {
            $this->handleMethodChain($node);
        }

        return null;
    }

    private function isRouteCall(Node $node): bool
    {
        if (!$node instanceof StaticCall) {
            return false;
        }

        $class = $node->class instanceof Node\Name ? $node->class->toString() : '';
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

        $routeMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

        return $class === 'Route' && in_array($method, $routeMethods);
    }

    private function isResourceRoute(Node $node): bool
    {
        if (!$node instanceof StaticCall) {
            return false;
        }

        $class = $node->class instanceof Node\Name ? $node->class->toString() : '';
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

        return $class === 'Route' && in_array($method, ['resource', 'apiResource']);
    }

    private function isGroupRoute(Node $node): bool
    {
        if (!$node instanceof StaticCall) {
            return false;
        }

        $class = $node->class instanceof Node\Name ? $node->class->toString() : '';
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

        return $class === 'Route' && $method === 'group';
    }

    private function handleRouteDefinition(StaticCall $node): void
    {
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        $args = $node->args;

        if (count($args) < 2) {
            return;
        }

        // First arg: URI
        $uri = $this->extractString($args[0]->value);
        
        // Second arg: Controller action or Closure
        $action = $this->extractAction($args[1]->value);

        if (!$uri) {
            return;
        }

        $route = [
            'uri' => $uri,
            'http_methods' => $this->mapHttpMethod($method),
            'controller' => $action['controller'] ?? null,
            'method' => $action['method'] ?? null,
            'is_closure' => $action['is_closure'] ?? false,
            'parameters' => $this->extractParameters($uri),
            'middleware' => $this->getCurrentGroupMiddleware(),
            'prefix' => $this->getCurrentGroupPrefix(),
            'name' => null,
            'is_resource' => false,
            'file_type' => $this->fileType,
            'line' => $node->getStartLine(),
        ];

        $this->routes[] = $route;
    }

    private function handleResourceRoute(StaticCall $node): void
    {
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        $args = $node->args;

        if (count($args) < 2) {
            return;
        }

        $uri = $this->extractString($args[0]->value);
        $controller = $this->extractController($args[1]->value);

        if (!$uri || !$controller) {
            return;
        }

        // Generate resource routes
        $resourceMethods = [
            'index' => ['GET'],
            'create' => ['GET'],
            'store' => ['POST'],
            'show' => ['GET'],
            'edit' => ['GET'],
            'update' => ['PUT', 'PATCH'],
            'destroy' => ['DELETE'],
        ];

        // API resources don't have create/edit
        if ($method === 'apiResource') {
            unset($resourceMethods['create']);
            unset($resourceMethods['edit']);
        }

        foreach ($resourceMethods as $methodName => $httpMethods) {
            $route = [
                'uri' => $this->buildResourceUri($uri, $methodName),
                'http_methods' => $httpMethods,
                'controller' => $controller,
                'method' => $methodName,
                'is_closure' => false,
                'parameters' => $this->extractParameters($this->buildResourceUri($uri, $methodName)),
                'middleware' => $this->getCurrentGroupMiddleware(),
                'prefix' => $this->getCurrentGroupPrefix(),
                'name' => null,
                'is_resource' => true,
                'file_type' => $this->fileType,
                'line' => $node->getStartLine(),
            ];

            $this->routes[] = $route;
        }
    }

    private function handleRouteGroup(StaticCall $node): void
    {
        $args = $node->args;
        
        if (empty($args)) {
            return;
        }

        // Extract group attributes (middleware, prefix, etc.)
        $attributes = [];
        if (count($args) >= 2 && $args[0]->value instanceof Array_) {
            $attributes = $this->extractArray($args[0]->value);
        }

        $this->routeGroups[] = [
            'attributes' => $attributes,
            'line' => $node->getStartLine(),
        ];

        // Push to stack for nested groups
        $this->groupStack[] = $attributes;
    }

    private function handleMethodChain(MethodCall $node): void
    {
        // Handle ->middleware(), ->name(), etc.
        // This would require more complex tracking of the route being defined
        // For now, we'll keep it simple and rely on group middleware
    }

    private function extractString($node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }
        return null;
    }

    private function extractAction($node): array
    {
        // Array: [Controller::class, 'method']
        if ($node instanceof Array_) {
            $items = $node->items;
            if (count($items) >= 2) {
                $controller = null;
                $method = null;

                if ($items[0]->value instanceof ClassConstFetch) {
                    $class = $items[0]->value->class;
                    if ($class instanceof Node\Name) {
                        $controller = $class->toString();
                    }
                }

                if ($items[1]->value instanceof String_) {
                    $method = $items[1]->value->value;
                }

                return [
                    'controller' => $controller,
                    'method' => $method,
                    'is_closure' => false,
                ];
            }
        }

        // Closure
        if ($node instanceof Closure) {
            return ['is_closure' => true];
        }

        // String: 'Controller@method'
        if ($node instanceof String_) {
            $parts = explode('@', $node->value);
            if (count($parts) === 2) {
                return [
                    'controller' => $parts[0],
                    'method' => $parts[1],
                    'is_closure' => false,
                ];
            }
        }

        return [];
    }

    private function extractController($node): ?string
    {
        if ($node instanceof ClassConstFetch) {
            $class = $node->class;
            if ($class instanceof Node\Name) {
                return $class->toString();
            }
        }

        if ($node instanceof String_) {
            return $node->value;
        }

        return null;
    }

    private function extractArray(Array_ $node): array
    {
        $result = [];
        
        foreach ($node->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            $key = null;
            if ($item->key instanceof String_) {
                $key = $item->key->value;
            }

            $value = null;
            if ($item->value instanceof String_) {
                $value = $item->value->value;
            } elseif ($item->value instanceof Array_) {
                $value = $this->extractSimpleArray($item->value);
            }

            if ($key && $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function extractSimpleArray(Array_ $node): array
    {
        $result = [];
        
        foreach ($node->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            if ($item->value instanceof String_) {
                $result[] = $item->value->value;
            }
        }

        return $result;
    }

    private function extractParameters(string $uri): array
    {
        preg_match_all('/\{([^}]+)\}/', $uri, $matches);
        
        $params = [];
        foreach ($matches[1] as $param) {
            $params[] = [
                'name' => $param,
                'optional' => str_ends_with($param, '?'),
            ];
        }

        return $params;
    }

    private function mapHttpMethod(string $method): array
    {
        return match($method) {
            'get' => ['GET'],
            'post' => ['POST'],
            'put' => ['PUT'],
            'patch' => ['PATCH'],
            'delete' => ['DELETE'],
            'options' => ['OPTIONS'],
            'any' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            default => ['GET'],
        };
    }

    private function buildResourceUri(string $base, string $method): string
    {
        return match($method) {
            'index' => $base,
            'create' => "{$base}/create",
            'store' => $base,
            'show' => "{$base}/{{$base}}",
            'edit' => "{$base}/{{$base}}/edit",
            'update' => "{$base}/{{$base}}",
            'destroy' => "{$base}/{{$base}}",
            default => $base,
        };
    }

    private function getCurrentGroupMiddleware(): array
    {
        $middleware = [];
        
        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                if (is_array($group['middleware'])) {
                    $middleware = array_merge($middleware, $group['middleware']);
                } else {
                    $middleware[] = $group['middleware'];
                }
            }
        }

        return array_unique($middleware);
    }

    private function getCurrentGroupPrefix(): ?string
    {
        $prefix = '';
        
        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
        }

        return $prefix ?: null;
    }
}
