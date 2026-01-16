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

    protected function setUp(): void
    {
        parent::setUp();
        // Add authentication if needed
        // $this->actingAs(User::factory()->create());
    }

    /**
     * Test index method
     * Route: GET /users
     */
    public function test_index(): void
    {
        $response = $this->get('/users');

        $response->assertStatus(200);
    }

    /**
     * Test store method
     * Route: POST /users
     */
    public function test_store(): void
    {
        $data = [
            'name' => 'Test Name',
            'email' => 'test@example.com',
        ];

        $response = $this->post('/users', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test store validation
     */
    public function test_store_validation(): void
    {
        $response = $this->post('/users', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email']);
    }

    /**
     * Test show method
     * Route: GET /users/{id}
     */
    public function test_show(): void
    {
        $user = User::factory()->create();

        $response = $this->get('/users/' . $user->id);

        $response->assertStatus(200);
    }

    /**
     * Test update method
     * Route: PUT /users/{id}
     */
    public function test_update(): void
    {
        $user = User::factory()->create();

        $data = [
            'name' => 'Updated Name',
        ];

        $response = $this->put('/users/' . $user->id, $data);

        $response->assertStatus(200);
    }

    /**
     * Test update validation
     */
    public function test_update_validation(): void
    {
        $response = $this->put('/users/' . $user->id, []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email']);
    }

    /**
     * Test destroy method
     * Route: DELETE /users/{id}
     */
    public function test_destroy(): void
    {
        $user = User::factory()->create();

        $response = $this->delete('/users/' . $user->id);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }

}
