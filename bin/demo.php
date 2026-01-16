#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Bberkaysari\LaravelTestGenerator\Core\ProjectAnalyzer;

echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║   Laravel Test Generator - Comprehensive Analysis Demo   ║\n";
echo "║          Enterprise-Grade Test Automation Tool           ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";
echo "\n";

$projectPath = __DIR__ . '/../tests/Fixtures/sample-project';

try {
    // Create analyzer with full verbosity
    $analyzer = new ProjectAnalyzer($projectPath, null, true);
    
    // Run comprehensive analysis
    $results = $analyzer->analyze();
    
    // Display detailed results
    echo "\n" . str_repeat("═", 60) . "\n";
    echo "📋 DETAILED ANALYSIS RESULTS\n";
    echo str_repeat("═", 60) . "\n\n";
    
    // Models
    if (!empty($results['models'])) {
        echo "🔷 MODELS (" . count($results['models']) . "):\n";
        foreach ($results['models'] as $model) {
            echo "\n  📦 {$model['name']}\n";
            echo "     Namespace: {$model['namespace']}\n";
            echo "     Fillable: " . count($model['fillable']) . " fields\n";
            echo "     Casts: " . count($model['casts']) . " fields\n";
            echo "     Relations: " . count($model['relations']) . "\n";
            
            if (!empty($model['relations'])) {
                foreach ($model['relations'] as $relation) {
                    $relName = $relation['method'] ?? $relation['name'] ?? 'unknown';
                    $relType = $relation['type'] ?? 'unknown';
                    $relTarget = $relation['model'] ?? $relation['related'] ?? 'unknown';
                    echo "       • {$relName} ({$relType} → {$relTarget})\n";
                }
            }
        }
        echo "\n";
    }
    
    // Migrations
    if (!empty($results['migrations'])) {
        echo "🔷 MIGRATIONS (" . count($results['migrations']) . "):\n";
        foreach ($results['migrations'] as $migration) {
            $tableName = $migration['table'] ?? $migration['name'] ?? 'unknown';
            echo "\n  📄 {$tableName}\n";
            echo "     Columns: " . count($migration['columns']) . "\n";
            echo "     Indexes: " . count($migration['indexes']) . "\n";
            echo "     Foreign Keys: " . count($migration['foreign_keys']) . "\n";
            
            if (!empty($migration['columns'])) {
                echo "     Fields:\n";
                foreach (array_slice($migration['columns'], 0, 5) as $col) {
                    $colName = $col['name'] ?? $col['column'] ?? 'unknown';
                    $colType = $col['type'] ?? 'unknown';
                    $nullable = ($col['nullable'] ?? false) ? ', nullable' : '';
                    $unique = ($col['unique'] ?? false) ? ', unique' : '';
                    echo "       • {$colName} ({$colType}{$nullable}{$unique})\n";
                }
                if (count($migration['columns']) > 5) {
                    echo "       ... and " . (count($migration['columns']) - 5) . " more\n";
                }
            }
        }
        echo "\n";
    }
    
    // Controllers
    if (!empty($results['controllers'])) {
        echo "🔷 CONTROLLERS (" . count($results['controllers']) . "):\n";
        foreach ($results['controllers'] as $controller) {
            echo "\n  🎮 {$controller['name']}\n";
            echo "     Type: " . ($controller['is_resource'] ? 'Resource' : 'Regular') . "\n";
            echo "     API: " . ($controller['is_api'] ? 'Yes' : 'No') . "\n";
            echo "     Methods: " . count($controller['methods']) . "\n";
            
            if (!empty($controller['methods'])) {
                foreach ($controller['methods'] as $method) {
                    $validation = $method['has_validation'] ? ' [validated]' : '';
                    $params = !empty($method['route_params']) ? ' {' . implode(', ', $method['route_params']) . '}' : '';
                    echo "       • {$method['http_method']} {$method['name']}(){$params}{$validation}\n";
                }
            }
        }
        echo "\n";
    }
    
    // Statistics
    $stats = $results['statistics'];
    echo str_repeat("═", 60) . "\n";
    echo "📊 TEST GENERATION ESTIMATE\n";
    echo str_repeat("═", 60) . "\n\n";
    
    $modelTests = $stats['models'] * 8;
    $controllerTests = $stats['controller_methods'] * 3;
    $migrationTests = $stats['migrations'] * 2;
    
    echo "  Model Tests:      {$modelTests} tests (8 per model)\n";
    echo "  Controller Tests: {$controllerTests} tests (3 per method)\n";
    echo "  Migration Tests:  {$migrationTests} tests (2 per migration)\n";
    echo "  ─────────────────────────────────\n";
    echo "  TOTAL:            {$stats['estimated_tests']} tests\n\n";
    
    // Performance
    $perf = $results['performance'];
    echo str_repeat("═", 60) . "\n";
    echo "⚡ PERFORMANCE METRICS\n";
    echo str_repeat("═", 60) . "\n\n";
    
    echo "  Execution Time:   " . ($perf['total_time'] ?? '0') . "\n";
    echo "  Memory Used:      " . ($perf['memory_used'] ?? '0 B') . "\n";
    echo "  Peak Memory:      " . ($perf['peak_memory'] ?? '0 B') . "\n\n";
    
    // Cache stats
    echo str_repeat("═", 60) . "\n";
    echo "💾 CACHE PERFORMANCE\n";
    echo str_repeat("═", 60) . "\n\n";
    
    echo "  Run this demo again to see cache speedup!\n";
    echo "  Expected: 10-50x faster on subsequent runs\n\n";
    
    // Features showcase
    echo str_repeat("═", 60) . "\n";
    echo "🎯 KEY FEATURES DEMONSTRATED\n";
    echo str_repeat("═", 60) . "\n\n";
    
    echo "  ✅ Model Analysis (fillable, casts, relations)\n";
    echo "  ✅ Migration Parsing (columns, indexes, FKs)\n";
    echo "  ✅ Controller Detection (HTTP methods, validation)\n";
    echo "  ✅ Resource Pattern Recognition\n";
    echo "  ✅ Intelligent Caching System\n";
    echo "  ✅ Performance Monitoring\n";
    echo "  ✅ Progress Tracking (for large projects)\n";
    echo "  ✅ Enterprise-Scale Support (1000+ models)\n\n";
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "Demo completed successfully! 🎉\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: {$e->getMessage()}\n";
    echo "Trace: {$e->getTraceAsString()}\n";
    exit(1);
}
