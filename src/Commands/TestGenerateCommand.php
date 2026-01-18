<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Commands;

use Illuminate\Console\Command;
use Bberkaysari\LaravelTestGenerator\Core\ProjectAnalyzer;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ModelTestGenerator;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ControllerTestGenerator;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ServiceTestGenerator;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\RouteAnalyzer;

/**
 * Laravel Artisan command for generating tests
 */
class TestGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:generate
                            {--analyze : Only analyze project without generating tests}
                            {--type=all : Test type (model, controller, service, all)}
                            {--output=tests : Output directory}
                            {--force : Overwrite existing tests}
                            {--no-cache : Disable caching}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate comprehensive tests for Laravel project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $projectPath = base_path();
        $testType = $this->option('type');
        $outputDir = $this->option('output');
        $force = $this->option('force');
        $noCache = $this->option('no-cache');
        $analyzeOnly = $this->option('analyze');

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║         Laravel Test Generator v1.4                      ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info('');

        try {
            // Analyze project
            $this->info('🔍 Analyzing project...');
            $this->newLine();

            $analyzer = new ProjectAnalyzer($projectPath, null, false);

            if ($noCache) {
                $analyzer->getCacheManager()->clear();
                $this->info('   Cache cleared');
            }

            $results = $analyzer->analyze();
            $stats = $results['statistics'];

            // Show analysis results
            $this->table(
                ['Component', 'Count'],
                [
                    ['Models', $stats['models']],
                    ['Controllers', $stats['controllers']],
                    ['Migrations', $stats['migrations']],
                    ['Services', $stats['services']],
                    ['Repositories', $stats['repositories']],
                    ['Routes', $stats['routes'] ?? 0],
                    ['Estimated Tests', $stats['estimated_tests']],
                ]
            );

            // If analyze only, stop here
            if ($analyzeOnly) {
                $this->newLine();
                $this->info('✅ Analysis complete! Use without --analyze to generate tests.');
                return Command::SUCCESS;
            }

            // Generate tests
            $this->newLine();
            $this->info('📝 Generating tests...');
            $this->newLine();

            // Ensure base test files exist
            $this->ensureBaseTestFiles($projectPath);

            $generated = 0;

            if (in_array($testType, ['model', 'all'])) {
                $count = $this->generateModelTests($results, $projectPath, $outputDir, $force);
                $generated += $count;
                $this->info("   ✓ Generated {$count} model test(s)");
            }

            if (in_array($testType, ['controller', 'all'])) {
                $count = $this->generateControllerTests($results, $projectPath, $outputDir, $force);
                $generated += $count;
                $this->info("   ✓ Generated {$count} controller test(s)");
            }

            if (in_array($testType, ['service', 'all'])) {
                $count = $this->generateServiceTests($results, $projectPath, $outputDir, $force);
                $generated += $count;
                if ($count > 0) {
                    $this->info("   ✓ Generated {$count} service/repository test(s)");
                }
            }

            // Performance summary
            $perf = $results['performance'];
            $this->newLine();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Tests Generated', $generated],
                    ['Execution Time', $perf['total_time']],
                    ['Memory Used', $perf['peak_memory']],
                ]
            );

            $this->newLine();
            $this->info('✅ Test generation completed!');
            $this->info("   Run: php artisan test");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('');
            $this->error('❌ Test generation failed: ' . $e->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function generateModelTests(array $results, string $projectPath, string $outputDir, bool $force): int
    {
        $generator = new ModelTestGenerator();
        $generated = 0;

        $models = $results['models'] ?? [];
        $migrations = $results['migrations'] ?? [];

        foreach ($models as $model) {
            $modelName = $model['name'];

            // Find matching migration
            $migration = $this->findMatchingMigration($model, $migrations);

            // Generate test code
            $testCode = $generator->generate($model, $migration);

            // Write to file
            $testPath = "{$projectPath}/{$outputDir}/Unit/{$modelName}Test.php";

            if (!$force && file_exists($testPath)) {
                continue;
            }

            $this->ensureDirectory(dirname($testPath));
            file_put_contents($testPath, $testCode);

            $generated++;
        }

        return $generated;
    }

    private function generateControllerTests(array $results, string $projectPath, string $outputDir, bool $force): int
    {
        // Pass RouteAnalyzer to generator
        $routeAnalyzer = null;
        if (!empty($results['routes'])) {
            $routeAnalyzer = new RouteAnalyzer($projectPath);
            $routeAnalyzer->analyze();
        }

        $generator = new ControllerTestGenerator($routeAnalyzer);
        $generated = 0;

        $controllers = $results['controllers'] ?? [];

        foreach ($controllers as $controller) {
            $controllerName = $controller['name'];
            $testName = str_replace('Controller', 'ControllerTest', $controllerName);

            // Generate test code
            $testCode = $generator->generate($controller);

            // Determine path (Feature/Api or Feature)
            $isApi = $controller['is_api'] ?? false;
            $subDir = $isApi ? 'Feature/Api' : 'Feature';
            $testPath = "{$projectPath}/{$outputDir}/{$subDir}/{$testName}.php";

            if (!$force && file_exists($testPath)) {
                continue;
            }

            $this->ensureDirectory(dirname($testPath));
            file_put_contents($testPath, $testCode);

            $generated++;
        }

        return $generated;
    }

    private function generateServiceTests(array $results, string $projectPath, string $outputDir, bool $force): int
    {
        $generator = new ServiceTestGenerator();
        $generated = 0;

        $servicesData = $results['services'] ?? [];
        $services = array_merge(
            $servicesData['services'] ?? [],
            $servicesData['repositories'] ?? []
        );

        if (empty($services)) {
            return 0;
        }

        foreach ($services as $service) {
            $serviceName = $service['name'] ?? 'Unknown';
            if ($serviceName === 'Unknown') {
                continue;
            }

            // Generate test code
            $testCode = $generator->generate($service);

            // Get path
            $testPath = "{$projectPath}/{$outputDir}/Unit/" . $generator->getTestPath($service);

            if (!$force && file_exists($testPath)) {
                continue;
            }

            $this->ensureDirectory(dirname($testPath));
            file_put_contents($testPath, $testCode);

            $generated++;
        }

        return $generated;
    }

    private function findMatchingMigration(array $model, array $migrations): ?array
    {
        $tableName = strtolower($this->pluralize($model['name']));

        foreach ($migrations as $migration) {
            if (($migration['table'] ?? '') === $tableName) {
                return $migration;
            }
        }

        return null;
    }

    private function pluralize(string $word): string
    {
        if (str_ends_with($word, 'y')) {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function ensureBaseTestFiles(string $projectPath): void
    {
        $testsDir = "{$projectPath}/tests";

        // Create TestCase.php if it doesn't exist
        $testCasePath = "{$testsDir}/TestCase.php";
        if (!file_exists($testCasePath)) {
            $this->ensureDirectory($testsDir);
            file_put_contents($testCasePath, $this->getTestCaseTemplate());
            $this->info('   ✓ Created tests/TestCase.php');
        }

        // Create CreatesApplication.php if it doesn't exist
        $createsAppPath = "{$testsDir}/CreatesApplication.php";
        if (!file_exists($createsAppPath)) {
            file_put_contents($createsAppPath, $this->getCreatesApplicationTemplate());
            $this->info('   ✓ Created tests/CreatesApplication.php');
        }
    }

    private function getTestCaseTemplate(): string
    {
        return <<<'PHP'
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}

PHP;
    }

    private function getCreatesApplicationTemplate(): string
    {
        return <<<'PHP'
<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}

PHP;
    }
}
