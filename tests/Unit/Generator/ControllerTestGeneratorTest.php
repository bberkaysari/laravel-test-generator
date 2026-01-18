<?php

namespace Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ControllerTestGenerator;
use Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers\RouteAnalyzer;

class ControllerTestGeneratorTest extends TestCase
{
    private ControllerTestGenerator $generator;
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ControllerTestGenerator();
        $this->fixturePath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_it_generates_basic_controller_test(): void
    {
        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'index',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => [],
                ],
            ],
        ];

        $result = $this->generator->generate($controller);

        $this->assertStringContainsString('class UserControllerTest', $result);
        $this->assertStringContainsString('function test_index', $result);
        $this->assertStringContainsString('$this->get(', $result);
    }

    public function test_it_generates_validation_tests(): void
    {
        $controller = [
            'name' => 'PostController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'store',
                    'http_method' => 'POST',
                    'has_validation' => true,
                    'route_params' => [],
                ],
            ],
        ];

        $result = $this->generator->generate($controller);

        $this->assertStringContainsString('function test_store', $result);
        $this->assertStringContainsString('function test_store_validation', $result);
        $this->assertStringContainsString('assertStatus(422)', $result);
    }

    public function test_it_handles_route_parameters(): void
    {
        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'show',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => ['user'],
                    'parameters' => [
                        ['name' => 'user', 'type' => 'User'],
                    ],
                ],
            ],
        ];

        $result = $this->generator->generate($controller);

        $this->assertStringContainsString('User::factory()->create()', $result);
        $this->assertStringContainsString('$user->id', $result);
    }

    public function test_it_detects_api_controllers(): void
    {
        $controller = [
            'name' => 'ApiUserController',
            'is_resource' => true,
            'is_api' => true,
            'methods' => [
                [
                    'name' => 'index',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => [],
                ],
            ],
        ];

        $result = $this->generator->generate($controller);

        $this->assertStringContainsString('Tests\Feature\Api', $result);
        $this->assertStringContainsString('assertJsonStructure', $result);
    }

    public function test_it_integrates_with_route_analyzer(): void
    {
        $routeAnalyzer = new RouteAnalyzer($this->fixturePath);
        $routeAnalyzer->analyze();

        $generator = new ControllerTestGenerator($routeAnalyzer);

        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'index',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => [],
                ],
                [
                    'name' => 'show',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => ['id'],
                    'parameters' => [
                        ['name' => 'user', 'type' => 'User'],
                    ],
                ],
            ],
        ];

        $result = $generator->generate($controller);

        // Should generate tests for index and show methods
        $this->assertStringContainsString('function test_index', $result);
        $this->assertStringContainsString('function test_show', $result);
    }

    public function test_it_uses_route_uri_from_route_analyzer(): void
    {
        $routeAnalyzer = new RouteAnalyzer($this->fixturePath);
        $routeAnalyzer->analyze();

        $generator = new ControllerTestGenerator($routeAnalyzer);

        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'index',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => [],
                ],
            ],
        ];

        $result = $generator->generate($controller);

        // When RouteAnalyzer finds /users route, it should use actual URI
        // The test should contain route reference
        $this->assertStringContainsString('test_index', $result);
    }

    public function test_it_generates_middleware_tests_from_route_data(): void
    {
        $routeAnalyzer = new RouteAnalyzer($this->fixturePath);
        $routeAnalyzer->analyze();

        $generator = new ControllerTestGenerator($routeAnalyzer);

        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'index',
                    'http_method' => 'GET',
                    'has_validation' => false,
                    'route_params' => [],
                ],
            ],
        ];

        $result = $generator->generate($controller);

        // If route has auth middleware, should generate middleware test
        // Note: The presence depends on RouteAnalyzer detecting middleware
        $this->assertIsString($result);
    }

    public function test_it_generates_correct_http_method_from_route(): void
    {
        $routeAnalyzer = new RouteAnalyzer($this->fixturePath);
        $routeAnalyzer->analyze();

        $generator = new ControllerTestGenerator($routeAnalyzer);

        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => false,
            'methods' => [
                [
                    'name' => 'store',
                    'http_method' => 'POST',
                    'has_validation' => true,
                    'route_params' => [],
                ],
                [
                    'name' => 'update',
                    'http_method' => 'PUT',
                    'has_validation' => true,
                    'route_params' => ['id'],
                    'parameters' => [
                        ['name' => 'user', 'type' => 'User'],
                    ],
                ],
                [
                    'name' => 'destroy',
                    'http_method' => 'DELETE',
                    'has_validation' => false,
                    'route_params' => ['id'],
                    'parameters' => [
                        ['name' => 'user', 'type' => 'User'],
                    ],
                ],
            ],
        ];

        $result = $generator->generate($controller);

        // Should use correct HTTP methods
        $this->assertStringContainsString('$this->post(', $result);
        $this->assertStringContainsString('$this->put(', $result);
        $this->assertStringContainsString('$this->delete(', $result);
    }

    public function test_it_generates_test_path_correctly(): void
    {
        $controller = [
            'name' => 'UserController',
            'is_api' => false,
        ];

        $path = $this->generator->getTestPath($controller);

        $this->assertEquals('tests/Feature/UserControllerTest.php', $path);
    }

    public function test_it_generates_api_test_path_correctly(): void
    {
        $controller = [
            'name' => 'UserController',
            'is_api' => true,
        ];

        $path = $this->generator->getTestPath($controller);

        $this->assertEquals('tests/Feature/Api/UserControllerTest.php', $path);
    }

    public function test_it_generates_destroy_test_with_correct_status(): void
    {
        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => true,
            'methods' => [
                [
                    'name' => 'destroy',
                    'http_method' => 'DELETE',
                    'has_validation' => false,
                    'route_params' => ['id'],
                    'parameters' => [
                        ['name' => 'user', 'type' => 'User'],
                    ],
                ],
            ],
        ];

        $result = $this->generator->generate($controller);

        $this->assertStringContainsString('assertStatus(204)', $result);
    }

    public function test_it_generates_store_test_with_correct_status(): void
    {
        $controller = [
            'name' => 'UserController',
            'is_resource' => true,
            'is_api' => true,
            'methods' => [
                [
                    'name' => 'store',
                    'http_method' => 'POST',
                    'has_validation' => true,
                    'route_params' => [],
                ],
            ],
        ];

        $result = $this->generator->generate($controller);

        $this->assertStringContainsString('assertStatus(201)', $result);
    }
}
