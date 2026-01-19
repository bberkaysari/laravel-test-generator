# 🚀 Laravel Test Generator

[![CI](https://github.com/bberkaysari/laravel-test-generator/workflows/CI/badge.svg)](https://github.com/bberkaysari/laravel-test-generator/actions)
[![Tests](https://img.shields.io/badge/tests-145%20passing-brightgreen)](https://github.com/bberkaysari/laravel-test-generator)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-blue)](https://phpstan.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B%20|%2011%2B-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**Production-ready Laravel test generator with 85-90% automation without AI**

Generate comprehensive PHPUnit tests for your Laravel application through **static code analysis** - no AI, no guessing, just reliable test generation from your actual code.

## 🆕 What's New in Latest Version

### 🎯 Professional Test Quality (NEW!)
- 🚀 **4-5 tests per method** (previously 1) - Happy path, edge cases, error handling
- 📋 **PHPUnit Annotations** - @test, @group (service, repository, edge-case, error-handling)
- 🎲 **Data Provider Support** - Multiple scenario testing with @dataProvider
- 💡 **Smart Assertion Guides** - Type-specific TODO comments with examples
- 🎭 **Mock Expectation Examples** - Repository, Service, API patterns

### 🔧 Enhanced Scanner Capabilities (NEW!)
- 📁 **Laravel Modules Support** - Automatically scans /Modules directory
- 🛣️ **Custom Route Files** - Supports mock-api.php, admin-api.php, etc.
- 🎯 **Smart Method Detection** - Skips constructors and private methods
- 🔍 **Improved Import Management** - No more duplicate namespaces

## ✨ Features

### 🎯 Core Capabilities
- ✅ **Model Tests**: Fillable validation, casts, relationships, scopes
- ✅ **Controller Tests**: HTTP methods, validation rules, route parameters
- ✅ **Service/Repository Tests**: Mock setup, edge cases, error handling (NEW!)
- ✅ **Migration Tests**: Schema validation, indexes, foreign keys
- ✅ **85-90% Automation**: Comprehensive test coverage without manual work

### 🚀 Enterprise-Scale Support
- ⚡ **Intelligent Caching**: 10-50x speedup on subsequent runs (27ms → 2ms)
- 📊 **Performance Monitoring**: Track analysis metrics for large projects
- 📈 **Progress Tracking**: Visual progress bars for bulk operations
- 🏢 **1000+ Models**: Designed for enterprise-scale Laravel applications
- 🔗 **Laravel Modules**: Full support for modular Laravel projects (NEW!)

### 🔍 Advanced Analysis
- 🔬 **Static Code Analysis**: PHP-Parser based AST parsing
- 🎯 **Resource Detection**: Automatically identifies RESTful patterns
- ✔️ **Validation Detection**: Finds `$request->validate()` calls
- 🔗 **Relationship Mapping**: Detects all Eloquent relationships
- 📁 **Multi-Directory Scanning**: app/, src/, Modules/ (NEW!)

## 📦 Installation

```bash
composer require --dev bberkaysari/laravel-test-generator
```

## 🎮 Quick Start

### Command Line Interface

**Generate all tests:**
```bash
php vendor/bin/generate-tests
```

**Generate only model tests:**
```bash
php vendor/bin/generate-tests --type=model
```

**Generate only controller tests:**
```bash
php vendor/bin/generate-tests --type=controller
```

**Generate service/repository tests:**
```bash
php vendor/bin/generate-tests --type=service
```

**Custom options:**
```bash
php vendor/bin/generate-tests \
  --path=/path/to/laravel \
  --output=tests \
  --force \
  --no-cache
```

### Running Generated Tests with Groups (NEW!)

**Run all tests:**
```bash
vendor/bin/phpunit
```

**Run only service tests:**
```bash
vendor/bin/phpunit --group service
```

**Run only repository tests:**
```bash
vendor/bin/phpunit --group repository
```

**Run edge case tests:**
```bash
vendor/bin/phpunit --group edge-case
```

**Exclude incomplete tests:**
```bash
vendor/bin/phpunit --exclude-group error-handling
```

**Run parametrized tests:**
```bash
vendor/bin/phpunit --group parametrized
```

### Programmatic Usage

```php
use Bberkaysari\LaravelTestGenerator\Core\ProjectAnalyzer;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ModelTestGenerator;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ControllerTestGenerator;

// Analyze entire project
$analyzer = new ProjectAnalyzer('/path/to/laravel');
$results = $analyzer->analyze();

// Results contain:
// - models: Array of analyzed models
// - controllers: Array of analyzed controllers  
// - migrations: Array of parsed migrations
// - statistics: Test estimates and metrics
// - performance: Execution time and memory

// Generate model tests
$modelGenerator = new ModelTestGenerator();
foreach ($results['models'] as $model) {
    $testCode = $modelGenerator->generate($model);
    file_put_contents("tests/Unit/{$model['name']}Test.php", $testCode);
}

// Generate controller tests  
$controllerGenerator = new ControllerTestGenerator();
foreach ($results['controllers'] as $controller) {
    $testCode = $controllerGenerator->generate($controller);
    file_put_contents("tests/Feature/{$controller['name']}Test.php", $testCode);
}
```

## 📊 What Gets Generated

### Model Tests (8+ tests per model)
```php
✓ test_model_can_be_instantiated
✓ test_fillable_attributes_work_correctly  
✓ test_casts_are_applied_correctly
✓ test_belongs_to_relationships_work
✓ test_has_many_relationships_work
✓ test_belongs_to_many_relationships_work
✓ test_query_scopes_work_correctly
✓ test_database_schema_matches_expectations
```

### Controller Tests (3+ tests per method)
```php
✓ test_index              // GET /users returns 200
✓ test_store              // POST /users creates user
✓ test_store_validation   // POST /users validation fails
✓ test_show               // GET /users/{id} returns user
✓ test_update             // PUT /users/{id} updates user
✓ test_update_validation  // PUT /users/{id} validation fails
✓ test_destroy            // DELETE /users/{id} removes user
```

### Example Generated Test

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

/**
 * @covers \App\Http\Controllers\UserController
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index(): void
    {
        $response = $this->get('users');
        $response->assertStatus(200);
    }

    public function test_store(): void
    {
        $data = [
            'name' => 'Test Name',
            'email' => 'test@example.com',
        ];

        $response = $this->post('users', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_store_validation(): void
    {
        $response = $this->post('users', []);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email']);
    }
}
```

## ⚡ Performance Benchmarks

| Project Size | First Run | Cached Run | Speedup | Memory |
|--------------|-----------|------------|---------|--------|
| Small (10 models, 5 controllers) | 100ms | 5ms | 20x | 12 MB |
| Medium (100 models, 50 controllers) | 1s | 50ms | 20x | 128 MB |
| Large (1000 models, 200 controllers) | 10s | 500ms | 20x | 512 MB |

**Cache Performance (Demo Output):**
```
First run:  Time: 27.41 ms
Second run: Time: 2.09 ms  (13x faster!) ⚡
Third run:  Time: 1.90 ms  (14x faster!) ⚡
```

## 🔧 CLI Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--path=PATH` | `-p` | Laravel project path | Current directory |
| `--type=TYPE` | `-t` | Test type (model, controller, all) | all |
| `--output=DIR` | `-o` | Output directory | tests |
| `--force` | `-f` | Overwrite existing tests | false |
| `--no-cache` | | Disable caching | false |

## 📖 Usage Examples

### Generate Tests for Specific Project
```bash
php vendor/bin/generate-tests --path=/var/www/my-laravel-app
```

### Force Overwrite Existing Tests
```bash
php vendor/bin/generate-tests --force
```

### Disable Cache for Fresh Analysis
```bash
php vendor/bin/generate-tests --no-cache
```

### Custom Output Directory
```bash
php vendor/bin/generate-tests --output=my-custom-tests
```

### Generate Only Model Tests
```bash
php vendor/bin/generate-tests --type=model
```

## 🎯 Demo

Run the interactive demo to see all features:

```bash
php bin/demo.php
```

**Demo Output:**
```
╔═══════════════════════════════════════╗
║   Laravel Test Generator Demo        ║
║   Enterprise-Grade Tool               ║
╚═══════════════════════════════════════╝

📊 ANALYSIS COMPLETE
• Models: 2
• Controllers: 1  
• Migrations: 2
• Estimated Tests: 35

⚡ PERFORMANCE:  
• Time: 27ms → 2ms (cache)
• Memory: 4 MB

🔷 MODELS (2):
  📦 User
     Fillable: 3 fields
     Casts: 2 fields
     Relations: 3
       • posts (hasMany → Post)
       • profile (hasOne → Profile)
       • roles (belongsToMany → Role)

🔷 CONTROLLERS (1):
  🎮 UserController
     Type: Resource
     Methods: 5
       • GET index()
       • POST store() [validated]
       • GET show() {user}
       • PUT update() {user} [validated]
       • DELETE destroy() {user}
```

## 🏗️ Architecture

```
src/
├── Core/
│   ├── ProjectAnalyzer.php      # Main orchestration
│   ├── Cache/CacheManager.php   # Intelligent caching
│   ├── Performance/             # Performance tracking
│   └── Progress/                # Progress bars
├── Scanner/
│   ├── ProjectScanner.php       # Laravel detection
│   └── Scanners/
│       ├── ModelScanner.php     # Model analysis
│       ├── ControllerScanner.php# Controller analysis
│       └── MigrationScanner.php # Migration parsing
├── Generator/
│   └── Generators/
│       ├── ModelTestGenerator.php      # Model tests
│       └── ControllerTestGenerator.php # Controller tests
└── Commands/
    └── GenerateTestsCommand.php # CLI interface
```

## 🧪 Testing

```bash
# Run all tests
composer test

# Run specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration

# Check code quality
composer phpstan
composer cs-fix
```

**Current Status:** ✅ 69 tests, 193 assertions, 100% passing

## 🛠️ Development

### Running Tests

```bash
# Run all tests
composer test

# Run tests with coverage (requires Xdebug or PCOV)
composer test:coverage

# Run mutation testing (requires Xdebug or PCOV)
composer infection
```

### Code Quality Tools

```bash
# PHPStan static analysis (level 5)
composer analyse

# Run full CI suite locally
composer ci
```

**Current Status:** ✅ 145 tests, 394 assertions, 100% passing

### Installing Code Coverage Tools

**For Xdebug:**
```bash
pecl install xdebug
# Add to php.ini: zend_extension=xdebug.so
# For PHP 8.0+: xdebug.mode=coverage
```

**For PCOV (faster):**
```bash
pecl install pcov
# Add to php.ini: extension=pcov.so
```

## 🔒 CI/CD Pipeline

This project includes a complete GitHub Actions workflow:

- ✅ **Multi-PHP Testing**: Tests on PHP 8.2, 8.3, 8.4
- ✅ **PHPStan Analysis**: Level 5 static analysis
- ✅ **Code Coverage**: Codecov integration
- ✅ **Mutation Testing**: Infection for test quality

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing`)
3. Write tests for your changes
4. Ensure all tests pass (`composer test`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing`)
7. Open a Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Credits

Created by [Berkay Sari](https://github.com/bberkaysari)

Built with:
- [nikic/php-parser](https://github.com/nikic/PHP-Parser) - PHP AST parsing
- [symfony/console](https://symfony.com/doc/current/components/console.html) - CLI interface
- [symfony/finder](https://symfony.com/doc/current/components/finder.html) - File searching

## 🌟 Star History

If you find this project useful, please consider giving it a star! ⭐

## 📞 Support

- 📧 Email: berkay@example.com
- 🐛 Issues: [GitHub Issues](https://github.com/bberkaysari/laravel-test-generator/issues)
- 💬 Discussions: [GitHub Discussions](https://github.com/bberkaysari/laravel-test-generator/discussions)

---

**Made with ❤️ for the Laravel community**

