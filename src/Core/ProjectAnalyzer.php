<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Core;

use Bberkaysari\LaravelTestGenerator\Scanner\ProjectScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ModelScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\MigrationScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ControllerScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ServiceScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\EventScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\MiddlewareScanner;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\FormRequestScanner;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\MethodAnalyzer;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\DependencyAnalyzer;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\QueryAnalyzer;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\RouteAnalyzer;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\CoverageAnalyzer;
use Bberkaysari\LaravelTestGenerator\Core\Performance\PerformanceMonitor;
use Bberkaysari\LaravelTestGenerator\Core\Cache\CacheManager;
use Bberkaysari\LaravelTestGenerator\Core\Progress\ProgressTracker;

/**
 * Main analyzer that coordinates all scanners for large projects
 */
class ProjectAnalyzer
{
    private string $projectPath;
    private PerformanceMonitor $performance;
    private CacheManager $cache;
    private bool $verbose;
    private array $results = [];
    
    public function __construct(
        string $projectPath,
        ?CacheManager $cache = null,
        bool $verbose = true
    ) {
        $this->projectPath = $projectPath;
        $this->performance = new PerformanceMonitor();
        $this->cache = $cache ?? new CacheManager($projectPath . '/.test-gen-cache', false);
        $this->verbose = $verbose;
    }
    
    /**
     * Analyze entire project
     */
    public function analyze(): array
    {
        if ($this->verbose) {
            echo "🔍 Analyzing Laravel project...\n";
            echo "📂 Path: {$this->projectPath}\n\n";
        }
        
        $this->performance->start('total');
        
        // Step 1: Project metadata
        $this->performance->start('project_scan');
        $this->results['project'] = $this->scanProject();
        $this->performance->stop('project_scan');
        
        // Step 2: Models
        $this->performance->start('model_scan');
        $this->results['models'] = $this->scanModels();
        $this->performance->stop('model_scan');
        
        // Step 3: Migrations
        $this->performance->start('migration_scan');
        $this->results['migrations'] = $this->scanMigrations();
        $this->performance->stop('migration_scan');
        
        // Step 4: Controllers
        $this->performance->start('controller_scan');
        $this->results['controllers'] = $this->scanControllers();
        $this->performance->stop('controller_scan');
        
        // Step 5: Services & Repositories
        $this->performance->start('service_scan');
        $this->results['services'] = $this->scanServices();
        $this->performance->stop('service_scan');
        
        // Step 6: Events, Listeners, Jobs
        $this->performance->start('event_scan');
        $this->results['events'] = $this->scanEvents();
        $this->performance->stop('event_scan');
        
        // Step 7: Middleware
        $this->performance->start('middleware_scan');
        $this->results['middleware'] = $this->scanMiddleware();
        $this->performance->stop('middleware_scan');
        
        // Step 8: Deep analysis (method calls, dependencies, queries)
        $this->performance->start('deep_analysis');
        $this->results['deep_analysis'] = $this->performDeepAnalysis();
        $this->performance->stop('deep_analysis');
        
        // Step 9: Routes
        $this->performance->start('route_analysis');
        $this->results['routes'] = $this->analyzeRoutes();
        $this->performance->stop('route_analysis');
        
        // Step 10: Form Requests
        $this->performance->start('form_request_scan');
        $this->results['form_requests'] = $this->scanFormRequests();
        $this->performance->stop('form_request_scan');
        
        // Step 11: Coverage Analysis
        $this->performance->start('coverage_analysis');
        $this->results['coverage'] = $this->analyzeCoverage();
        $this->performance->stop('coverage_analysis');
        
        $this->performance->stop('total');
        
        // Add statistics
        $this->results['statistics'] = $this->generateStatistics();
        $this->results['performance'] = $this->performance->getSummary();
        
        if ($this->verbose) {
            $this->displaySummary();
        }
        
        return $this->results;
    }
    
    /**
     * Get performance monitor (for testing)
     */
    public function getPerformanceMonitor(): PerformanceMonitor
    {
        return $this->performance;
    }
    
    /**
     * Get cache manager
     */
    public function getCacheManager(): CacheManager
    {
        return $this->cache;
    }
    
    /**
     * Get statistics (for testing)
     */
    public function getStatistics(): array
    {
        return $this->generateStatistics();
    }
    
