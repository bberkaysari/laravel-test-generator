<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers;

use Symfony\Component\Finder\Finder;

/**
 * CoverageAnalyzer - Identifies test coverage gaps
 * 
 * Analyzes:
 * - Which models have tests vs which don't
 * - Which controller methods have tests vs which don't
 * - Which services/repositories have tests vs which don't
 * - Overall coverage percentage
 * - Missing test files
 * - Insufficient test coverage (1 test when 5+ needed)
 */
class CoverageAnalyzer
{
    private string $testDirectory;
    private array $existingTests = [];

    public function __construct(string $testDirectory)
    {
        $this->testDirectory = rtrim($testDirectory, '/');
    }

    /**
     * Analyze test coverage for all components
     */
    public function analyze(array $projectData): array
    {
        // Scan existing tests
        $this->scanExistingTests();

        $results = [
            'models' => $this->analyzeModelCoverage($projectData['models'] ?? []),
            'controllers' => $this->analyzeControllerCoverage($projectData['controllers'] ?? []),
            'services' => $this->analyzeServiceCoverage($projectData['services'] ?? []),
            'overall' => [],
        ];

        // Calculate overall statistics
        $results['overall'] = $this->calculateOverallCoverage($results);

        return $results;
    }

    /**
     * Scan existing test files
     */
    private function scanExistingTests(): void
    {
        $this->existingTests = [
            'models' => [],
            'controllers' => [],
            'services' => [],
            'feature' => [],
            'unit' => [],
        ];

        if (!is_dir($this->testDirectory)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($this->testDirectory)->name('*Test.php');

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $className = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            
            // Categorize tests
            if (str_contains($relativePath, 'Feature')) {
                $this->existingTests['feature'][$className] = [
                    'path' => $relativePath,
                    'methods' => $this->countTestMethods($file->getPathname()),
                ];
            } elseif (str_contains($relativePath, 'Unit')) {
                $this->existingTests['unit'][$className] = [
                    'path' => $relativePath,
                    'methods' => $this->countTestMethods($file->getPathname()),
                ];
            }

            // Categorize by component type
            if (str_contains($relativePath, 'Models') || str_contains($className, 'ModelTest')) {
                $this->existingTests['models'][$className] = [
                    'path' => $relativePath,
                    'methods' => $this->countTestMethods($file->getPathname()),
                ];
            } elseif (str_contains($relativePath, 'Controllers') || str_contains($className, 'ControllerTest')) {
                $this->existingTests['controllers'][$className] = [
                    'path' => $relativePath,
                    'methods' => $this->countTestMethods($file->getPathname()),
                ];
            } elseif (str_contains($relativePath, 'Services') || str_contains($className, 'ServiceTest')) {
                $this->existingTests['services'][$className] = [
                    'path' => $relativePath,
                    'methods' => $this->countTestMethods($file->getPathname()),
                ];
            }
        }
    }

    /**
     * Count test methods in a file
     */
    private function countTestMethods(string $filePath): int
    {
        $content = file_get_contents($filePath);
        
        // Count methods starting with test_ or having @test annotation
        preg_match_all('/public function (test_\w+|test\w+)\(/i', $content, $matches);
        $testMethods = count($matches[0]);
        
        // Also count @test annotations
        preg_match_all('/@test/', $content, $annotationMatches);
        $testMethods += count($annotationMatches[0]);
        
        return $testMethods;
    }

