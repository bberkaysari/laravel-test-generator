<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Generator\Generators;

use Bberkaysari\LaravelTestGenerator\Generator\Contracts\GeneratorInterface;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\DependencyAnalyzer;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\MethodAnalyzer;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\QueryAnalyzer;

/**
 * ServiceTestGenerator - Generates comprehensive tests for Service/Repository classes
 * 
 * Features:
 * - Tests all public methods
 * - Mocks dependencies automatically
 * - Tests edge cases and error handling
 * - Tests database transaction usage
 * - Tests event/job dispatching
 * - Tests complex business logic
 */
class ServiceTestGenerator implements GeneratorInterface
{
    private DependencyAnalyzer $depAnalyzer;
    private MethodAnalyzer $methodAnalyzer;
    private QueryAnalyzer $queryAnalyzer;

    public function __construct(
        ?DependencyAnalyzer $depAnalyzer = null,
        ?MethodAnalyzer $methodAnalyzer = null,
        ?QueryAnalyzer $queryAnalyzer = null
    ) {
        $this->depAnalyzer = $depAnalyzer ?? new DependencyAnalyzer();
        $this->methodAnalyzer = $methodAnalyzer ?? new MethodAnalyzer();
        $this->queryAnalyzer = $queryAnalyzer ?? new QueryAnalyzer();
    }

    public function generate(array $data): string
    {
        $className = $data['name'];
        $fqn = $data['fqn'];
        $methods = $data['methods'] ?? [];
        $constructor = $data['constructor'] ?? null;

        // Analyze dependencies
        $depAnalysis = $this->depAnalyzer->analyzeClass($data);
        $dependencies = $depAnalysis['dependencies'];

        // Build test class
        $testClass = $this->generateTestClass($className, $fqn, $methods, $dependencies);

        return $testClass;
    }

    public function supports(string $type): bool
    {
        return in_array($type, ['service', 'repository']);
    }

    public function getTestPath(array $data): string
    {
        $className = $data['name'] ?? 'Unknown';
        $namespace = $data['namespace'] ?? '';
        $testFile = str_replace('Service', 'ServiceTest', $className);
        $testFile = str_replace('Repository', 'RepositoryTest', $testFile);
        
        // Extract sub-path from namespace
        $subPath = $this->extractSubPathFromNamespace($namespace);
        
        if ($subPath) {
            // Return relative path without tests/Unit prefix (Command adds it)
            return $subPath . '/' . $testFile . '.php';
        }
        
        // Default to Services directory
        return 'Services/' . $testFile . '.php';
    }
    
    /**
     * Extract subdirectory path from namespace
     * App\Services\User\UserService -> Services/User
     * App\Http\Controllers\UserService -> Http/Controllers (if misplaced)
     * App\Repositories\PostRepository -> Repositories
     */
    private function extractSubPathFromNamespace(string $namespace): string
    {
        // Remove App\ or similar prefix
        $parts = explode('\\', $namespace);
        
        // Find base directory (Services, Repositories, or keep full path if misplaced)
        $baseIndex = -1;
        $baseDirs = ['Services', 'Repositories', 'Domain', 'Http'];
        
        foreach ($parts as $i => $part) {
            if (in_array($part, $baseDirs)) {
                $baseIndex = $i;
                break;
            }
        }
        
        // Get everything from base directory onwards
        if ($baseIndex !== -1) {
            return implode('/', array_slice($parts, $baseIndex));
        }
        
        return '';
    }

    private function generateTestClass(string $className, string $fqn, array $methods, array $dependencies): string
    {
        $testClassName = $className . 'Test';
        $mockSetup = $this->generateMockSetup($dependencies);

        // Determine if this is a repository
        $isRepository = str_contains($fqn, 'Repository');

        // Collect model types used in methods for imports
        $modelTypes = $this->collectModelTypesFromMethods($methods);

        $methodTests = $this->generateMethodTests($methods, $dependencies, $isRepository);

        // Calculate dynamic namespace from FQN
        $testNamespace = $this->calculateTestNamespace($fqn);

        // Generate import statements for dependencies
        $imports = $this->generateImports($fqn, $dependencies, $isRepository, $modelTypes);

        // No automatic RefreshDatabase trait - let users decide
        $traitUsage = '';

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$testNamespace};

{$imports}

class {$testClassName} extends TestCase
{{$traitUsage}
    private {$className} \$service;
{$this->generateMockProperties($dependencies)}

