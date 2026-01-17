<?php

namespace Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ServiceScanner;

class ServiceScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_it_scans_services(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('repositories', $result);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_it_detects_service_classes(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $services = $result['services'];

        // Should find UserService
        $userService = $this->findByName($services, 'UserService');
        $this->assertNotNull($userService, 'UserService should be found');

        $this->assertEquals('UserService', $userService['name']);
        $this->assertEquals('App\\Services', $userService['namespace']);
    }

    public function test_it_detects_repository_classes(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $repositories = $result['repositories'];

        // Should find UserRepository
        $userRepo = $this->findByName($repositories, 'UserRepository');
        $this->assertNotNull($userRepo, 'UserRepository should be found');

        $this->assertEquals('UserRepository', $userRepo['name']);
        $this->assertEquals('App\\Repositories', $userRepo['namespace']);
    }

    public function test_it_extracts_constructor_dependencies(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $services = $result['services'];
        $userService = $this->findByName($services, 'UserService');

        $this->assertNotNull($userService);
        $this->assertArrayHasKey('constructor', $userService);

        $constructor = $userService['constructor'];
        $this->assertNotEmpty($constructor['parameters']);

        // Should have UserRepository dependency
        $repoParam = $this->findParamByName($constructor['parameters'], 'userRepository');
        $this->assertNotNull($repoParam);
        $this->assertEquals('UserRepository', $repoParam['type']);
    }

    public function test_it_extracts_public_methods(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $services = $result['services'];
        $userService = $this->findByName($services, 'UserService');

        $this->assertNotNull($userService);
        $this->assertArrayHasKey('methods', $userService);

        $methods = $userService['methods'];
        $methodNames = array_column($methods, 'name');

        $this->assertContains('createUser', $methodNames);
        $this->assertContains('updateUser', $methodNames);
        $this->assertContains('findByEmail', $methodNames);
        $this->assertContains('getActiveUsers', $methodNames);
    }

    public function test_it_extracts_method_return_types(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $services = $result['services'];
        $userService = $this->findByName($services, 'UserService');

        $methods = $userService['methods'] ?? [];
        $createMethod = $this->findMethodByName($methods, 'createUser');

        $this->assertNotNull($createMethod);
        $this->assertEquals('User', $createMethod['return_type']);
    }

    public function test_it_extracts_method_parameters(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $services = $result['services'];
        $userService = $this->findByName($services, 'UserService');

        $methods = $userService['methods'] ?? [];
        $updateMethod = $this->findMethodByName($methods, 'updateUser');

        $this->assertNotNull($updateMethod);
        $this->assertCount(2, $updateMethod['parameters']);

        $params = $updateMethod['parameters'];
        $this->assertEquals('user', $params[0]['name']);
        $this->assertEquals('User', $params[0]['type']);
        $this->assertEquals('data', $params[1]['name']);
        $this->assertEquals('array', $params[1]['type']);
    }

    public function test_it_generates_statistics(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $stats = $result['statistics'];

        $this->assertArrayHasKey('total_services', $stats);
        $this->assertArrayHasKey('total_repositories', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total_services']);
        $this->assertGreaterThanOrEqual(1, $stats['total_repositories']);
    }

    public function test_it_handles_missing_directories(): void
    {
        $scanner = new ServiceScanner('/non/existent/path');
        $result = $scanner->scan();

        $this->assertEmpty($result['services']);
        $this->assertEmpty($result['repositories']);
        $this->assertEquals(0, $result['statistics']['total_services']);
        $this->assertEquals(0, $result['statistics']['total_repositories']);
    }

    public function test_it_detects_nullable_parameters(): void
    {
        $scanner = new ServiceScanner($this->fixturePath);
        $result = $scanner->scan();

        $services = $result['services'];
        $userService = $this->findByName($services, 'UserService');

        $constructor = $userService['constructor'] ?? [];
        $params = $constructor['parameters'] ?? [];

        // notificationService should be nullable
        $notifParam = $this->findParamByName($params, 'notificationService');
        if ($notifParam) {
            $this->assertTrue(
                str_starts_with($notifParam['type'] ?? '', '?') || $notifParam['has_default'] ?? false
            );
        }
    }

    private function findByName(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        return null;
    }

    private function findParamByName(array $params, string $name): ?array
    {
        foreach ($params as $param) {
            if (($param['name'] ?? '') === $name) {
                return $param;
            }
        }
        return null;
    }

    private function findMethodByName(array $methods, string $name): ?array
    {
        foreach ($methods as $method) {
            if (($method['name'] ?? '') === $name) {
                return $method;
            }
        }
        return null;
    }
}
