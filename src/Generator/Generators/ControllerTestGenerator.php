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
        $code .= "use Illuminate\Foundation\Testing\RefreshDatabase;\n";
        $code .= "use Tests\TestCase;\n";
        
        // Detect model from route params
        $modelClass = $this->detectModelClass($methods);
        if ($modelClass) {
            $code .= "use App\\Models\\{$modelClass};\n";
        }
        
        $code .= "\n";
        $code .= "/**\n";
        $code .= " * @covers \\App\\Http\\Controllers\\{$className}\n";
        $code .= " */\n";
        $code .= "class {$testClass} extends TestCase\n";
        $code .= "{\n";
        $code .= "    use RefreshDatabase;\n\n";
        
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
        
        return "    protected function setUp(): void\n" .
               "    {\n" .
               "        parent::setUp();\n" .
               "        // Add authentication if needed\n" .
               "        // \$this->actingAs({$modelClass}::factory()->create());\n" .
               "    }\n\n";
    }
    
    private function generateMethodTest(array $method, string $controller, ?string $modelClass, bool $isApi): string
    {
        $methodName = $method['name'];
        $httpMethod = strtolower($method['http_method']);
        $hasValidation = $method['has_validation'] ?? false;
        $routeParams = $method['route_params'] ?? [];
        
        // Find actual route for this controller method
        $routeData = $this->findRouteForMethod($controller, $methodName);
        
        $testName = "test_" . $this->convertToSnakeCase($methodName);
        $code = "    /**\n";
        $code .= "     * Test {$methodName} method\n";
        if ($routeData) {
            $code .= "     * Route: " . implode('|', $routeData['http_methods']) . " {$routeData['uri']}\n";
        }
        $code .= "     */\n";
        $code .= "    public function {$testName}(): void\n";
        $code .= "    {\n";
        
        // Setup data if needed
        if ($routeData && !empty($routeData['parameters']) && $modelClass) {
            $var = lcfirst($modelClass);
            $code .= "        \${$var} = {$modelClass}::factory()->create();\n\n";
        } elseif (!empty($routeParams) && $modelClass) {
            $var = lcfirst($modelClass);
            $code .= "        \${$var} = {$modelClass}::factory()->create();\n\n";
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
        $code .= $this->generateAssertions($methodName, $actualHttpMethod, $isApi);
        
        $code .= "    }\n\n";
        
        // Add validation test if method has validation
        if ($hasValidation) {
            $code .= $this->generateValidationTest($methodName, $actualHttpMethod, $route, $controller);
        }
        
        // Add middleware test if route has middleware
        if ($routeData && !empty($routeData['middleware'])) {
            $code .= $this->generateMiddlewareTest($methodName, $routeData, $route);
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
    
    private function generateAssertions(string $methodName, string $httpMethod, bool $isApi): string
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
        
        // Database assertions
        if ($methodName === 'store') {
            $code .= "        \$this->assertDatabaseHas('users', [\n";
            $code .= "            'email' => 'test@example.com',\n";
            $code .= "        ]);\n";
        } elseif ($methodName === 'destroy') {
            $code .= "        \$this->assertDatabaseMissing('users', [\n";
            $code .= "            'id' => \$user->id,\n";
            $code .= "        ]);\n";
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
    private function generateMiddlewareTest(string $methodName, array $routeData, string $route): string
    {
        $testName = "test_" . $this->convertToSnakeCase($methodName) . "_middleware";
        $middleware = $routeData['middleware'];
        $middlewareList = implode(', ', $middleware);
        
        $code = "    /**\n";
        $code .= "     * Test {$methodName} middleware: {$middlewareList}\n";
        $code .= "     */\n";
        $code .= "    public function {$testName}(): void\n";
        $code .= "    {\n";
        
        // Check for auth middleware
        if (in_array('auth', $middleware)) {
            $httpMethod = strtolower($routeData['http_methods'][0]);
            $code .= "        \$response = \$this->{$httpMethod}({$route});\n\n";
            $code .= "        \$response->assertStatus(302);\n";
            $code .= "        \$response->assertRedirect('/login');\n";
        } else {
            $code .= "        // Test middleware: {$middlewareList}\n";
            $code .= "        \$this->assertTrue(true);\n";
        }
        
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
        $testName = str_replace('Controller', 'ControllerTest', $controllerName);
        $isApi = $controller['is_api'] ?? false;
        
        $subDir = $isApi ? 'Feature/Api' : 'Feature';
        
        return "tests/{$subDir}/{$testName}.php";
    }
}
