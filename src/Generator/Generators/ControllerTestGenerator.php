<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Generator\Generators;

use Bberkaysari\LaravelTestGenerator\Generator\Contracts\GeneratorInterface;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\RouteAnalyzer;

/**
 * Generate comprehensive controller tests with HTTP method testing
 */
class ControllerTestGenerator implements GeneratorInterface
{
    private ?RouteAnalyzer $routeAnalyzer = null;
    private array $routes = [];
    
    public function __construct(?RouteAnalyzer $routeAnalyzer = null)
    {
        $this->routeAnalyzer = $routeAnalyzer;
        if ($routeAnalyzer) {
            $this->routes = $routeAnalyzer->getRoutes();
        }
    }
    
    public function generate(array $data): string
    {
        $controller = $data;
        $migration = $data['migration'] ?? null;
        $className = $controller['name'];
        $isResource = $controller['is_resource'] ?? false;
        $isApi = $controller['is_api'] ?? false;
        $methods = $controller['methods'] ?? [];
        
        $namespace = $isApi ? 'Tests\Feature\Api' : 'Tests\Feature';
        $testClass = str_replace('Controller', 'ControllerTest', $className);
        
        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";

        $code .= "use Tests\TestCase;\n";
        $code .= "use App\\Models\\User;\n";

        // Detect model from route params
        $modelClass = $this->detectModelClass($methods);
        if ($modelClass && $modelClass !== 'User') {
            $code .= "use App\\Models\\{$modelClass};\n";
        }
        
        $code .= "\n";
        $code .= "/**\n";
        $code .= " * @covers \\App\\Http\\Controllers\\{$className}\n";
        $code .= " */\n";
        $code .= "class {$testClass} extends TestCase\n";
        $code .= "{\n";
        
        // Generate setup if needed
        if ($modelClass) {
            $code .= $this->generateSetup($modelClass);
        }
        
        // Generate tests for each method
        foreach ($methods as $method) {
            $code .= $this->generateMethodTest($method, $className, $modelClass, $isApi);
        }
        
        $code .= "}\n";
        
        return $code;
    }
    
    private function generateSetup(string $modelClass): string
    {
        $var = lcfirst($modelClass);

        return "    protected User \$user;\n\n" .
               "    protected function setUp(): void\n" .
               "    {\n" .
               "        parent::setUp();\n" .
               "        \$this->user = User::factory()->create();\n" .
               "    }\n\n";
    }
    
