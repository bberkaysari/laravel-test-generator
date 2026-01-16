<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * MiddlewareScanner - Detects and analyzes middleware in Laravel applications
 * 
 * Capabilities:
 * - Identifies middleware classes
 * - Detects route middleware assignments
 * - Finds controller middleware
 * - Maps middleware groups
 * - Analyzes middleware priority/order
 * - Detects global middleware
 */
class MiddlewareScanner implements ScannerInterface
{
    private array $middleware = [];
    private array $routeMiddleware = [];
    private array $middlewareGroups = [];
    private array $globalMiddleware = [];
    private string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, '/');
    }

    public function scan(): array
    {
        $this->middleware = [];
        $this->routeMiddleware = [];
        $this->middlewareGroups = [];
        $this->globalMiddleware = [];

        // Scan middleware classes
        $this->scanMiddlewareDirectory();

        // Scan Kernel for middleware configuration
        $this->scanKernel();

        // Scan controllers for middleware usage
        $this->scanControllerMiddleware();

        // Scan routes for middleware (if routes files exist)
        $this->scanRoutes();

        return [
            'middleware' => $this->middleware,
            'route_middleware' => $this->routeMiddleware,
            'middleware_groups' => $this->middlewareGroups,
            'global_middleware' => $this->globalMiddleware,
            'statistics' => [
                'total_middleware' => count($this->middleware),
                'total_route_middleware' => count($this->routeMiddleware),
                'total_groups' => count($this->middlewareGroups),
                'total_global' => count($this->globalMiddleware),
            ],
        ];
    }

    private function scanMiddlewareDirectory(): void
    {
        $paths = [
            $this->projectPath . '/app/Http/Middleware',
            $this->projectPath . '/src/Http/Middleware',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $this->scanMiddlewareFile($file);
            }
        }
    }

    private function scanMiddlewareFile(SplFileInfo $file): void
    {
        $content = $file->getContents();
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new MiddlewareVisitor($file->getRelativePathname());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->classes as $classData) {
                $this->middleware[] = $classData;
            }

        } catch (\Exception $e) {
            // Skip files with parse errors
        }
    }

    private function scanKernel(): void
    {
        $kernelPaths = [
            $this->projectPath . '/app/Http/Kernel.php',
            $this->projectPath . '/bootstrap/app.php', // Laravel 11+
        ];

        foreach ($kernelPaths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $this->scanKernelFile($path);
        }
    }

    private function scanKernelFile(string $path): void
    {
        $content = file_get_contents($path);
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new KernelVisitor();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $this->routeMiddleware = $visitor->routeMiddleware;
            $this->middlewareGroups = $visitor->middlewareGroups;
            $this->globalMiddleware = $visitor->globalMiddleware;

        } catch (\Exception $e) {
            // Skip on parse error
        }
    }

    private function scanControllerMiddleware(): void
    {
        $paths = [
            $this->projectPath . '/app/Http/Controllers',
            $this->projectPath . '/src/Http/Controllers',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $this->scanControllerFile($file);
            }
        }
    }

    private function scanControllerFile(SplFileInfo $file): void
    {
        $content = $file->getContents();
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new ControllerMiddlewareVisitor($file->getRelativePathname());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            // Update middleware with controller usage info
            foreach ($visitor->middlewareUsage as $usage) {
                // Find or create route middleware entry
                $found = false;
                foreach ($this->routeMiddleware as &$rm) {
                    if ($rm['name'] === $usage['middleware']) {
                        $rm['used_in_controllers'][] = $usage['controller'];
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $this->routeMiddleware[] = [
                        'name' => $usage['middleware'],
                        'class' => null,
                        'used_in_controllers' => [$usage['controller']],
                    ];
                }
            }

        } catch (\Exception $e) {
            // Skip on error
        }
    }

    private function scanRoutes(): void
    {
        $routePaths = [
            $this->projectPath . '/routes/web.php',
            $this->projectPath . '/routes/api.php',
        ];

        foreach ($routePaths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $this->scanRouteFile($path);
        }
    }

    private function scanRouteFile(string $path): void
    {
        $content = file_get_contents($path);
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new RouteMiddlewareVisitor(basename($path));
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            // Collect route middleware usage
            foreach ($visitor->middlewareUsage as $usage) {
                foreach ($this->routeMiddleware as &$rm) {
                    if ($rm['name'] === $usage['middleware']) {
                        $rm['used_in_routes'][] = $usage['file'];
                    }
                }
            }

        } catch (\Exception $e) {
            // Skip on error
        }
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}

