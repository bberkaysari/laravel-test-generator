<?php

namespace Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ControllerTestGenerator;

class ControllerTestGeneratorTest extends TestCase
{
    private ControllerTestGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ControllerTestGenerator();
    }

    public function test_it_generates_basic_controller_test()
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

    public function test_it_generates_validation_tests()
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

    public function test_it_handles_route_parameters()
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

    public function test_it_detects_api_controllers()
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
}
