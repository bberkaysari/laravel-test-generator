<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Generator\Generators;

use Bberkaysari\LaravelTestGenerator\Generator\Contracts\GeneratorInterface;

class ModelTestGenerator implements GeneratorInterface
{
    private string $testNamespace = 'Tests\\Unit\\Models';
    private string $testFramework = 'phpunit'; // or 'pest'

    public function __construct(
        string $testNamespace = 'Tests\\Unit\\Models',
        string $testFramework = 'phpunit'
    ) {
        $this->testNamespace = $testNamespace;
        $this->testFramework = $testFramework;
    }

    public function generate(array $modelData): string
    {
        $tests = [];
        
        // Check if model is properly configured
        $hasAnyTests = !empty($modelData['fillable']) || 
                       !empty($modelData['hidden']) || 
                       !empty($modelData['casts']) || 
                       !empty($modelData['relations']);

        // Generate basic model tests
        $tests[] = $this->generateFactoryTest($modelData);
        
        // Add warning if model is empty/incomplete
        if (!$hasAnyTests) {
            $tests[] = $this->generateIncompleteModelWarning($modelData);
        }

        // Generate fillable tests
        if (!empty($modelData['fillable'])) {
            $tests[] = $this->generateFillableTest($modelData);
        }

        // Generate hidden tests
        if (!empty($modelData['hidden'])) {
            $tests[] = $this->generateHiddenTest($modelData);
        }

        // Generate cast tests
        if (!empty($modelData['casts'])) {
            $tests = array_merge($tests, $this->generateCastTests($modelData));
        }

        // Generate relation tests
        if (!empty($modelData['relations'])) {
            $tests = array_merge($tests, $this->generateRelationTests($modelData));
        }

        // Generate scope tests
        $scopeMethods = $this->findScopeMethods($modelData);
        if (!empty($scopeMethods)) {
            $tests = array_merge($tests, $this->generateScopeTests($modelData, $scopeMethods));
        }

        return $this->buildTestClass($modelData, $tests);
    }

    public function getTestPath(array $modelData): string
    {
        $modelName = $modelData['name'];
        $namespace = $modelData['namespace'] ?? '';
        
        // Extract sub-path from namespace (e.g., App\Models\User\Profile -> User)
        // This preserves directory structure in tests
        $subPath = $this->extractSubPathFromNamespace($namespace);
        
        if ($subPath) {
            return "tests/Unit/Models/{$subPath}/{$modelName}Test.php";
        }
        
        return "tests/Unit/Models/{$modelName}Test.php";
    }
    
    /**
     * Extract subdirectory path from namespace
     * App\Models\User\Profile -> User
     * App\Domain\Shop\Product -> Shop
     */
    private function extractSubPathFromNamespace(string $namespace): string
    {
        // Remove App\ or similar prefix
        $parts = explode('\\', $namespace);
        
        // Find Models, Domain, or similar base directory
        $baseIndex = -1;
        foreach ($parts as $i => $part) {
            if (in_array($part, ['Models', 'Domain', 'Entities'])) {
                $baseIndex = $i;
                break;
            }
        }
        
        // Get everything after base directory
        if ($baseIndex !== -1 && isset($parts[$baseIndex + 1])) {
            return implode('/', array_slice($parts, $baseIndex + 1));
        }
        
        return '';
    }

    private function buildTestClass(array $modelData, array $tests): string
    {
        $modelName = $modelData['name'];
        $modelNamespace = $modelData['namespace'];
        $fullModelClass = "{$modelNamespace}\\{$modelName}";

        $useStatements = $this->buildUseStatements($modelData);
        $testMethods = implode("\n\n", $tests);

        return <<<PHP
<?php

namespace {$this->testNamespace};

{$useStatements}

class {$modelName}Test extends TestCase
{
{$testMethods}
}
PHP;
    }

    private function buildUseStatements(array $modelData): string
    {
        $modelName = $modelData['name'];
        $modelNamespace = $modelData['namespace'];

        $uses = [
            "{$modelNamespace}\\{$modelName}",
            'Tests\\TestCase',
        ];

        // Add related models for relation tests
        foreach ($modelData['relations'] as $relation) {
            if (!empty($relation['model'])) {
                $uses[] = "{$modelNamespace}\\{$relation['model']}";
            }
        }

        $uses = array_unique($uses);
        sort($uses);

        return implode("\n", array_map(fn($use) => "use {$use};", $uses));
    }

