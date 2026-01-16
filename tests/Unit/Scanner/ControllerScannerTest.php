<?php

namespace Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ControllerScanner;

class ControllerScannerTest extends TestCase
{
    private ControllerScanner $scanner;
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $projectPath = __DIR__ . '/../../Fixtures/sample-project';
        $this->scanner = new ControllerScanner($projectPath);
        $this->fixturesPath = __DIR__ . '/../../Fixtures/sample-project/app/Http/Controllers';
    }

    public function test_it_scans_controller_methods()
    {
        $results = $this->scanner->scan();
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        
        $userController = collect($results)->firstWhere('name', 'UserController');
        $this->assertNotNull($userController);
        $this->assertArrayHasKey('name', $userController);
        $this->assertArrayHasKey('namespace', $userController);
        $this->assertArrayHasKey('methods', $userController);
        $this->assertEquals('UserController', $userController['name']);
    }

    public function test_it_detects_http_methods()
    {
        $results = $this->scanner->scan();
        $result = collect($results)->firstWhere('name', 'UserController');

        $methods = $result['methods'];
        $this->assertNotEmpty($methods);

        // Check index method
        $indexMethod = collect($methods)->firstWhere('name', 'index');
        $this->assertNotNull($indexMethod);
        $this->assertEquals('GET', $indexMethod['http_method']);

        // Check store method
        $storeMethod = collect($methods)->firstWhere('name', 'store');
        $this->assertNotNull($storeMethod);
        $this->assertEquals('POST', $storeMethod['http_method']);

        // Check update method
        $updateMethod = collect($methods)->firstWhere('name', 'update');
        $this->assertNotNull($updateMethod);
        $this->assertEquals('PUT', $updateMethod['http_method']);

        // Check destroy method
        $destroyMethod = collect($methods)->firstWhere('name', 'destroy');
        $this->assertNotNull($destroyMethod);
        $this->assertEquals('DELETE', $destroyMethod['http_method']);
    }

    public function test_it_detects_resource_pattern()
    {
        $results = $this->scanner->scan();
        $result = collect($results)->firstWhere('name', 'UserController');

        $this->assertTrue($result['is_resource']);
    }

    public function test_it_detects_route_parameters()
    {
        $results = $this->scanner->scan();
        $result = collect($results)->firstWhere('name', 'UserController');

        $showMethod = collect($result['methods'])->firstWhere('name', 'show');
        $this->assertNotEmpty($showMethod['parameters']);
        $this->assertEquals('User', $showMethod['parameters'][0]['type']);
        $this->assertEquals('user', $showMethod['parameters'][0]['name']);
    }

    public function test_it_detects_validation_rules()
    {
        $results = $this->scanner->scan();
        $result = collect($results)->firstWhere('name', 'UserController');

        $storeMethod = collect($result['methods'])->firstWhere('name', 'store');
        $this->assertTrue($storeMethod['has_validation']);
    }

    public function test_it_handles_missing_file_gracefully()
    {
        $scanner = new ControllerScanner('/non/existent/path');
        $results = $scanner->scan();
        
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
}