/**
 * Visitor for middleware classes
 */
class MiddlewareVisitor extends NodeVisitorAbstract
{
    public array $classes = [];
    private string $currentNamespace = '';
    private string $filename;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : '';
        }

        if ($node instanceof Class_) {
            $className = $node->name ? $node->name->toString() : 'Anonymous';
            $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $className : $className;

            $handleMethod = $node->getMethod('handle');
            
            $this->classes[] = [
                'name' => $className,
                'fqn' => $fqn,
                'namespace' => $this->currentNamespace,
                'filename' => $this->filename,
                'has_handle_method' => $handleMethod !== null,
                'handle_parameters' => $handleMethod ? $this->extractParameters($handleMethod) : [],
            ];
        }

        return null;
    }

    private function extractParameters($method): array
    {
        $params = [];
        foreach ($method->getParams() as $param) {
            $params[] = [
                'name' => $param->var instanceof Node\Expr\Variable ? $param->var->name : 'unknown',
                'type' => $param->type ? $this->getTypeString($param->type) : null,
            ];
        }
        return $params;
    }

    private function getTypeString($type): string
    {
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        return 'mixed';
    }
}

/**
 * Visitor for Kernel middleware configuration
 */
class KernelVisitor extends NodeVisitorAbstract
{
    public array $routeMiddleware = [];
    public array $middlewareGroups = [];
    public array $globalMiddleware = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Property) {
            $name = $node->props[0]->name->toString();

            if ($name === 'routeMiddleware' || $name === 'middlewareAliases') {
                $this->routeMiddleware = $this->extractArray($node->props[0]->default);
            }

            if ($name === 'middlewareGroups') {
                $this->middlewareGroups = $this->extractArray($node->props[0]->default);
            }

            if ($name === 'middleware') {
                $this->globalMiddleware = $this->extractSimpleArray($node->props[0]->default);
            }
        }

        return null;
    }

    private function extractArray($default): array
    {
        $result = [];
        
        if ($default instanceof Array_) {
            foreach ($default->items as $item) {
                if ($item && $item->key instanceof String_ && $item->value instanceof String_) {
                    $result[] = [
                        'name' => $item->key->value,
                        'class' => $item->value->value,
                    ];
                }
            }
        }

        return $result;
    }

    private function extractSimpleArray($default): array
    {
        $result = [];
        
        if ($default instanceof Array_) {
            foreach ($default->items as $item) {
                if ($item && $item->value instanceof String_) {
                    $result[] = $item->value->value;
                } elseif ($item && $item->value instanceof Node\Expr\ClassConstFetch) {
                    $class = $item->value->class;
                    if ($class instanceof Node\Name) {
                        $result[] = $class->toString();
                    }
                }
            }
        }

        return $result;
    }
}

/**
 * Visitor for controller middleware usage
 */
class ControllerMiddlewareVisitor extends NodeVisitorAbstract
{
    public array $middlewareUsage = [];
    private string $filename;
    private string $currentClass = '';

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_) {
            $this->currentClass = $node->name ? $node->name->toString() : 'Anonymous';
        }

        // Detect $this->middleware() calls
        if ($node instanceof Node\Expr\MethodCall) {
            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            
            if ($method === 'middleware' && !empty($node->args)) {
                $arg = $node->args[0]->value;
                if ($arg instanceof String_) {
                    $this->middlewareUsage[] = [
                        'controller' => $this->currentClass,
                        'middleware' => $arg->value,
                        'file' => $this->filename,
                    ];
                }
            }
        }

        return null;
    }
}

/**
 * Visitor for route middleware usage
 */
class RouteMiddlewareVisitor extends NodeVisitorAbstract
{
    public array $middlewareUsage = [];
    private string $filename;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    public function enterNode(Node $node)
    {
        // Detect Route::middleware() or ->middleware()
        if ($node instanceof Node\Expr\MethodCall) {
            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            
            if ($method === 'middleware' && !empty($node->args)) {
                $arg = $node->args[0]->value;
                if ($arg instanceof String_) {
                    $this->middlewareUsage[] = [
                        'middleware' => $arg->value,
                        'file' => $this->filename,
                    ];
                }
            }
        }

        return null;
    }
}