    /**
     * Scan project metadata
     */
    private function scanProject(): array
    {
        $cacheKey = 'project_' . md5($this->projectPath);
        
        if ($cached = $this->cache->get($cacheKey)) {
            if ($this->verbose) {
                echo "   ✅ Project metadata (cached)\n";
            }
            return $cached;
        }
        
        $scanner = new ProjectScanner($this->projectPath);
        $result = $scanner->scan();
        
        $this->cache->set($cacheKey, $result, $this->projectPath . '/composer.json');
        
        if ($this->verbose) {
            echo "   ✅ Project metadata\n";
        }
        
        return $result;
    }
    
    /**
     * Scan models with progress tracking
     */
    private function scanModels(): array
    {
        $scanner = new ModelScanner($this->projectPath);
        $models = $scanner->scan();
        
        if ($this->verbose) {
            echo "   ✅ Scanned " . count($models) . " model(s)\n";
        }
        
        return $models;
    }
    
    /**
     * Scan migrations
     */
    private function scanMigrations(): array
    {
        $scanner = new MigrationScanner($this->projectPath);
        $migrations = $scanner->scan();
        
        if ($this->verbose) {
            echo "   ✅ Scanned " . count($migrations) . " table(s)\n";
        }
        
        return $migrations;
    }
    
    /**
     * Scan controllers
     */
    private function scanControllers(): array
    {
        $scanner = new ControllerScanner($this->projectPath);
        $controllers = $scanner->scan();
        
        if ($this->verbose) {
            echo "   ✅ Scanned " . count($controllers) . " controller(s)\n";
        }
        
        return $controllers;
    }
    
    /**
     * Scan services and repositories
     */
    private function scanServices(): array
    {
        $scanner = new ServiceScanner($this->projectPath);
        $result = $scanner->scan();
        
        if ($this->verbose) {
            $total = $result['statistics']['total_services'] + $result['statistics']['total_repositories'];
            echo "   ✅ Scanned {$total} service/repository class(es)\n";
        }
        
        return $result;
    }
    
    /**
     * Scan events, listeners, jobs
     */
    private function scanEvents(): array
    {
        $scanner = new EventScanner($this->projectPath);
        $result = $scanner->scan();
        
        if ($this->verbose) {
            $total = $result['statistics']['total_events'] + 
                    $result['statistics']['total_listeners'] + 
                    $result['statistics']['total_jobs'];
            echo "   ✅ Scanned {$total} event/listener/job(s)\n";
        }
        
        return $result;
    }
    
    /**
     * Scan middleware
     */
    private function scanMiddleware(): array
    {
        $scanner = new MiddlewareScanner($this->projectPath);
        $result = $scanner->scan();
        
        if ($this->verbose) {
            echo "   ✅ Scanned " . $result['statistics']['total_middleware'] . " middleware class(es)\n";
        }
        
        return $result;
    }
    
    /**
     * Perform deep analysis on scanned components
     */
    private function performDeepAnalysis(): array
    {
        $analysis = [
            'method_analysis' => [],
            'dependency_graph' => [],
            'query_analysis' => [],
        ];
        
        // Analyze service methods
        if (!empty($this->results['services'])) {
            $methodAnalyzer = new MethodAnalyzer();
            $depAnalyzer = new DependencyAnalyzer();
            $queryAnalyzer = new QueryAnalyzer();
            
            // Analyze all services
            $allClasses = array_merge(
                $this->results['services']['services'] ?? [],
                $this->results['services']['repositories'] ?? []
            );
            
            foreach ($allClasses as $class) {
                // Method analysis would need AST access - skip for now
                // In production, this would parse method bodies
            }
            
            // Dependency graph
            $analysis['dependency_graph'] = $depAnalyzer->analyzeDependencyGraph($allClasses);
        }
        
        if ($this->verbose) {
            echo "   ✅ Deep analysis completed\n";
        }
        
        return $analysis;
    }
    
    /**
     * Analyze routes
     */
    private function analyzeRoutes(): array
    {
        $analyzer = new RouteAnalyzer($this->projectPath);
        $result = $analyzer->analyze();
        
        if ($this->verbose) {
            echo "   ✅ Analyzed " . $result['statistics']['total_routes'] . " route(s)\n";
        }
        
        return $result;
    }
    
    /**
     * Scan Form Requests
     */
    private function scanFormRequests(): array
    {
        $scanner = new FormRequestScanner($this->projectPath);
        $result = $scanner->scan();
        
        if ($this->verbose) {
            echo "   ✅ Scanned " . $result['statistics']['total_requests'] . " form request(s)\n";
        }
        
        return $result;
    }
    
