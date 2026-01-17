<?php

namespace Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\MiddlewareScanner;

class MiddlewareScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_it_scans_middleware(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $this->assertArrayHasKey('middleware', $result);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_it_detects_middleware_classes(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $middleware = $result['middleware'];

        // Should find EnsureUserIsActive middleware
        $activeMiddleware = $this->findByName($middleware, 'EnsureUserIsActive');
        $this->assertNotNull($activeMiddleware, 'EnsureUserIsActive middleware should be found');

        $this->assertEquals('EnsureUserIsActive', $activeMiddleware['name']);
        $this->assertEquals('App\\Http\\Middleware', $activeMiddleware['namespace']);
    }

    public function test_it_detects_handle_method(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $middleware = $result['middleware'];
        $activeMiddleware = $this->findByName($middleware, 'EnsureUserIsActive');

        $this->assertNotNull($activeMiddleware);
        $this->assertTrue($activeMiddleware['has_handle_method'] ?? false);
    }

    public function test_it_extracts_handle_parameters(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $middleware = $result['middleware'];
        $activeMiddleware = $this->findByName($middleware, 'EnsureUserIsActive');

        $this->assertNotNull($activeMiddleware);

        $params = $activeMiddleware['handle_parameters'] ?? [];
        $this->assertNotEmpty($params);

        // Should have Request parameter
        $requestParam = $this->findParamByName($params, 'request');
        $this->assertNotNull($requestParam);
        $this->assertEquals('Request', $requestParam['type']);
    }

    public function test_it_generates_statistics(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $stats = $result['statistics'];

        $this->assertArrayHasKey('total_middleware', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total_middleware']);
    }

    public function test_it_handles_missing_directories(): void
    {
        $scanner = new MiddlewareScanner('/non/existent/path');
        $result = $scanner->scan();

        $this->assertEmpty($result['middleware']);
        $this->assertEquals(0, $result['statistics']['total_middleware']);
    }

    public function test_it_extracts_fqn(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $middleware = $result['middleware'];
        $activeMiddleware = $this->findByName($middleware, 'EnsureUserIsActive');

        $this->assertNotNull($activeMiddleware);
        $this->assertArrayHasKey('fqn', $activeMiddleware);
        $this->assertEquals('App\\Http\\Middleware\\EnsureUserIsActive', $activeMiddleware['fqn']);
    }

    public function test_it_returns_route_middleware_array(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $this->assertArrayHasKey('route_middleware', $result);
        $this->assertIsArray($result['route_middleware']);
    }

    public function test_it_returns_middleware_groups_array(): void
    {
        $scanner = new MiddlewareScanner($this->fixturePath);
        $result = $scanner->scan();

        $this->assertArrayHasKey('middleware_groups', $result);
        $this->assertIsArray($result['middleware_groups']);
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
}