    protected function setUp(): void
    {
        parent::setUp();
{$mockSetup}
        \$this->service = new {$className}(
{$this->generateConstructorArgs($dependencies)}
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

{$methodTests}
}

PHP;

        return $code;
    }

    /**
     * Collect model types used in method parameters and return types
     */
    private function collectModelTypesFromMethods(array $methods): array
    {
        $modelTypes = [];

        foreach ($methods as $method) {
            // Check return type
            $returnType = $method['return_type'] ?? '';
            if ($returnType && $this->looksLikeModel($returnType)) {
                $modelTypes[] = ltrim($returnType, '?');
            }

            // Check parameters
            foreach ($method['parameters'] ?? [] as $param) {
                $type = $param['type'] ?? '';
                if ($type && $this->looksLikeModel($type)) {
                    $modelTypes[] = ltrim($type, '?');
                }
            }
        }

        return array_unique($modelTypes);
    }

    /**
     * Calculate test namespace from original FQN
     * App\Services\User\UserService -> Tests\Unit\Services\User
     * App\Repositories\PostRepository -> Tests\Unit\Repositories
     */
    private function calculateTestNamespace(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        array_pop($parts); // Remove class name

        // Find the base directory index
        $baseIndex = -1;
        $baseDirs = ['Services', 'Repositories', 'Domain'];

        foreach ($parts as $i => $part) {
            if (in_array($part, $baseDirs)) {
                $baseIndex = $i;
                break;
            }
        }

        if ($baseIndex !== -1) {
            $relevantParts = array_slice($parts, $baseIndex);
            return 'Tests\\Unit\\' . implode('\\', $relevantParts);
        }

        // Fallback
        return 'Tests\\Unit\\Services';
    }

    /**
     * Generate use/import statements
     */
    private function generateImports(string $fqn, array $dependencies, bool $isRepository = false, array $modelTypes = []): string
    {
        $imports = [];

        // Always import the class being tested
        $imports[] = "use {$fqn};";
        $imports[] = "use Tests\\TestCase;";
        $imports[] = "use Mockery;";
        $imports[] = "use Mockery\\MockInterface;";

        // No automatic RefreshDatabase import - let users decide based on their needs

        // Import dependency types (interfaces, classes)
        foreach ($dependencies as $dep) {
            $type = $dep['type'] ?? null;
            if (!$type) {
                continue;
            }

            // Clean nullable indicator
            $type = ltrim($type, '?');

            // Skip primitive types
            if ($this->isPrimitiveType($type)) {
                continue;
            }

            // Get full FQN if available
            $depFqn = $dep['fqn'] ?? null;
            if ($depFqn) {
                $imports[] = "use {$depFqn};";
            } elseif (str_contains($type, 'Interface')) {
                // Try to guess interface namespace
                $imports[] = "use App\\Repositories\\Interfaces\\{$type};";
            }
        }

        // Import model types used in method signatures
        foreach ($modelTypes as $modelType) {
            // Skip if already has namespace
            if (str_contains($modelType, '\\')) {
                $imports[] = "use {$modelType};";
            } else {
                // Try to find model in common locations
                $imports[] = "use App\\Models\\{$modelType};";
            }
        }

        // Remove duplicates and sort
        $imports = array_unique($imports);

        // Filter out invalid imports (double namespace)
        $imports = array_filter($imports, function($import) {
            // Remove imports like "use App\Models\App\Models\File;"
            return !preg_match('/use\s+[^;]*\\\\[^;]*\\\\[^;]*\\\\/', $import);
        });

        return implode("\n", $imports);
    }