    private function generateMethodTest(array $method, string $controller, ?string $modelClass, bool $isApi): string
    {
        $methodName = $method['name'];
        $httpMethod = strtolower($method['http_method']);
        $hasValidation = $method['has_validation'] ?? false;
        $routeParams = $method['route_params'] ?? [];
        $hasImplementation = $method['has_implementation'] ?? true; // Default to true for backward compatibility
        
        // Find actual route for this controller method
        $routeData = $this->findRouteForMethod($controller, $methodName);
        
        $testName = "test_" . $this->convertToSnakeCase($methodName);
        $code = "    /**\n";
        $code .= "     * Test {$methodName} method\n";
        if ($routeData) {
            $code .= "     * Route: " . implode('|', $routeData['http_methods']) . " {$routeData['uri']}\n";
        }
        
        // Only warn about missing routes if we have a RouteAnalyzer and found no routes
        if ($this->routeAnalyzer && !$routeData && $this->isResourceMethod($methodName)) {
            $code .= "     * \n";
            $code .= "     * WARNING: No route found for this controller method.\n";
            $code .= "     * Please add a route in routes/web.php or routes/api.php\n";
        }
        
        if (!$hasImplementation) {
            $code .= "     * \n";
            $code .= "     * WARNING: Controller method appears to be empty.\n";
            $code .= "     * Please implement the method logic before running this test.\n";
        }
        $code .= "     */\n";
        $code .= "    public function {$testName}(): void\n";
        $code .= "    {\n";
        
        // Add warning if routes were analyzed but not found (skip if RouteAnalyzer wasn't used)
        if ($this->routeAnalyzer && !$routeData && $this->isResourceMethod($methodName)) {
            $code .= "        // TODO: Add route definition for this controller method\n";
            $code .= "        \$this->markTestIncomplete(\n";
            $code .= "            'No route defined for {$controller}::{$methodName}. Add route first.'\n";
            $code .= "        );\n";
            $code .= "    }\n\n";
            return $code;
        }
        
        // Add warning if no implementation (always check this)
        if (!$hasImplementation) {
            $code .= "        // TODO: Implement the controller method first\n";
            $code .= "        \$this->markTestIncomplete(\n";
            $code .= "            '{$controller}::{$methodName} method is empty. Implement method first.'\n";
            $code .= "        );\n";
            $code .= "    }\n\n";
            return $code;
        }
        
        // Add authentication
        $code .= "        \$this->actingAs(\$this->user);\n\n";

        // Setup data if needed
        if ($routeData && !empty($routeData['parameters']) && $modelClass) {
            $var = lcfirst($modelClass);
            $code .= "        \${$var} = {$modelClass}::factory()->create();\n";
        } elseif (!empty($routeParams) && $modelClass) {
            $var = lcfirst($modelClass);
            $code .= "        \${$var} = {$modelClass}::factory()->create();\n";
        }
        
        // Prepare request data for validation tests
        if ($hasValidation) {
            $code .= "        \$data = [\n";
            $code .= $this->generateSampleData($methodName);
            $code .= "        ];\n\n";
        }
        
        // Build route using actual route data
        if ($routeData) {
            $route = $this->buildRouteFromRouteData($routeData, $modelClass);
            // Use actual HTTP method from route
            $actualHttpMethod = strtolower($routeData['http_methods'][0]);
        } else {
            $route = $this->buildRoute($methodName, $controller, $routeParams, $modelClass);
            $actualHttpMethod = $httpMethod;
        }
        
        // Make request
        if ($hasValidation) {
            $code .= "        \$response = \$this->{$actualHttpMethod}({$route}, \$data);\n\n";
        } else {
            $code .= "        \$response = \$this->{$actualHttpMethod}({$route});\n\n";
        }
        
        // Assertions
        $code .= $this->generateAssertions($methodName, $actualHttpMethod, $isApi, $modelClass);
        
        $code .= "    }\n\n";
        
        // Add validation test if method has validation
        if ($hasValidation) {
            $code .= $this->generateValidationTest($methodName, $actualHttpMethod, $route, $controller);
        }

        // Add middleware test if route has middleware
        if ($routeData && !empty($routeData['middleware'])) {
            $code .= $this->generateMiddlewareTest($methodName, $routeData, $route, $isApi);
        }

        // Add 404 test for show/update/destroy methods
        if ($modelClass && in_array($methodName, ['show', 'update', 'destroy', 'edit'])) {
            $code .= $this->generateNotFoundTest($methodName, $actualHttpMethod, $route, $modelClass);
        }

        return $code;
    }
    
    /**
     * Find route data for specific controller method
     */
    private function findRouteForMethod(string $controller, string $methodName): ?array
    {
        $controllerName = class_basename($controller);
        
        foreach ($this->routes as $route) {
            $routeController = $route['controller'] ?? null;
            $routeMethod = $route['method'] ?? null;
            
            if ($routeController === $controllerName && $routeMethod === $methodName) {
                return $route;
            }
        }
        
        return null;
    }
    
    /**
     * Build route string from RouteAnalyzer data
     */
    private function buildRouteFromRouteData(array $routeData, ?string $modelClass): string
    {
        $uri = $routeData['uri'];
        $parameters = $routeData['parameters'] ?? [];
        
        if (empty($parameters)) {
            return "'{$uri}'";
        }
        
        // Build route with parameter replacements
        $var = lcfirst($modelClass ?? 'model');
        $parts = [];
        $currentString = '';
        
        // Split by parameters
        $pattern = '/\{([^}]+)\}/';
        $lastPos = 0;
        
        preg_match_all($pattern, $uri, $matches, PREG_OFFSET_CAPTURE);
        
        foreach ($matches[0] as $index => $match) {
            $fullMatch = $match[0];
            $position = $match[1];
            
            // Add string before parameter
            if ($position > $lastPos) {
                $parts[] = "'" . substr($uri, $lastPos, $position - $lastPos);
            }
            
            // Add parameter variable
            $parts[] = "' . \${$var}->id . '";
            
            $lastPos = $position + strlen($fullMatch);
        }
        
        // Add remaining string
        if ($lastPos < strlen($uri)) {
            $parts[] = substr($uri, $lastPos) . "'";
        } else {
            $parts[] = "'";
        }
        
        $result = implode('', $parts);
        
        // Clean up
        $result = str_replace("'' . ", '', $result);
        $result = str_replace(" . ''", '', $result);
        
        return $result;
    }
    
