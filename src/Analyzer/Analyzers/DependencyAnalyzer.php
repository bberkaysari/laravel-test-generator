<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * DependencyAnalyzer - Analyzes constructor injection and dependency graphs
 * 
 * Features:
 * - Maps constructor dependencies
 * - Detects service container bindings
 * - Tracks dependency relationships
 * - Identifies circular dependencies
 * - Detects facade usage
 * - Analyzes dependency complexity
 */
class DependencyAnalyzer
{
    private array $dependencyMap = [];
    private array $circularDeps = [];

    /**
     * Analyze dependencies for a single class
     */
    public function analyzeClass(array $classData): array
    {
        $dependencies = [];

        if (!isset($classData['constructor']) || !$classData['constructor']) {
            return [
                'dependencies' => [],
                'dependency_count' => 0,
                'has_constructor_injection' => false,
                'complexity_score' => 0,
            ];
        }

        $constructor = $classData['constructor'];
        
        foreach ($constructor['parameters'] as $param) {
            $dep = [
                'name' => $param['name'],
                'type' => $param['type'],
                'is_interface' => $this->isInterface($param['type']),
                'is_concrete' => $this->isConcrete($param['type']),
                'is_framework' => $this->isFrameworkClass($param['type']),
                'has_default' => $param['has_default'],
            ];

            $dependencies[] = $dep;
        }

        return [
            'dependencies' => $dependencies,
            'dependency_count' => count($dependencies),
            'has_constructor_injection' => count($dependencies) > 0,
            'interface_count' => count(array_filter($dependencies, fn($d) => $d['is_interface'])),
            'concrete_count' => count(array_filter($dependencies, fn($d) => $d['is_concrete'])),
            'framework_count' => count(array_filter($dependencies, fn($d) => $d['is_framework'])),
            'complexity_score' => $this->calculateComplexityScore($dependencies),
        ];
    }

    /**
     * Analyze dependencies across multiple classes and build dependency graph
     */
    public function analyzeDependencyGraph(array $classes): array
    {
        $this->dependencyMap = [];
        $this->circularDeps = [];

        // Build dependency map
        foreach ($classes as $class) {
            $fqn = $class['fqn'] ?? $class['name'];
            $this->dependencyMap[$fqn] = $this->extractDependencies($class);
        }

        // Detect circular dependencies
        $this->detectCircularDependencies();

        return [
            'dependency_map' => $this->dependencyMap,
            'circular_dependencies' => $this->circularDeps,
            'statistics' => $this->calculateGraphStatistics(),
            'most_depended_on' => $this->findMostDependedOn(),
            'highest_dependency_count' => $this->findHighestDependencyCount(),
        ];
    }

    /**
     * Analyze method-level dependencies (property/method injection)
     */
    public function analyzeMethodDependencies(array $methods): array
    {
        $methodDeps = [];

        foreach ($methods as $method) {
            $deps = [];

            // Analyze method parameters
            if (isset($method['parameters'])) {
                foreach ($method['parameters'] as $param) {
                    if ($param['type'] && !$this->isPrimitiveType($param['type'])) {
                        $deps[] = [
                            'type' => 'parameter',
                            'name' => $param['name'],
                            'class' => $param['type'],
                        ];
                    }
                }
            }

            $methodDeps[$method['name']] = [
                'dependencies' => $deps,
                'dependency_count' => count($deps),
            ];
        }

        return $methodDeps;
    }

    private function extractDependencies(array $classData): array
    {
        $deps = [];

        if (isset($classData['constructor']['parameters'])) {
            foreach ($classData['constructor']['parameters'] as $param) {
                if ($param['type'] && !$this->isPrimitiveType($param['type'])) {
                    $deps[] = $param['type'];
                }
            }
        }

        return $deps;
    }

    private function detectCircularDependencies(): void
    {
        foreach ($this->dependencyMap as $class => $dependencies) {
            $visited = [];
            $this->dfsCircular($class, $dependencies, $visited, [$class]);
        }
    }

    private function dfsCircular(string $class, array $dependencies, array &$visited, array $path): void
    {
        foreach ($dependencies as $dep) {
            if (in_array($dep, $path)) {
                $cycle = array_slice($path, array_search($dep, $path));
                $cycle[] = $dep;
                $this->circularDeps[] = $cycle;
                continue;
            }

            if (isset($visited[$dep])) {
                continue;
            }

            $visited[$dep] = true;
            
            if (isset($this->dependencyMap[$dep])) {
                $newPath = $path;
                $newPath[] = $dep;
                $this->dfsCircular($dep, $this->dependencyMap[$dep], $visited, $newPath);
            }
        }
    }

    private function calculateGraphStatistics(): array
    {
        $totalDeps = 0;
        $classesWithDeps = 0;
        $maxDeps = 0;

        foreach ($this->dependencyMap as $deps) {
            $count = count($deps);
            $totalDeps += $count;
            if ($count > 0) {
                $classesWithDeps++;
            }
            $maxDeps = max($maxDeps, $count);
        }

        return [
            'total_classes' => count($this->dependencyMap),
            'classes_with_dependencies' => $classesWithDeps,
            'total_dependencies' => $totalDeps,
            'average_dependencies' => $classesWithDeps > 0 ? $totalDeps / $classesWithDeps : 0,
            'max_dependencies' => $maxDeps,
            'circular_dependency_count' => count($this->circularDeps),
        ];
    }

    private function findMostDependedOn(): array
    {
        $depCount = [];

        foreach ($this->dependencyMap as $deps) {
            foreach ($deps as $dep) {
                $depCount[$dep] = ($depCount[$dep] ?? 0) + 1;
            }
        }

        arsort($depCount);

        return array_slice($depCount, 0, 10, true);
    }

    private function findHighestDependencyCount(): array
    {
        $counts = [];

        foreach ($this->dependencyMap as $class => $deps) {
            $counts[$class] = count($deps);
        }

        arsort($counts);

        return array_slice($counts, 0, 10, true);
    }

    private function calculateComplexityScore(array $dependencies): int
    {
        $score = 0;

        foreach ($dependencies as $dep) {
            // Base score per dependency
            $score += 1;

            // Penalty for concrete classes (prefer interfaces)
            if ($dep['is_concrete'] && !$dep['is_interface']) {
                $score += 1;
            }

            // Penalty for framework dependencies (tight coupling)
            if ($dep['is_framework']) {
                $score += 1;
            }

            // Bonus for interfaces (loose coupling)
            if ($dep['is_interface']) {
                $score -= 1;
            }
        }

        return max(0, $score);
    }

    private function isInterface(?string $type): bool
    {
        if (!$type) {
            return false;
        }

        return str_ends_with($type, 'Interface') || 
               str_contains($type, '\\Contracts\\') ||
               str_contains($type, '\\Interfaces\\');
    }

    private function isConcrete(?string $type): bool
    {
        if (!$type || $this->isPrimitiveType($type)) {
            return false;
        }

        return !$this->isInterface($type);
    }

    private function isFrameworkClass(?string $type): bool
    {
        if (!$type) {
            return false;
        }

        $frameworks = [
            'Illuminate\\',
            'Symfony\\',
            'Laravel\\',
        ];

        foreach ($frameworks as $framework) {
            if (str_starts_with($type, $framework)) {
                return true;
            }
        }

        return false;
    }

    private function isPrimitiveType(?string $type): bool
    {
        if (!$type) {
            return false;
        }

        $primitives = ['int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'void', 'null'];
        
        $type = ltrim($type, '?');
        
        return in_array($type, $primitives);
    }
}
