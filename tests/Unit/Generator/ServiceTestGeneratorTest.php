<?php

namespace Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Generator\Generators\ServiceTestGenerator;

class ServiceTestGeneratorTest extends TestCase
{
    private ServiceTestGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ServiceTestGenerator();
    }

    public function test_it_generates_basic_service_test(): void
    {
        $service = [
            'name' => 'UserService',
            'fqn' => 'App\\Services\\UserService',
            'methods' => [
                [
                    'name' => 'createUser',
                    'visibility' => 'public',
                    'parameters' => [
                        ['name' => 'data', 'type' => 'array'],
                    ],
                    'return_type' => 'User',
                ],
            ],
            'constructor' => [
                'parameters' => [],
            ],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('class UserServiceTest', $result);
        $this->assertStringContainsString('function test_create_user', $result);
        $this->assertStringContainsString('Tests\\TestCase', $result);
    }

    public function test_it_generates_mock_setup_for_dependencies(): void
    {
        $service = [
            'name' => 'UserService',
            'fqn' => 'App\\Services\\UserService',
            'methods' => [
                [
                    'name' => 'findUser',
                    'visibility' => 'public',
                    'parameters' => [
                        ['name' => 'id', 'type' => 'int'],
                    ],
                    'return_type' => '?User',
                ],
            ],
            'constructor' => [
                'parameters' => [
                    ['name' => 'userRepository', 'type' => 'UserRepository', 'has_default' => false],
                ],
            ],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('Mockery::mock', $result);
        $this->assertStringContainsString('MockInterface', $result);
        $this->assertStringContainsString('$userRepository', $result);
    }

    public function test_it_generates_teardown_with_mockery_close(): void
    {
        $service = [
            'name' => 'TestService',
            'fqn' => 'App\\Services\\TestService',
            'methods' => [],
            'constructor' => ['parameters' => []],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('Mockery::close()', $result);
        $this->assertStringContainsString('tearDown', $result);
    }

    public function test_it_skips_private_methods(): void
    {
        $service = [
            'name' => 'UserService',
            'fqn' => 'App\\Services\\UserService',
            'methods' => [
                [
                    'name' => 'publicMethod',
                    'visibility' => 'public',
                    'parameters' => [],
                    'return_type' => 'void',
                ],
                [
                    'name' => 'privateMethod',
                    'visibility' => 'private',
                    'parameters' => [],
                    'return_type' => 'void',
                ],
            ],
            'constructor' => ['parameters' => []],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('test_public_method', $result);
        $this->assertStringNotContainsString('test_private_method', $result);
    }

    public function test_it_generates_edge_case_tests_for_array_params(): void
    {
        $service = [
            'name' => 'DataService',
            'fqn' => 'App\\Services\\DataService',
            'methods' => [
                [
                    'name' => 'processData',
                    'visibility' => 'public',
                    'parameters' => [
                        ['name' => 'data', 'type' => 'array'],
                    ],
                    'return_type' => 'array',
                ],
            ],
            'constructor' => ['parameters' => []],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('test_process_data', $result);
        // New edge case test names after improvements
        $this->assertStringContainsString('handles_errors', $result);
    }

    public function test_it_generates_correct_assertions_for_return_types(): void
    {
        $service = [
            'name' => 'TypeService',
            'fqn' => 'App\\Services\\TypeService',
            'methods' => [
                [
                    'name' => 'getBool',
                    'visibility' => 'public',
                    'parameters' => [],
                    'return_type' => 'bool',
                ],
                [
                    'name' => 'getInt',
                    'visibility' => 'public',
                    'parameters' => [],
                    'return_type' => 'int',
                ],
                [
                    'name' => 'getArray',
                    'visibility' => 'public',
                    'parameters' => [],
                    'return_type' => 'array',
                ],
                [
                    'name' => 'getString',
                    'visibility' => 'public',
                    'parameters' => [],
                    'return_type' => 'string',
                ],
            ],
            'constructor' => ['parameters' => []],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('assertIsBool', $result);
        $this->assertStringContainsString('assertIsInt', $result);
        $this->assertStringContainsString('assertIsArray', $result);
        $this->assertStringContainsString('assertIsString', $result);
    }

    public function test_it_supports_service_type(): void
    {
        $this->assertTrue($this->generator->supports('service'));
    }

    public function test_it_supports_repository_type(): void
    {
        $this->assertTrue($this->generator->supports('repository'));
    }

    public function test_it_does_not_support_other_types(): void
    {
        $this->assertFalse($this->generator->supports('controller'));
        $this->assertFalse($this->generator->supports('model'));
    }

    public function test_it_generates_correct_test_path_for_service(): void
    {
        $service = [
            'name' => 'UserService',
        ];

        $path = $this->generator->getTestPath($service);

        $this->assertEquals('Services/UserServiceTest.php', $path);
    }

    public function test_it_generates_correct_test_path_for_repository(): void
    {
        $repository = [
            'name' => 'UserRepository',
        ];

        $path = $this->generator->getTestPath($repository);

        $this->assertEquals('Services/UserRepositoryTest.php', $path);
    }

    public function test_it_generates_multiple_mock_properties(): void
    {
        $service = [
            'name' => 'OrderService',
            'fqn' => 'App\\Services\\OrderService',
            'methods' => [],
            'constructor' => [
                'parameters' => [
                    ['name' => 'orderRepository', 'type' => 'OrderRepository', 'has_default' => false],
                    ['name' => 'userRepository', 'type' => 'UserRepository', 'has_default' => false],
                    ['name' => 'paymentService', 'type' => 'PaymentService', 'has_default' => false],
                ],
            ],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('$orderRepository', $result);
        $this->assertStringContainsString('$userRepository', $result);
        $this->assertStringContainsString('$paymentService', $result);
    }

    public function test_it_handles_nullable_dependencies(): void
    {
        $service = [
            'name' => 'CacheService',
            'fqn' => 'App\\Services\\CacheService',
            'methods' => [],
            'constructor' => [
                'parameters' => [
                    ['name' => 'cache', 'type' => '?CacheInterface', 'has_default' => true],
                ],
            ],
        ];

        $result = $this->generator->generate($service);

        // Should strip the ? from type when creating mock
        $this->assertStringContainsString('CacheInterface::class', $result);
    }

    public function test_it_generates_repository_mock_expectations(): void
    {
        $service = [
            'name' => 'UserService',
            'fqn' => 'App\\Services\\UserService',
            'methods' => [
                [
                    'name' => 'getUser',
                    'visibility' => 'public',
                    'parameters' => [
                        ['name' => 'id', 'type' => 'int'],
                    ],
                    'return_type' => '?User',
                ],
            ],
            'constructor' => [
                'parameters' => [
                    ['name' => 'userRepository', 'type' => 'UserRepository', 'has_default' => false],
                ],
            ],
        ];

        $result = $this->generator->generate($service);

        $this->assertStringContainsString('shouldReceive', $result);
        $this->assertStringContainsString('andReturn', $result);
    }
}
