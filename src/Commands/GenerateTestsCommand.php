<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Commands;

use Bberkaysari\LaravelTestGenerator\Core\ProjectAnalyzer;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ModelTestGenerator;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ControllerTestGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Artisan-style command for generating tests
 */
class GenerateTestsCommand extends Command
{
    protected static $defaultName = 'test:generate';
    
    protected function configure(): void
    {
        $this
            ->setName('test:generate')
            ->setDescription('Generate comprehensive tests for Laravel project')
            ->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'Laravel project path', getcwd())
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Test type (model, controller, all)', 'all')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory', 'tests')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing tests')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching')
            ->setHelp(<<<'HELP'
Generate comprehensive tests for your Laravel project.

<info>Usage:</info>
  php artisan test:generate [options]
  
<info>Examples:</info>
  php artisan test:generate                    # Generate all tests
  php artisan test:generate --type=model       # Only model tests
  php artisan test:generate --type=controller  # Only controller tests
  php artisan test:generate --force            # Overwrite existing
  php artisan test:generate --no-cache         # Disable caching

<info>Options:</info>
  -p, --path=PATH       Laravel project path (default: current directory)
  -t, --type=TYPE       Test type: model, controller, all (default: all)
  -o, --output=DIR      Output directory (default: tests)
  -f, --force           Overwrite existing test files
      --no-cache        Disable caching for fresh analysis
HELP
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $projectPath = $input->getOption('path');
        $testType = $input->getOption('type');
        $outputDir = $input->getOption('output');
        $force = $input->getOption('force');
        $noCache = $input->getOption('no-cache');
        
        // Validate project path
        if (!is_dir($projectPath)) {
            $io->error("Project path does not exist: {$projectPath}");
            return Command::FAILURE;
        }
        
        $io->title('Laravel Test Generator');
        $io->info("Project: {$projectPath}");
        $io->info("Type: {$testType}");
        $io->newLine();
        
        try {
            // Analyze project
            $io->section('Analyzing Project');
            $analyzer = new ProjectAnalyzer($projectPath, null, false);
            
            if ($noCache) {
                $analyzer->getCacheManager()->clear();
                $io->info('Cache cleared');
            }
            
            $results = $analyzer->analyze();
            
            $stats = $results['statistics'];
            $io->success([
                "Found {$stats['models']} models",
                "Found {$stats['controllers']} controllers",
                "Found {$stats['migrations']} migrations",
                "Estimated {$stats['estimated_tests']} tests",
            ]);
            
            // Generate tests
            $io->section('Generating Tests');
            $generated = 0;
            
            if (in_array($testType, ['model', 'all'])) {
                $generated += $this->generateModelTests($results, $projectPath, $outputDir, $force, $io);
            }
            
            if (in_array($testType, ['controller', 'all'])) {
                $generated += $this->generateControllerTests($results, $projectPath, $outputDir, $force, $io);
            }
            
            // Performance summary
            $perf = $results['performance'];
            $io->newLine();
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Tests Generated', $generated],
                    ['Execution Time', $perf['total_time']],
                    ['Memory Used', $perf['peak_memory']],
                ]
            );
            
            $io->success('Test generation completed!');
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Test generation failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->block($e->getTraceAsString(), null, 'fg=white;bg=red', ' ', true);
            }
            return Command::FAILURE;
        }
    }
    
    private function generateModelTests(array $results, string $projectPath, string $outputDir, bool $force, SymfonyStyle $io): int
    {
        $generator = new ModelTestGenerator();
        $generated = 0;
        
        $models = $results['models'] ?? [];
        $migrations = $results['migrations'] ?? [];
        
        $progressBar = $io->createProgressBar(count($models));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        
        foreach ($models as $model) {
            $modelName = $model['name'];
            $progressBar->setMessage("Generating {$modelName}Test...");
            
            // Find matching migration
            $migration = $this->findMatchingMigration($model, $migrations);
            
            // Generate test code
            $testCode = $generator->generate($model, $migration);
            
            // Write to file
            $testPath = "{$projectPath}/{$outputDir}/Unit/{$modelName}Test.php";
            
            if (!$force && file_exists($testPath)) {
                $progressBar->setMessage("{$modelName}Test already exists (use --force)");
                $progressBar->advance();
                continue;
            }
            
            $this->ensureDirectory(dirname($testPath));
            file_put_contents($testPath, $testCode);
            
            $generated++;
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $io->newLine(2);
        
        return $generated;
    }
    
    private function generateControllerTests(array $results, string $projectPath, string $outputDir, bool $force, SymfonyStyle $io): int
    {
        // Pass RouteAnalyzer to generator
        $routeAnalyzer = null;
        if (!empty($results['routes'])) {
            $routeAnalyzer = new \Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\RouteAnalyzer($projectPath);
            $routeAnalyzer->analyze(); // Analyze routes before passing to generator
        }
        
        $generator = new ControllerTestGenerator($routeAnalyzer);
        $generated = 0;
        
        $controllers = $results['controllers'] ?? [];
        
        if (empty($controllers)) {
            $io->note('No controllers found to generate tests for');
            return 0;
        }
        
        $progressBar = $io->createProgressBar(count($controllers));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        
        foreach ($controllers as $controller) {
            $controllerName = $controller['name'];
            $testName = str_replace('Controller', 'ControllerTest', $controllerName);
            $progressBar->setMessage("Generating {$testName}...");
            
            // Generate test code
            $testCode = $generator->generate($controller);
            
            // Determine path (Feature/Api or Feature)
            $isApi = $controller['is_api'] ?? false;
            $subDir = $isApi ? 'Feature/Api' : 'Feature';
            $testPath = "{$projectPath}/{$outputDir}/{$subDir}/{$testName}.php";
            
            if (!$force && file_exists($testPath)) {
                $progressBar->setMessage("{$testName} already exists (use --force)");
                $progressBar->advance();
                continue;
            }
            
            $this->ensureDirectory(dirname($testPath));
            file_put_contents($testPath, $testCode);
            
            $generated++;
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $io->newLine(2);
        
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
        if (substr($word, -1) === 'y') {
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
}