    private function generateMockProperties(array $dependencies): string
    {
        if (empty($dependencies)) {
            return '';
        }

        $properties = [];
        foreach ($dependencies as $dep) {
            $type = $dep['type'] ?? 'mixed';
            $name = $dep['name'];

            // Clean type
            $cleanType = ltrim($type, '?');

            // Skip primitive types - they don't need mock properties
            if ($this->isPrimitiveType($cleanType)) {
                continue;
            }

            $properties[] = "    private MockInterface \${$name};";
        }

        return implode("\n", $properties);
    }

    private function generateMockSetup(array $dependencies): string
    {
        if (empty($dependencies)) {
            return '        // No dependencies to mock';
        }

        $setup = [];
        foreach ($dependencies as $dep) {
            $type = $dep['type'] ?? 'stdClass';
            $name = $dep['name'];

            // Clean type (remove nullable ?)
            $type = ltrim($type, '?');

            // Skip primitive types - they can't be mocked
            if ($this->isPrimitiveType($type)) {
                continue;
            }

            $setup[] = "        \$this->{$name} = Mockery::mock({$type}::class);";
        }

        if (empty($setup)) {
            return '        // No mockable dependencies';
        }

        return implode("\n", $setup);
    }

    /**
     * Check if type is a primitive PHP type that cannot be mocked
     */
    private function isPrimitiveType(string $type): bool
    {
        $primitives = [
            'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean',
            'array', 'object', 'callable', 'iterable', 'mixed', 'null', 'void',
            'never', 'true', 'false', 'resource', 'stdClass'
        ];

        return in_array(strtolower($type), $primitives);
    }

    private function generateConstructorArgs(array $dependencies): string
    {
        if (empty($dependencies)) {
            return '';
        }

        $args = [];
        foreach ($dependencies as $dep) {
            $name = $dep['name'];
            $type = ltrim($dep['type'] ?? 'mixed', '?');

            // Use default value for primitive types, mock reference for objects
            if ($this->isPrimitiveType($type)) {
                $args[] = "            " . $this->getDefaultValue($type);
            } else {
                $args[] = "            \$this->{$name}";
            }
        }

        return implode(",\n", $args);
    }

    private function generateMethodTests(array $methods, array $dependencies, bool $isRepository = false): string
    {
        $tests = [];

        foreach ($methods as $method) {
            // Skip private/protected methods
            if ($method['visibility'] !== 'public') {
                continue;
            }

            $methodName = $method['name'];
            $tests[] = $this->generateMethodTest($method, $dependencies, $isRepository);

            // Generate edge case tests for both repository and service tests
            // Edge cases are important for catching bugs!
            $edgeCaseTests = $this->generateEdgeCaseTests($method, $dependencies, $isRepository);
            foreach ($edgeCaseTests as $edgeTest) {
                if ($edgeTest) {
                    $tests[] = $edgeTest;
                }
            }

            // Generate data provider test if method has simple parameters
            $dataProviderTest = $this->generateDataProviderTest($method, $dependencies, $isRepository);
            if ($dataProviderTest) {
                $tests[] = $dataProviderTest;
            }
        }

        return implode("\n\n", array_filter($tests));
    }

    private function generateMethodTest(array $method, array $dependencies, bool $isRepository = false): string
    {
        $methodName = $method['name'];
        $testName = 'test_' . $this->camelToSnake($methodName);
        $params = $method['parameters'] ?? [];
        $returnType = $method['return_type'] ?? 'void';

        // Generate parameter setup - use factory for repositories, mocks for services
        $paramSetup = $this->generateParameterSetup($params, $isRepository);
        $methodCall = $this->generateMethodCall($methodName, $params);

        // Generate mock expectations
        $mockExpectations = $this->generateMockExpectations($dependencies, $methodName);

        // Generate assertions
        $assertions = $this->generateAssertions($returnType);

        // Generate PHPDoc annotations
        $testGroup = $isRepository ? 'repository' : 'service';
        $annotations = <<<ANNOT
    /**
     * Test {$methodName} method
     *
     * @test
     * @group {$testGroup}
     * @group unit
     */
ANNOT;

        $code = <<<PHP
{$annotations}
    public function {$testName}(): void
    {
{$paramSetup}
{$mockExpectations}
        \$result = {$methodCall};

{$assertions}
    }
PHP;

        return $code;
    }

