<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use Bberkaysari\LaravelTestGenerator\Scanner\Parser\PhpParser;
use Symfony\Component\Finder\Finder;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Scans Laravel controllers and extracts route information
 */
class ControllerScanner implements ScannerInterface
{
    private string $projectPath;
    private PhpParser $parser;
    private array $controllers = [];
    
    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
        $this->parser = new PhpParser();
    }
    
    public function scan(): array
    {
        $controllerPath = $this->findControllerPath();
        
        if (!is_dir($controllerPath)) {
            return [];
        }
        
        $finder = new Finder();
        $finder->files()->in($controllerPath)->name('*Controller.php');
        
        foreach ($finder as $file) {
            $content = $file->getContents();
            
            if ($this->isController($content)) {
                $this->controllers[] = $this->parseController($file->getRealPath(), $content);
            }
        }
        
        return $this->controllers;
    }
    
    /**
     * Find controller directory
     */
    private function findControllerPath(): string
    {
        $path = $this->projectPath . '/app/Http/Controllers';
        
        if (is_dir($path)) {
            return $path;
        }
        
        return $this->projectPath . '/app/Controllers';
    }
    
    /**
     * Check if file contains a controller class
     */
    private function isController(string $content): bool
    {
        return str_contains($content, 'extends Controller') 
            || str_contains($content, 'class ') && str_contains($content, 'Controller');
    }
    
    /**
     * Parse controller file
     */
    private function parseController(string $path, string $content): array
    {
        $ast = $this->parser->parse($content);
        $controllerScanner = $this; // Closure scope for anonymous class
        
        $visitor = new class($controllerScanner) extends NodeVisitorAbstract {
            private $scanner;
            
            public array $data = [
                'name' => '',
                'namespace' => '',
                'extends' => '',
                'methods' => [],
                'middleware' => [],
                'resource_type' => null,
            ];
            
            public function __construct($scanner)
            {
                $this->scanner = $scanner;
            }
            
            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->data['namespace'] = $node->name?->toString() ?? '';
                }
                
                if ($node instanceof Node\Stmt\Class_) {
                    $this->data['name'] = $node->name?->toString() ?? '';
                    
                    if ($node->extends) {
                        $this->data['extends'] = $node->extends->toString();
                    }
                    
                    // Parse methods
                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\ClassMethod) {
                            $method = $this->parseMethod($stmt);
                            $this->data['methods'][] = $method;
                            
                            // Detect resource controller pattern
                            if (in_array($method['name'], ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])) {
                                $this->data['resource_type'] = 'resource';
                            }
                        }
                    }
                }
                
                return null;
            }
            
            private function parseMethod(Node\Stmt\ClassMethod $method): array
            {
                $methodData = [
                    'name' => $method->name->toString(),
                    'visibility' => $this->getVisibility($method),
                    'parameters' => [],
                    'return_type' => $method->returnType ? $this->getType($method->returnType) : null,
                    'is_static' => $method->isStatic(),
                    'http_method' => $this->guessHttpMethod($method->name->toString()),
                    'route_params' => [],
                    'has_validation' => false,
                    'has_implementation' => $this->hasImplementation($method),
                ];
                
                // Parse parameters
                foreach ($method->params as $param) {
                    $paramData = [
                        'name' => $param->var->name ?? '',
                        'type' => $param->type ? $this->getType($param->type) : null,
                        'default' => $param->default !== null,
                    ];
                    
                    $methodData['parameters'][] = $paramData;
                    
                    // Detect route parameters
                    if ($paramData['type'] && !in_array($paramData['type'], ['Request', 'int', 'string'])) {
                        $methodData['route_params'][] = $paramData['name'];
                    }
                }
                
                // Detect validation
                if ($method->stmts) {
                    $methodData['has_validation'] = $this->scanner->detectValidation($method->stmts);
                }
                
                // Fallback: simple string search
                if (!$methodData['has_validation'] && $method->stmts) {
                    $methodCode = $method->getAttribute('comments') ?? [];
                    // Check method body by serializing
                    $methodData['has_validation'] = $this->scanner->hasValidateInCode($method);
                }
                
                return $methodData;
            }
            
            private function getVisibility(Node\Stmt\ClassMethod $method): string
            {
                if ($method->isPublic()) return 'public';
                if ($method->isProtected()) return 'protected';
                if ($method->isPrivate()) return 'private';
                return 'public';
            }
            
            private function hasImplementation(Node\Stmt\ClassMethod $method): bool
            {
                // Empty method has no statements or only returns null/empty
                if (empty($method->stmts)) {
                    return false;
                }
                
                // Single comment-only methods
                if (count($method->stmts) === 0) {
                    return false;
                }
                
                // Check if only return; or return null;
                if (count($method->stmts) === 1) {
                    $stmt = $method->stmts[0];
                    if ($stmt instanceof Node\Stmt\Return_) {
                        if ($stmt->expr === null) {
                            return false; // just "return;"
                        }
                    }
                }
                
                return true;
            }
            
            private function getType(Node $type): string
            {
                if ($type instanceof Node\Identifier) {
                    return $type->name;
                }
                
                if ($type instanceof Node\Name) {
                    return $type->toString();
                }
                
                if ($type instanceof Node\NullableType) {
                    return '?' . $this->getType($type->type);
                }
                
                return 'mixed';
            }
            
            private function guessHttpMethod(string $methodName): string
            {
                $map = [
                    'index' => 'GET',
                    'create' => 'GET',
                    'store' => 'POST',
                    'show' => 'GET',
                    'edit' => 'GET',
                    'update' => 'PUT',
                    'destroy' => 'DELETE',
                ];
                
                if (isset($map[$methodName])) {
                    return $map[$methodName];
                }
                
                // Guess from method name
                if (str_starts_with($methodName, 'get') || str_starts_with($methodName, 'show')) {
                    return 'GET';
                }
                
                if (str_starts_with($methodName, 'create') || str_starts_with($methodName, 'store')) {
                    return 'POST';
                }
                
                if (str_starts_with($methodName, 'update')) {
                    return 'PUT';
                }
                
                if (str_starts_with($methodName, 'delete') || str_starts_with($methodName, 'destroy')) {
                    return 'DELETE';
                }
                
                return 'GET';
            }
        };
        
        $this->parser->traverse($ast, $visitor);
        
        // Detect resource pattern
        $isResource = $this->isResourceController($visitor->data['methods']);
        
        return [
            'path' => $path,
            'name' => $visitor->data['name'],
            'namespace' => $visitor->data['namespace'],
            'extends' => $visitor->data['extends'],
            'methods' => $visitor->data['methods'],
            'middleware' => $visitor->data['middleware'],
            'resource_type' => $visitor->data['resource_type'],
            'is_api' => str_contains($visitor->data['namespace'], '\\Api\\'),
            'is_resource' => $isResource,
        ];
    }
    
    /**
     * Check if controller has resource pattern (index, show, store, update, destroy)
     */
    private function isResourceController(array $methods): bool
    {
        $resourceMethods = ['index', 'show', 'store', 'update', 'destroy'];
        $methodNames = array_column($methods, 'name');
        
        $matchCount = count(array_intersect($resourceMethods, $methodNames));
        
        // If it has at least 3 resource methods, consider it a resource controller
        return $matchCount >= 3;
    }
    
    /**
     * Detect validation in method statements
     */
    public function detectValidation(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            // Check for $request->validate() or $validated = $request->validate()
            if ($stmt instanceof Node\Expr\Assign && $stmt->expr instanceof Node\Expr\MethodCall) {
                if ($this->isValidateCall($stmt->expr)) {
                    return true;
                }
            }
            
            // Check for standalone validate() calls
            if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\MethodCall) {
                if ($this->isValidateCall($stmt->expr)) {
                    return true;
                }
            }
            
            // Recursive check for nested statements (if, try, etc.)
            if (isset($stmt->stmts) && is_array($stmt->stmts)) {
                if ($this->detectValidation($stmt->stmts)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Fallback: Check if code contains ->validate( (simple string search)
     */
    public function hasValidateInCode(Node\Stmt\ClassMethod $method): bool
    {
        // Use prettyPrint to convert AST back to code
        $printer = new \PhpParser\PrettyPrinter\Standard();
        $code = $printer->prettyPrint($method->stmts ?? []);
        
        return str_contains($code, '->validate(');
    }
    
    /**
     * Check if a method call is validate()
     */
    private function isValidateCall(Node\Expr\MethodCall $call): bool
    {
        return $call->name instanceof Node\Identifier && 
               $call->name->toString() === 'validate';
    }
}