    /**
     * Analyze model test coverage
     */
    private function analyzeModelCoverage(array $models): array
    {
        $coverage = [
            'total' => count($models),
            'tested' => 0,
            'untested' => 0,
            'insufficient' => 0,
            'details' => [],
        ];

        foreach ($models as $model) {
            $modelName = $model['name'];
            $testName = $modelName . 'Test';
            
            $testExists = isset($this->existingTests['models'][$testName]);
            $testCount = $testExists ? $this->existingTests['models'][$testName]['methods'] : 0;
            
            // Expected tests: relationships + fillable + basic CRUD
            $expectedTests = count($model['relations'] ?? []) + 
                           (count($model['fillable'] ?? []) > 0 ? 2 : 0) + 
                           3; // create, update, delete

            $status = 'untested';
            if ($testExists) {
                if ($testCount >= $expectedTests) {
                    $status = 'tested';
                    $coverage['tested']++;
                } else {
                    $status = 'insufficient';
                    $coverage['insufficient']++;
                }
            } else {
                $coverage['untested']++;
            }

            $coverage['details'][$modelName] = [
                'status' => $status,
                'test_count' => $testCount,
                'expected_tests' => $expectedTests,
                'test_file' => $testExists ? $this->existingTests['models'][$testName]['path'] : null,
                'relations' => count($model['relations'] ?? []),
                'fillable_count' => count($model['fillable'] ?? []),
            ];
        }

        $coverage['coverage_percent'] = $coverage['total'] > 0 
            ? round(($coverage['tested'] / $coverage['total']) * 100, 1) 
            : 0;

        return $coverage;
    }

    /**
     * Analyze controller test coverage
     */
    private function analyzeControllerCoverage(array $controllers): array
    {
        $coverage = [
            'total_controllers' => count($controllers),
            'total_methods' => 0,
            'tested_controllers' => 0,
            'tested_methods' => 0,
            'untested_methods' => 0,
            'details' => [],
        ];

        foreach ($controllers as $controller) {
            $controllerName = $controller['name'];
            $testName = $controllerName . 'Test';
            $methods = $controller['methods'] ?? [];
            
            $coverage['total_methods'] += count($methods);
            
            $testExists = isset($this->existingTests['controllers'][$testName]);
            $testCount = $testExists ? $this->existingTests['controllers'][$testName]['methods'] : 0;

            if ($testExists) {
                $coverage['tested_controllers']++;
            }

            $methodDetails = [];
            foreach ($methods as $method) {
                $methodName = $method['name'];
                
                // Estimate if method is tested (heuristic: 3 tests per method)
                $estimatedTested = $testCount >= (count($methods) * 2);
                
                if ($estimatedTested) {
                    $coverage['tested_methods']++;
                } else {
                    $coverage['untested_methods']++;
                }

                $methodDetails[$methodName] = [
                    'status' => $estimatedTested ? 'likely_tested' : 'likely_untested',
                    'http_methods' => $method['http_methods'] ?? [],
                    'has_validation' => $method['has_validation'] ?? false,
                ];
            }

            $coverage['details'][$controllerName] = [
                'test_exists' => $testExists,
                'test_count' => $testCount,
                'method_count' => count($methods),
                'expected_tests' => count($methods) * 3, // ~3 tests per method
                'test_file' => $testExists ? $this->existingTests['controllers'][$testName]['path'] : null,
                'methods' => $methodDetails,
            ];
        }

        $coverage['controller_coverage_percent'] = $coverage['total_controllers'] > 0 
            ? round(($coverage['tested_controllers'] / $coverage['total_controllers']) * 100, 1) 
            : 0;

        $coverage['method_coverage_percent'] = $coverage['total_methods'] > 0 
            ? round(($coverage['tested_methods'] / $coverage['total_methods']) * 100, 1) 
            : 0;

        return $coverage;
    }