    private function buildRoute(string $methodName, string $controller, array $routeParams, ?string $modelClass): string
    {
        $resourceName = strtolower(str_replace('Controller', '', $controller));
        $resourceName = $this->pluralize($resourceName);
        
        $route = "'{$resourceName}";
        
        if (in_array($methodName, ['show', 'update', 'destroy', 'edit']) && !empty($routeParams)) {
            $var = lcfirst($modelClass ?? 'model');
            $route .= "/' . \${$var}->id";
        }
        
        $route .= "'";
        
        return $route;
    }
    
    private function generateSampleData(string $methodName): string
    {
        // Common validation fields
        $data = '';
        
        if (in_array($methodName, ['store', 'create'])) {
            $data .= "            'name' => 'Test Name',\n";
            $data .= "            'email' => 'test@example.com',\n";
        } elseif ($methodName === 'update') {
            $data .= "            'name' => 'Updated Name',\n";
        }
        
        return $data;
    }
    
    private function generateAssertions(string $methodName, string $httpMethod, bool $isApi, ?string $modelClass = null): string
    {
        $code = '';
        
        // Status code assertions
        if ($methodName === 'store') {
            $code .= "        \$response->assertStatus(201);\n";
        } elseif ($methodName === 'destroy') {
            $code .= "        \$response->assertStatus(204);\n";
        } else {
            $code .= "        \$response->assertStatus(200);\n";
        }
        
        // JSON assertions for API
        if ($isApi && $methodName !== 'destroy') {
            $code .= "        \$response->assertJsonStructure([]);\n";
        }
        
        // Database assertions - only if model is available
        if ($modelClass) {
            $tableName = $this->getTableName($modelClass);
            $var = lcfirst($modelClass);
            
            if ($methodName === 'store') {
                $code .= "        // TODO: Update assertions based on your model's fillable attributes\n";
                $code .= "        \$this->assertDatabaseHas('{$tableName}', [\n";
                $code .= "            // Add your assertions here\n";
                $code .= "        ]);\n";
            } elseif ($methodName === 'destroy') {
                $code .= "        \$this->assertDatabaseMissing('{$tableName}', [\n";
                $code .= "            'id' => \${$var}->id,\n";
                $code .= "        ]);\n";
            }
        }
        
        return $code;
    }
    
    private function generateValidationTest(string $methodName, string $httpMethod, string $route, string $controller): string
    {
        $testName = "test_" . $this->convertToSnakeCase($methodName) . "_validation";
        
        $code = "    /**\n";
        $code .= "     * Test {$methodName} validation\n";
        $code .= "     */\n";
        $code .= "    public function {$testName}(): void\n";
        $code .= "    {\n";
        $code .= "        \$response = \$this->{$httpMethod}({$route}, []);\n\n";
        $code .= "        \$response->assertStatus(422);\n";
        $code .= "        \$response->assertJsonValidationErrors(['name', 'email']);\n";
        $code .= "    }\n\n";
        
        return $code;
    }
    
    /**
     * Generate middleware test for route
     */
    private function generateMiddlewareTest(string $methodName, array $routeData, string $route, bool $isApi = false): string
    {
        $testName = "test_" . $this->convertToSnakeCase($methodName) . "_requires_authentication";
        $middleware = $routeData['middleware'];
        $middlewareList = implode(', ', $middleware);

        $code = "    /**\n";
        $code .= "     * Test {$methodName} requires authentication\n";
        $code .= "     * Middleware: {$middlewareList}\n";
        $code .= "     */\n";
        $code .= "    public function {$testName}(): void\n";
        $code .= "    {\n";

        // Check for auth middleware
        $hasAuth = in_array('auth', $middleware) || in_array('auth:sanctum', $middleware) || in_array('auth:api', $middleware);

        if ($hasAuth) {
            $httpMethod = strtolower($routeData['http_methods'][0]);
            $code .= "        // Test without authentication\n";
            $code .= "        \$response = \$this->{$httpMethod}({$route});\n\n";

            if ($isApi || in_array('auth:sanctum', $middleware) || in_array('auth:api', $middleware)) {
                $code .= "        \$response->assertStatus(401);\n";
            } else {
                $code .= "        \$response->assertStatus(302);\n";
                $code .= "        \$response->assertRedirect('/login');\n";
            }
        } else {
            $code .= "        // Middleware: {$middlewareList}\n";
            $code .= "        \$this->assertTrue(true);\n";
        }

        $code .= "    }\n\n";

        return $code;
    }