    /**
     * Analyze test coverage
     */
    private function analyzeCoverage(): array
    {
        $testDir = $this->projectPath . '/tests';
        $analyzer = new CoverageAnalyzer($testDir);
        
        $coverage = $analyzer->analyze($this->results);
        $gaps = $analyzer->getCoverageGaps($coverage);
        
        if ($this->verbose) {
            $overallPercent = $coverage['overall']['overall_coverage_percent'] ?? 0;
            echo "   ✅ Coverage: {$overallPercent}% ({$coverage['overall']['tested_components']}/{$coverage['overall']['total_components']} components)\n";
        }
        
        return [
            'analysis' => $coverage,
            'gaps' => $gaps,
        ];
    }
    
    /**
     * Generate project statistics
     */
    private function generateStatistics(): array
    {
        $models = $this->results['models'] ?? [];
        $migrations = $this->results['migrations'] ?? [];
        $controllers = $this->results['controllers'] ?? [];
        $services = $this->results['services'] ?? [];
        $events = $this->results['events'] ?? [];
        $middleware = $this->results['middleware'] ?? [];
        
        // Count relationships
        $relationshipCount = 0;
        foreach ($models as $model) {
            $relationshipCount += count($model['relations'] ?? []);
        }
        
        // Count controller methods
        $methodCount = 0;
        $apiControllers = 0;
        $resourceControllers = 0;
        
        foreach ($controllers as $controller) {
            $methodCount += count($controller['methods'] ?? []);
            if ($controller['is_api'] ?? false) {
                $apiControllers++;
            }
            if ($controller['resource_type'] ?? null) {
                $resourceControllers++;
            }
        }
        
        // Count services/repos
        $servicesData = $services['statistics'] ?? [];
        $serviceCount = ($servicesData['total_services'] ?? 0);
        $repositoryCount = ($servicesData['total_repositories'] ?? 0);
        
        // Count events/jobs
        $eventCount = ($events['statistics']['total_events'] ?? 0) + 
                     ($events['statistics']['total_listeners'] ?? 0) + 
                     ($events['statistics']['total_jobs'] ?? 0);
        
        // Estimate test count
        $estimatedTests = 
            (count($models) * 8) +     // ~8 tests per model
            ($methodCount * 3) +       // ~3 tests per controller method
            (count($migrations) * 2) + // ~2 tests per migration
            ($serviceCount * 10) +     // ~10 tests per service (complex logic)
            ($repositoryCount * 8) +   // ~8 tests per repository
            ($eventCount * 2);         // ~2 tests per event/listener/job
        
        return [
            'models' => count($models),
            'migrations' => count($migrations),
            'controllers' => count($controllers),
            'services' => $serviceCount,
            'repositories' => $repositoryCount,
            'events_listeners_jobs' => $eventCount,
            'middleware' => $middleware['statistics']['total_middleware'] ?? 0,
            'relationships' => $relationshipCount,
            'controller_methods' => $methodCount,
            'api_controllers' => $apiControllers,
            'resource_controllers' => $resourceControllers,
            'estimated_tests' => $estimatedTests,
        ];
    }
    
    /**
     * Display analysis summary
     */
    private function displaySummary(): void
    {
        $stats = $this->results['statistics'];
        $perf = $this->performance;
        
        echo "\n" . str_repeat("═", 60) . "\n";
        echo "📊 ANALYSIS COMPLETE\n";
        echo str_repeat("═", 60) . "\n\n";
        
        echo "Project Components:\n";
        echo "   • Models: {$stats['models']}\n";
        echo "   • Migrations: {$stats['migrations']}\n";
        echo "   • Controllers: {$stats['controllers']}\n";
        echo "   • Relationships: {$stats['relationships']}\n";
        echo "   • Controller Methods: {$stats['controller_methods']}\n";
        echo "   • API Controllers: {$stats['api_controllers']}\n";
        echo "   • Resource Controllers: {$stats['resource_controllers']}\n";
        echo "\n";
        
        echo "Test Generation:\n";
        echo "   • Estimated tests: {$stats['estimated_tests']}\n";
        echo "\n";
        
        echo "Performance:\n";
        echo "   • Time: " . $perf->formatTime($perf->getTotalTime()) . "\n";
        echo "   • Memory: " . $perf->formatBytes($perf->getTotalMemory()) . "\n";
        echo "   • Peak Memory: " . $perf->formatBytes($perf->getPeakMemory()) . "\n";
        echo "\n";
        
        // Cache stats
        $cacheStats = $this->cache->getStats();
        if ($cacheStats['enabled']) {
            echo "Cache:\n";
            echo "   • Files: {$cacheStats['files']}\n";
            echo "   • Size: {$cacheStats['total_size_formatted']}\n";
            echo "\n";
        }
    }
    
    /**
     * Get results
     */
    public function getResults(): array
    {
        return $this->results;
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache->clear();
    }
}