    /**
     * Generate multiple edge case tests for a method
     * Returns an array of test code strings
     */
    private function generateEdgeCaseTests(array $method, array $dependencies, bool $isRepository): array
    {
        $methodName = $method['name'];
        $params = $method['parameters'] ?? [];
        $returnType = $method['return_type'] ?? 'void';
        $tests = [];

        // Edge Case 1: Empty/null parameters
        if (!empty($params)) {
            $hasNullableOrArray = false;
            foreach ($params as $param) {
                $type = $param['type'] ?? '';
                if (str_starts_with($type, '?') || $type === 'array' || str_contains($type, 'null')) {
                    $hasNullableOrArray = true;
                    break;
                }
            }

            if ($hasNullableOrArray) {
                $testName = 'test_' . $this->camelToSnake($methodName) . '_with_null_or_empty_params';
                $paramSetup = [];
                foreach ($params as $param) {
                    $name = $param['name'];
                    $type = $param['type'] ?? 'mixed';

                    if (str_starts_with($type, '?')) {
                        $paramSetup[] = "        \${$name} = null;";
                    } elseif ($type === 'array') {
                        $paramSetup[] = "        \${$name} = [];";
                    } else {
                        $paramSetup[] = "        \${$name} = " . $this->getDefaultValueForTest($type) . ";";
                    }
                }

                $methodCall = $this->generateMethodCall($methodName, $params);
                $tests[] = <<<PHP
    /**
     * Edge case: Test {$methodName} with null/empty parameters
     *
     * @test
     * @group edge-case
     * @group unit
     */
    public function {$testName}(): void
    {
{$this->indent($paramSetup, 1)}

        \$result = {$methodCall};

        // TODO: Add assertions for edge case behavior
        // What should happen when parameters are null/empty?
        \$this->assertNotNull(\$result);
    }
PHP;
            }
        }

        // Edge Case 2: Invalid data scenarios (for Services/Repositories)
        // Only add this as a TODO/template, user should implement based on business logic
        $testName = 'test_' . $this->camelToSnake($methodName) . '_handles_errors';
        $tests[] = <<<PHP
    /**
     * Edge case: Test {$methodName} error handling
     * TODO: Implement based on your error scenarios
     *
     * @test
     * @group error-handling
     * @group unit
     */
    public function {$testName}(): void
    {
        \$this->markTestIncomplete(
            'TODO: Test error scenarios for {$methodName} - Add mock expectations that throw exceptions or return error responses'
        );

        // Example for ServiceResponse:
        // Configure mocks to return error response
        // \$result = \$this->service->{$methodName}(...);
        // \$this->assertFalse(\$result->success);
        // \$this->assertNotNull(\$result->message);
    }
PHP;

        return $tests;
    }