    /**
     * Generate test for 404 not found response
     */
    private function generateNotFoundTest(string $methodName, string $httpMethod, string $route, ?string $modelClass): string
    {
        if (!in_array($methodName, ['show', 'update', 'destroy', 'edit'])) {
            return '';
        }

        $testName = "test_" . $this->convertToSnakeCase($methodName) . "_returns_404_for_invalid_id";

        $code = "    /**\n";
        $code .= "     * Test {$methodName} returns 404 for non-existent resource\n";
        $code .= "     */\n";
        $code .= "    public function {$testName}(): void\n";
        $code .= "    {\n";
        $code .= "        \$this->actingAs(\$this->user);\n\n";

        // Replace model id with invalid id
        $invalidRoute = preg_replace('/\$[a-zA-Z]+->id/', '99999', $route);
        $code .= "        \$response = \$this->{$httpMethod}({$invalidRoute});\n\n";
        $code .= "        \$response->assertStatus(404);\n";
        $code .= "    }\n\n";

        return $code;
    }
    
    private function detectModelClass(array $methods): ?string
    {
        foreach ($methods as $method) {
            if (!empty($method['parameters'])) {
                foreach ($method['parameters'] as $param) {
                    $type = $param['type'] ?? '';
                    if ($type && !in_array($type, ['Request', 'int', 'string', 'bool', 'array'])) {
                        return $type;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Convert model class name to table name
     */
    private function getTableName(string $modelClass): string
    {
        // Convert PascalCase to snake_case and pluralize
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelClass));
        return $this->pluralize($tableName);
    }
    
    /**
     * Check if method is a standard resource method
     */
    private function isResourceMethod(string $methodName): bool
    {
        return in_array($methodName, ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    }
    
    private function convertToSnakeCase(string $str): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $str));
    }
    
    private function pluralize(string $word): string
    {
        // Simple pluralization
        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        
        if (in_array(substr($word, -1), ['s', 'x', 'z']) || in_array(substr($word, -2), ['sh', 'ch'])) {
            return $word . 'es';
        }
        
        return $word . 's';
    }
    
    public function getTestPath(array $data): string
    {
        $controller = $data;
        $controllerName = $controller['name'];
        $namespace = $controller['namespace'] ?? '';
        $testName = str_replace('Controller', 'ControllerTest', $controllerName);
        $isApi = $controller['is_api'] ?? false;
        
        // Extract sub-path from namespace (e.g., App\Http\Controllers\Admin\PostController -> Admin)
        $subPath = $this->extractSubPathFromNamespace($namespace);
        
        $baseDir = $isApi ? 'Feature/Api' : 'Feature';
        
        if ($subPath) {
            return "tests/{$baseDir}/{$subPath}/{$testName}.php";
        }
        
        return "tests/{$baseDir}/{$testName}.php";
    }
    
    /**
     * Extract subdirectory path from namespace
     * App\Http\Controllers\Admin\PostController -> Admin
     * App\Controllers\Api\V1\UserController -> Api/V1
     */
    private function extractSubPathFromNamespace(string $namespace): string
    {
        // Remove App\ or similar prefix
        $parts = explode('\\', $namespace);
        
        // Find Controllers base directory
        $baseIndex = -1;
        foreach ($parts as $i => $part) {
            if ($part === 'Controllers') {
                $baseIndex = $i;
                break;
            }
        }
        
        // Get everything after Controllers directory
        if ($baseIndex !== -1 && isset($parts[$baseIndex + 1])) {
            return implode('/', array_slice($parts, $baseIndex + 1));
        }
        
        return '';
    }
}