    private function generateFactoryTest(array $modelData): string
    {
        $modelName = $modelData['name'];

        return <<<PHP
    /** @test */
    public function it_can_be_created_using_factory(): void
    {
        \$model = {$modelName}::factory()->create();

        \$this->assertInstanceOf({$modelName}::class, \$model);
        \$this->assertDatabaseHas('{$this->getTableName($modelName)}', [
            'id' => \$model->id,
        ]);
    }
PHP;
    }
    
    private function generateIncompleteModelWarning(array $modelData): string
    {
        $modelName = $modelData['name'];

        return <<<PHP
    /**
     * TODO: This model appears to be incomplete.
     * 
     * Please add the following to your model:
     * - \$fillable array for mass assignment
     * - \$hidden array for sensitive attributes  
     * - \$casts array for attribute casting
     * - Relationship methods (hasMany, belongsTo, etc.)
     * 
     * Once added, regenerate tests to get comprehensive coverage.
     */
    public function test_model_needs_configuration(): void
    {
        \$this->markTestIncomplete(
            '{$modelName} model needs fillable, relations, and other configurations.'
        );
    }
PHP;
    }

    private function generateFillableTest(array $modelData): string
    {
        $modelName = $modelData['name'];
        $fillable = $modelData['fillable'];
        $fillableList = "'" . implode("', '", $fillable) . "'";

        return <<<PHP
    /** @test */
    public function it_has_correct_fillable_attributes(): void
    {
        \$model = new {$modelName}();
        \$fillable = \$model->getFillable();

        \$expected = [{$fillableList}];

        foreach (\$expected as \$attribute) {
            \$this->assertContains(\$attribute, \$fillable);
        }
    }
PHP;
    }

    private function generateHiddenTest(array $modelData): string
    {
        $modelName = $modelData['name'];
        $hidden = $modelData['hidden'];
        $hiddenList = "'" . implode("', '", $hidden) . "'";

        return <<<PHP
    /** @test */
    public function it_hides_sensitive_attributes(): void
    {
        \$model = {$modelName}::factory()->create();
        \$array = \$model->toArray();

        \$hiddenAttributes = [{$hiddenList}];

        foreach (\$hiddenAttributes as \$attribute) {
            \$this->assertArrayNotHasKey(\$attribute, \$array);
        }
    }
PHP;
    }

    private function generateCastTests(array $modelData): array
    {
        $tests = [];
        $modelName = $modelData['name'];

        foreach ($modelData['casts'] as $attribute => $castType) {
            $testName = $this->camelToSnake($attribute);
            // Remove :precision from cast type for method name (e.g., decimal:2 -> decimal)
            $cleanCastType = preg_replace('/:.+$/', '', $castType);
            $assertMethod = $this->getAssertMethodForCast($castType, $attribute);

            $tests[] = <<<PHP
    /** @test */
    public function it_casts_{$testName}_to_{$cleanCastType}(): void
    {
        \$model = {$modelName}::factory()->create();

        {$assertMethod}
    }
PHP;
        }

        return $tests;
    }

    private function generateRelationTests(array $modelData): array
    {
        $tests = [];
        $modelName = $modelData['name'];

        foreach ($modelData['relations'] as $relationName => $relationInfo) {
            $relationType = $relationInfo['type'];
            $relatedModel = $relationInfo['model'] ?? 'Related';

            $test = match ($relationType) {
                'hasMany' => $this->generateHasManyTest($modelName, $relationName, $relatedModel),
                'hasOne' => $this->generateHasOneTest($modelName, $relationName, $relatedModel),
                'belongsTo' => $this->generateBelongsToTest($modelName, $relationName, $relatedModel),
                'belongsToMany' => $this->generateBelongsToManyTest($modelName, $relationName, $relatedModel),
                default => null,
            };

            if ($test) {
                $tests[] = $test;
            }
        }

        return $tests;
    }

    private function generateHasManyTest(string $modelName, string $relationName, string $relatedModel): string
    {
        $foreignKey = $this->getForeignKey($modelName);

        return <<<PHP
    /** @test */
    public function it_has_many_{$relationName}(): void
    {
        \$model = {$modelName}::factory()->create();
        \${$relationName} = {$relatedModel}::factory()->count(3)->create([
            '{$foreignKey}' => \$model->id,
        ]);

        \$this->assertCount(3, \$model->{$relationName});
        \$this->assertInstanceOf({$relatedModel}::class, \$model->{$relationName}->first());
    }
PHP;
    }