    /**
     * Generate data provider test for methods with simple parameters
     * Uses @dataProvider annotation for testing multiple scenarios
     */
    private function generateDataProviderTest(array $method, array $dependencies, bool $isRepository): string
    {
        $methodName = $method['name'];
        $params = $method['parameters'] ?? [];
        $returnType = $method['return_type'] ?? 'void';

        // Only generate for methods with 1-3 simple parameters (int, string, bool)
        if (empty($params) || count($params) > 3) {
            return '';
        }

        $hasOnlySimpleParams = true;
        foreach ($params as $param) {
            $type = $param['type'] ?? 'mixed';
            $cleanType = ltrim($type, '?');
            if (!in_array($cleanType, ['int', 'integer', 'string', 'bool', 'boolean', 'float', 'double'])) {
                $hasOnlySimpleParams = false;
                break;
            }
        }

        if (!$hasOnlySimpleParams) {
            return '';
        }

        $testName = 'test_' . $this->camelToSnake($methodName) . '_with_various_inputs';
        $providerName = $this->camelToSnake($methodName) . 'DataProvider';
        $methodCall = $this->generateMethodCall($methodName, $params);
        $paramList = implode(', ', array_map(fn($p) => '$' . $p['name'], $params));

        $code = <<<PHP
    /**
     * Test {$methodName} with various input combinations
     *
     * @dataProvider {$providerName}
     * @group parametrized
     */
    public function {$testName}({$this->generateDataProviderParams($params)}): void
    {
        // TODO: Configure mock expectations based on input data

        \$result = {$methodCall};

        // TODO: Add assertions based on expected behavior for each scenario
        \$this->assertNotNull(\$result);
    }

    /**
     * Data provider for {$methodName} test
     *
     * @return array<string, array>
     */
    public static function {$providerName}(): array
    {
        return [
            'valid_input' => [{$this->generateSampleDataProviderValues($params, 'valid')}],
            'edge_case_zeros' => [{$this->generateSampleDataProviderValues($params, 'zero')}],
            'edge_case_negatives' => [{$this->generateSampleDataProviderValues($params, 'negative')}],
            // TODO: Add more test scenarios based on your business logic
        ];
    }
PHP;

        return $code;
    }

    /**
     * Generate parameter list for data provider test
     */
    private function generateDataProviderParams(array $params): string
    {
        $paramsList = [];
        foreach ($params as $param) {
            $type = $param['type'] ?? 'mixed';
            $name = $param['name'];
            $paramsList[] = "{$type} \${$name}";
        }
        return implode(', ', $paramsList);
    }

    /**
     * Generate sample values for data provider scenarios
     */
    private function generateSampleDataProviderValues(array $params, string $scenario): string
    {
        $values = [];
        foreach ($params as $param) {
            $type = ltrim($param['type'] ?? 'mixed', '?');

            if ($scenario === 'valid') {
                if (in_array($type, ['int', 'integer'])) {
                    $values[] = '1';
                } elseif (in_array($type, ['string'])) {
                    $values[] = "'test'";
                } elseif (in_array($type, ['bool', 'boolean'])) {
                    $values[] = 'true';
                } elseif (in_array($type, ['float', 'double'])) {
                    $values[] = '1.5';
                }
            } elseif ($scenario === 'zero') {
                if (in_array($type, ['int', 'integer', 'float', 'double'])) {
                    $values[] = '0';
                } elseif (in_array($type, ['string'])) {
                    $values[] = "''";
                } elseif (in_array($type, ['bool', 'boolean'])) {
                    $values[] = 'false';
                }
            } elseif ($scenario === 'negative') {
                if (in_array($type, ['int', 'integer'])) {
                    $values[] = '-1';
                } elseif (in_array($type, ['float', 'double'])) {
                    $values[] = '-1.5';
                } elseif (in_array($type, ['string'])) {
                    $values[] = "'invalid'";
                } elseif (in_array($type, ['bool', 'boolean'])) {
                    $values[] = 'false';
                }
            }
        }
        return implode(', ', $values);
    }

    /**
     * Get default value for test parameters - handles object types with mocks
     */
    private function getDefaultValueForTest(string $type): string
    {
        $type = ltrim($type, '?');

        // Primitive types
        if ($this->isPrimitiveType($type)) {
            return $this->getDefaultValue($type);
        }

        // Object types - create a mock or factory
        if (str_contains($type, 'Model') || $this->looksLikeModel($type)) {
            return "{$type}::factory()->create()";
        }

        // For interfaces/services, use Mockery
        return "Mockery::mock({$type}::class)";
    }