    /**
     * Analyze service/repository test coverage
     */
    private function analyzeServiceCoverage(array $servicesData): array
    {
        $services = array_merge(
            $servicesData['services'] ?? [],
            $servicesData['repositories'] ?? []
        );

        $coverage = [
            'total' => count($services),
            'tested' => 0,
            'untested' => 0,
            'details' => [],
        ];

        foreach ($services as $service) {
            $serviceName = $service['name'];
            $testName = str_replace(['Service', 'Repository'], ['ServiceTest', 'RepositoryTest'], $serviceName);
            
            $testExists = isset($this->existingTests['services'][$testName]);
            $testCount = $testExists ? $this->existingTests['services'][$testName]['methods'] : 0;

            if ($testExists) {
                $coverage['tested']++;
            } else {
                $coverage['untested']++;
            }

            $coverage['details'][$serviceName] = [
                'status' => $testExists ? 'tested' : 'untested',
                'test_count' => $testCount,
                'method_count' => count($service['methods'] ?? []),
                'test_file' => $testExists ? $this->existingTests['services'][$testName]['path'] : null,
            ];
        }

        $coverage['coverage_percent'] = $coverage['total'] > 0 
            ? round(($coverage['tested'] / $coverage['total']) * 100, 1) 
            : 0;

        return $coverage;
    }

    /**
     * Calculate overall coverage statistics
     */
    private function calculateOverallCoverage(array $results): array
    {
        $totalComponents = 
            $results['models']['total'] + 
            $results['controllers']['total_controllers'] + 
            $results['services']['total'];

        $testedComponents = 
            $results['models']['tested'] + 
            $results['controllers']['tested_controllers'] + 
            $results['services']['tested'];

        $untestedComponents = 
            $results['models']['untested'] + 
            ($results['controllers']['total_controllers'] - $results['controllers']['tested_controllers']) + 
            $results['services']['untested'];

        return [
            'total_components' => $totalComponents,
            'tested_components' => $testedComponents,
            'untested_components' => $untestedComponents,
            'overall_coverage_percent' => $totalComponents > 0 
                ? round(($testedComponents / $totalComponents) * 100, 1) 
                : 0,
            'test_files_count' => count($this->existingTests['unit']) + count($this->existingTests['feature']),
            'unit_tests' => count($this->existingTests['unit']),
            'feature_tests' => count($this->existingTests['feature']),
        ];
    }

    /**
     * Get coverage gaps (untested components)
     */
    public function getCoverageGaps(array $analysis): array
    {
        $gaps = [];

        // Model gaps
        foreach ($analysis['models']['details'] as $name => $details) {
            if ($details['status'] === 'untested' || $details['status'] === 'insufficient') {
                $gaps[] = [
                    'type' => 'model',
                    'name' => $name,
                    'status' => $details['status'],
                    'test_count' => $details['test_count'],
                    'expected_tests' => $details['expected_tests'],
                    'priority' => $details['status'] === 'untested' ? 'high' : 'medium',
                ];
            }
        }

        // Controller gaps
        foreach ($analysis['controllers']['details'] as $name => $details) {
            if (!$details['test_exists']) {
                $gaps[] = [
                    'type' => 'controller',
                    'name' => $name,
                    'status' => 'untested',
                    'test_count' => 0,
                    'expected_tests' => $details['expected_tests'],
                    'method_count' => $details['method_count'],
                    'priority' => 'high',
                ];
            } elseif ($details['test_count'] < $details['expected_tests'] / 2) {
                $gaps[] = [
                    'type' => 'controller',
                    'name' => $name,
                    'status' => 'insufficient',
                    'test_count' => $details['test_count'],
                    'expected_tests' => $details['expected_tests'],
                    'method_count' => $details['method_count'],
                    'priority' => 'medium',
                ];
            }
        }

        // Service gaps
        foreach ($analysis['services']['details'] as $name => $details) {
            if ($details['status'] === 'untested') {
                $gaps[] = [
                    'type' => 'service',
                    'name' => $name,
                    'status' => 'untested',
                    'test_count' => 0,
                    'method_count' => $details['method_count'],
                    'priority' => 'high',
                ];
            }
        }

        // Sort by priority
        usort($gaps, function($a, $b) {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            return $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']];
        });

        return $gaps;
    }

    /**
     * Get existing tests
     */
    public function getExistingTests(): array
    {
        return $this->existingTests;
    }
}