    private function generateHasOneTest(string $modelName, string $relationName, string $relatedModel): string
    {
        $foreignKey = $this->getForeignKey($modelName);

        return <<<PHP
    /** @test */
    public function it_has_one_{$relationName}(): void
    {
        \$model = {$modelName}::factory()->create();
        \${$relationName} = {$relatedModel}::factory()->create([
            '{$foreignKey}' => \$model->id,
        ]);

        \$this->assertInstanceOf({$relatedModel}::class, \$model->{$relationName});
        \$this->assertEquals(\${$relationName}->id, \$model->{$relationName}->id);
    }
PHP;
    }

    private function generateBelongsToTest(string $modelName, string $relationName, string $relatedModel): string
    {
        $foreignKey = $this->getForeignKey($relatedModel);

        return <<<PHP
    /** @test */
    public function it_belongs_to_{$relationName}(): void
    {
        \${$relationName} = {$relatedModel}::factory()->create();
        \$model = {$modelName}::factory()->create([
            '{$foreignKey}' => \${$relationName}->id,
        ]);

        \$this->assertInstanceOf({$relatedModel}::class, \$model->{$relationName});
        \$this->assertEquals(\${$relationName}->id, \$model->{$relationName}->id);
    }
PHP;
    }

    private function generateBelongsToManyTest(string $modelName, string $relationName, string $relatedModel): string
    {
        return <<<PHP
    /** @test */
    public function it_belongs_to_many_{$relationName}(): void
    {
        \$model = {$modelName}::factory()->create();
        \${$relationName} = {$relatedModel}::factory()->count(3)->create();

        \$model->{$relationName}()->attach(\${$relationName}->pluck('id'));

        \$this->assertCount(3, \$model->{$relationName});
        \$this->assertInstanceOf({$relatedModel}::class, \$model->{$relationName}->first());
    }
PHP;
    }

    private function generateScopeTests(array $modelData, array $scopeMethods): array
    {
        $tests = [];
        $modelName = $modelData['name'];

        foreach ($scopeMethods as $scopeMethod) {
            // Remove 'scope' prefix and convert to snake_case for test name
            $scopeName = lcfirst(substr($scopeMethod, 5));
            $testName = $this->camelToSnake($scopeName);

            $tests[] = <<<PHP
    /** @test */
    public function it_can_filter_by_{$testName}_scope(): void
    {
        // Create models with varying attributes
        {$modelName}::factory()->count(5)->create();

        \$filtered = {$modelName}::{$scopeName}()->get();

        \$this->assertIsIterable(\$filtered);
        // Add specific assertions based on your scope logic
    }
PHP;
        }

        return $tests;
    }

    private function findScopeMethods(array $modelData): array
    {
        $scopes = [];

        foreach ($modelData['methods'] ?? [] as $method) {
            if (str_starts_with($method, 'scope') && $method !== 'scopeQuery') {
                $scopes[] = $method;
            }
        }

        return $scopes;
    }

    private function getTableName(string $modelName): string
    {
        // Convert PascalCase to snake_case and pluralize
        $snake = $this->camelToSnake($modelName);
        return $this->pluralize($snake);
    }

    private function getForeignKey(string $modelName): string
    {
        return $this->camelToSnake($modelName) . '_id';
    }

    private function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    private function pluralize(string $word): string
    {
        // Simple pluralization - can be enhanced later
        if (str_ends_with($word, 's')) {
            return $word . 'es';
        }
        if (str_ends_with($word, 'y')) {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    private function getAssertMethodForCast(string $castType, string $attribute): string
    {
        return match ($castType) {
            'datetime', 'date' => "\$this->assertInstanceOf(\\Illuminate\\Support\\Carbon::class, \$model->{$attribute});",
            'boolean', 'bool' => "\$this->assertIsBool(\$model->{$attribute});",
            'integer', 'int' => "\$this->assertIsInt(\$model->{$attribute});",
            'float', 'double', 'decimal' => "\$this->assertIsFloat(\$model->{$attribute});",
            'array', 'json' => "\$this->assertIsArray(\$model->{$attribute});",
            'collection' => "\$this->assertInstanceOf(\\Illuminate\\Support\\Collection::class, \$model->{$attribute});",
            'object' => "\$this->assertIsObject(\$model->{$attribute});",
            default => "\$this->assertNotNull(\$model->{$attribute});",
        };
    }
}