    /**
     * Check if type looks like an Eloquent model
     */
    private function looksLikeModel(string $type): bool
    {
        $type = ltrim($type, '?');

        // Skip non-model patterns
        $skipPatterns = [
            'Interface', 'Repository', 'Service', 'Request', 'Contract',
            'Handler', 'Factory', 'Provider', 'Manager', 'Helper', 'Validator',
            'Exception', 'Event', 'Listener', 'Job', 'Mail', 'Notification',
            'Policy', 'Rule', 'Middleware', 'Collection', 'Builder'
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($type, $pattern)) {
                return false;
            }
        }

        // Check for common model patterns
        $modelPatterns = [
            'User', 'Post', 'Order', 'Product', 'Cart', 'Item', 'Payment',
            'Address', 'Category', 'Tag', 'Comment', 'Image', 'File', 'Media',
            'Variant', 'Batch', 'Row', 'Import', 'Export', 'Invoice', 'Customer',
            'Subscription', 'Plan', 'Role', 'Permission', 'Setting', 'Config'
        ];

        foreach ($modelPatterns as $pattern) {
            if (str_contains($type, $pattern)) {
                return true;
            }
        }

        // If type is PascalCase and doesn't match skip patterns, likely a model
        if (preg_match('/^[A-Z][a-z]+(?:[A-Z][a-z]+)*$/', $type)) {
            return true;
        }

        return false;
    }

    private function generateParameterSetup(array $params, bool $isRepository = false): string
    {
        if (empty($params)) {
            return '        // No parameters needed';
        }

        $setup = [];
        foreach ($params as $param) {
            $name = $param['name'];
            $type = $param['type'] ?? 'mixed';
            $cleanType = ltrim($type, '?');

            // For repositories, use factory for models
            if ($isRepository && $this->looksLikeModel($cleanType)) {
                $setup[] = "        \${$name} = {$cleanType}::factory()->create();";
            } else {
                $default = $this->getDefaultValueForTest($type);
                $setup[] = "        \${$name} = {$default};";
            }
        }

        return implode("\n", $setup);
    }

    private function generateMethodCall(string $methodName, array $params): string
    {
        if (empty($params)) {
            return "\$this->service->{$methodName}()";
        }

        $args = array_map(fn($p) => '$' . $p['name'], $params);
        return "\$this->service->{$methodName}(" . implode(', ', $args) . ")";
    }

    private function generateMockExpectations(array $dependencies, string $methodName): string
    {
        if (empty($dependencies)) {
            return '        // No mocks to configure';
        }

        // Intelligent mock expectations based on dependency types
        $expectations = [];
        $hasExpectations = false;

        foreach ($dependencies as $dep) {
            $name = $dep['name'];
            $type = $dep['type'] ?? '';

            // Repository dependencies - common patterns
            if (str_contains($type, 'Repository')) {
                $expectations[] = "        // TODO: Configure {$name} mock expectations";
                $expectations[] = "        // Example: \$this->{$name}->shouldReceive('find')->with(\$id)->once()->andReturn(\$mockData);";
                $expectations[] = "        // Example: \$this->{$name}->shouldReceive('create')->once()->andReturn(\$model);";
                $hasExpectations = true;
            }
            // Service dependencies
            elseif (str_contains($type, 'Service')) {
                $expectations[] = "        // TODO: Configure {$name} mock expectations";
                $expectations[] = "        // Example: \$this->{$name}->shouldReceive('process')->once()->andReturn(new ServiceResponse(true, 'Success', []));";
                $hasExpectations = true;
            }
            // API/Network dependencies
            elseif (str_contains($type, 'Client') || str_contains($type, 'Http') || str_contains($type, 'Api')) {
                $expectations[] = "        // TODO: Configure {$name} API mock expectations";
                $expectations[] = "        // Example: \$this->{$name}->shouldReceive('get')->once()->andReturn(['status' => 200, 'data' => []]);";
                $hasExpectations = true;
            }
            // Event/Queue dependencies
            elseif (str_contains($type, 'Dispatcher') || str_contains($type, 'Queue')) {
                $expectations[] = "        // TODO: Configure {$name} mock expectations";
                $expectations[] = "        // Example: \$this->{$name}->shouldReceive('dispatch')->once();";
                $hasExpectations = true;
            }
        }

        if (!$hasExpectations) {
            return "        // TODO: Configure mock expectations for method '{$methodName}'\n" .
                   "        // Add shouldReceive() calls to define expected behavior of dependencies";
        }

        return implode("\n", $expectations);
    }

