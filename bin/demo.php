#!/usr/bin/env php
<?php

/**
 * Laravel Test Generator Demo
 *
 * This script demonstrates the test generation capabilities.
 * Run: php bin/demo.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ModelScanner;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ModelTestGenerator;

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         LARAVEL TEST GENERATOR - DEMO                         ║\n";
echo "║         AI-Free, Universal Test Automation                    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Use the sample project from fixtures
$projectPath = __DIR__ . '/../tests/Fixtures/sample-project';

if (!is_dir($projectPath)) {
    echo "❌ Sample project not found at: {$projectPath}\n";
    exit(1);
}

echo "📂 Scanning project: {$projectPath}\n";
echo str_repeat("─", 60) . "\n\n";

// Step 1: Scan models
echo "🔍 STEP 1: Scanning Models...\n";
$scanner = new ModelScanner($projectPath);
$models = $scanner->scan();

echo "   ✅ Found " . count($models) . " model(s)\n\n";

foreach ($models as $index => $model) {
    echo "   📋 Model " . ($index + 1) . ": {$model['name']}\n";
    echo "      • Namespace: {$model['namespace']}\n";
    echo "      • Extends: {$model['extends']}\n";
    echo "      • Fillable: " . (empty($model['fillable']) ? 'none' : implode(', ', $model['fillable'])) . "\n";
    echo "      • Hidden: " . (empty($model['hidden']) ? 'none' : implode(', ', $model['hidden'])) . "\n";
    echo "      • Casts: " . (empty($model['casts']) ? 'none' : implode(', ', array_keys($model['casts']))) . "\n";
    echo "      • Relations: " . (empty($model['relations']) ? 'none' : implode(', ', array_keys($model['relations']))) . "\n";
    echo "\n";
}

echo str_repeat("─", 60) . "\n\n";

// Step 2: Generate tests
echo "🧪 STEP 2: Generating Tests...\n\n";

$generator = new ModelTestGenerator();

foreach ($models as $model) {
    echo "   Generating test for: {$model['name']}...\n";

    $testCode = $generator->generate($model);
    $testPath = $generator->getTestPath($model);

    echo "   📄 Output path: {$testPath}\n";
    echo "   📊 Generated code length: " . strlen($testCode) . " characters\n";

    // Count test methods
    preg_match_all('/public function test_|\/\*\* @test \*\//', $testCode, $matches);
    $testCount = count($matches[0]);
    echo "   🔢 Test methods generated: {$testCount}\n";

    echo "\n";
}

echo str_repeat("─", 60) . "\n\n";

// Step 3: Show sample output
echo "📝 STEP 3: Sample Generated Test (User Model):\n";
echo str_repeat("─", 60) . "\n\n";

$userModel = null;
foreach ($models as $model) {
    if ($model['name'] === 'User') {
        $userModel = $model;
        break;
    }
}

if ($userModel) {
    $generatedTest = $generator->generate($userModel);

    // Show first 80 lines
    $lines = explode("\n", $generatedTest);
    $preview = array_slice($lines, 0, 80);

    foreach ($preview as $line) {
        echo $line . "\n";
    }

    if (count($lines) > 80) {
        echo "\n... (" . (count($lines) - 80) . " more lines)\n";
    }
}

echo "\n" . str_repeat("─", 60) . "\n\n";

// Summary
echo "✅ DEMO COMPLETE!\n\n";
echo "📊 Summary:\n";
echo "   • Models scanned: " . count($models) . "\n";
echo "   • Tests would be generated: " . count($models) . " files\n";
echo "   • Capabilities demonstrated:\n";
echo "     - Model scanning (fillable, hidden, casts)\n";
echo "     - Relation detection (hasMany, hasOne, belongsTo, belongsToMany)\n";
echo "     - Scope detection\n";
echo "     - Test code generation\n";
echo "\n";

echo "🚀 Next Steps:\n";
echo "   • Add ControllerScanner for API endpoint tests\n";
echo "   • Add MigrationScanner for database schema tests\n";
echo "   • Create CLI command: php artisan test:generate\n";
echo "\n";