    private function generateAssertions(string $returnType): string
    {
        $returnType = ltrim($returnType, '?');

        if ($returnType === 'void' || $returnType === '') {
            return '        // Void method - verify no exception was thrown
        $this->addToAssertionCount(1);';
        }

        // Special handling for ServiceResponse
        if ($returnType === 'ServiceResponse' || str_contains($returnType, 'ServiceResponse')) {
            return <<<'ASSERTIONS'
        $this->assertInstanceOf(ServiceResponse::class, $result);
        // TODO: Add specific assertions based on your business logic
        // $this->assertTrue($result->success);
        // $this->assertNotNull($result->data);
        // $this->assertIsArray($result->data);
        // $this->assertEquals('Expected message', $result->message);
ASSERTIONS;
        }

        if ($returnType === 'bool') {
            return <<<'ASSERTIONS'
        $this->assertIsBool($result);
        // TODO: Verify the expected boolean value
        // $this->assertTrue($result);
        // or $this->assertFalse($result);
ASSERTIONS;
        }

        if (in_array($returnType, ['int', 'integer'])) {
            return <<<'ASSERTIONS'
        $this->assertIsInt($result);
        // TODO: Verify the expected integer value
        // $this->assertEquals(expectedValue, $result);
        // $this->assertGreaterThan(0, $result);
ASSERTIONS;
        }

        if (in_array($returnType, ['float', 'double'])) {
            return <<<'ASSERTIONS'
        $this->assertIsFloat($result);
        // TODO: Verify the expected float value
        // $this->assertEquals(expectedValue, $result, '', 0.001);
ASSERTIONS;
        }

        if ($returnType === 'array' || str_contains($returnType, '[]')) {
            return <<<'ASSERTIONS'
        $this->assertIsArray($result);
        // TODO: Verify array contents
        // $this->assertNotEmpty($result);
        // $this->assertArrayHasKey('key', $result);
        // $this->assertCount(expectedCount, $result);
ASSERTIONS;
        }

        if ($returnType === 'string') {
            return <<<'ASSERTIONS'
        $this->assertIsString($result);
        // TODO: Verify string contents
        // $this->assertNotEmpty($result);
        // $this->assertEquals('expected', $result);
        // $this->assertStringContainsString('substring', $result);
ASSERTIONS;
        }

        if ($returnType === 'Collection' || str_contains($returnType, 'Collection')) {
            return <<<'ASSERTIONS'
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        // TODO: Verify collection contents
        // $this->assertCount(expectedCount, $result);
        // $this->assertTrue($result->isNotEmpty());
ASSERTIONS;
        }

        // Object type - check instance
        if (!$this->isPrimitiveType($returnType)) {
            return "        \$this->assertInstanceOf({$returnType}::class, \$result);\n" .
                   "        // TODO: Verify object properties and state\n" .
                   "        // \$this->assertEquals(expectedValue, \$result->property);";
        }

        return '        $this->assertNotNull($result);\n' .
               '        // TODO: Add specific assertions for this return type';
    }

    private function getDefaultValue(string $type): string
    {
        $type = ltrim($type, '?'); // Remove nullable indicator

        return match($type) {
            'int' => '1',
            'float' => '1.0',
            'string' => "'test'",
            'bool' => 'true',
            'array' => '[]',
            default => 'null',
        };
    }

    private function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
    }

    private function indent(array $lines, int $level): string
    {
        $indent = str_repeat('    ', $level);
        return $indent . implode("\n" . $indent, $lines);
    }
}
