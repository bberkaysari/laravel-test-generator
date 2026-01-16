<?php

namespace Bberkaysari\LaravelTestGenerator\Tests\Unit\Generator;

use Bberkaysari\LaravelTestGenerator\Generator\Generators\ModelTestGenerator;
use PHPUnit\Framework\TestCase;

class ModelTestGeneratorTest extends TestCase
{
    private ModelTestGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ModelTestGenerator();
    }

    public function test_it_generates_basic_test_class_structure(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('namespace Tests\Unit\Models;', $generatedCode);
        $this->assertStringContainsString('class UserTest extends TestCase', $generatedCode);
        $this->assertStringContainsString('use RefreshDatabase;', $generatedCode);
    }

    public function test_it_generates_use_statements(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('use App\Models\User;', $generatedCode);
        $this->assertStringContainsString('use Tests\TestCase;', $generatedCode);
        $this->assertStringContainsString('use Illuminate\Foundation\Testing\RefreshDatabase;', $generatedCode);
    }

    public function test_it_generates_factory_test(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_can_be_created_using_factory', $generatedCode);
        $this->assertStringContainsString('User::factory()->create()', $generatedCode);
        $this->assertStringContainsString('assertInstanceOf(User::class', $generatedCode);
        $this->assertStringContainsString('assertDatabaseHas', $generatedCode);
    }

    public function test_it_generates_fillable_test(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_has_correct_fillable_attributes', $generatedCode);
        $this->assertStringContainsString("'name'", $generatedCode);
        $this->assertStringContainsString("'email'", $generatedCode);
        $this->assertStringContainsString('getFillable()', $generatedCode);
    }

    public function test_it_generates_hidden_test(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_hides_sensitive_attributes', $generatedCode);
        $this->assertStringContainsString("'password'", $generatedCode);
        $this->assertStringContainsString('toArray()', $generatedCode);
        $this->assertStringContainsString('assertArrayNotHasKey', $generatedCode);
    }

    public function test_it_generates_cast_tests(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_casts_email_verified_at_to_datetime', $generatedCode);
        $this->assertStringContainsString('it_casts_is_admin_to_boolean', $generatedCode);
    }

    public function test_it_generates_has_many_relation_test(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_has_many_posts', $generatedCode);
        $this->assertStringContainsString('Post::factory()->count(3)->create', $generatedCode);
        $this->assertStringContainsString('assertCount(3', $generatedCode);
        $this->assertStringContainsString('user_id', $generatedCode);
    }

    public function test_it_generates_has_one_relation_test(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_has_one_profile', $generatedCode);
        $this->assertStringContainsString('Profile::factory()->create', $generatedCode);
    }

    public function test_it_generates_belongs_to_many_relation_test(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_belongs_to_many_roles', $generatedCode);
        $this->assertStringContainsString('Role::factory()->count(3)->create', $generatedCode);
        $this->assertStringContainsString('attach', $generatedCode);
    }

    public function test_it_generates_belongs_to_relation_test(): void
    {
        $modelData = $this->getSamplePostModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_belongs_to_user', $generatedCode);
        $this->assertStringContainsString('User::factory()->create', $generatedCode);
    }

    public function test_it_generates_scope_tests(): void
    {
        $modelData = $this->getSamplePostModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('it_can_filter_by_published_scope', $generatedCode);
        $this->assertStringContainsString('it_can_filter_by_featured_scope', $generatedCode);
        $this->assertStringContainsString('Post::published()->get()', $generatedCode);
    }

    public function test_it_returns_correct_test_path(): void
    {
        $modelData = $this->getSampleUserModelData();

        $path = $this->generator->getTestPath($modelData);

        $this->assertEquals('tests/Unit/Models/UserTest.php', $path);
    }

    public function test_it_handles_model_without_fillable(): void
    {
        $modelData = [
            'name' => 'EmptyModel',
            'namespace' => 'App\Models',
            'fillable' => [],
            'hidden' => [],
            'casts' => [],
            'relations' => [],
            'methods' => [],
        ];

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('class EmptyModelTest', $generatedCode);
        $this->assertStringContainsString('it_can_be_created_using_factory', $generatedCode);
        $this->assertStringNotContainsString('it_has_correct_fillable_attributes', $generatedCode);
    }

    public function test_it_adds_related_models_to_use_statements(): void
    {
        $modelData = $this->getSampleUserModelData();

        $generatedCode = $this->generator->generate($modelData);

        $this->assertStringContainsString('use App\Models\Post;', $generatedCode);
        $this->assertStringContainsString('use App\Models\Profile;', $generatedCode);
        $this->assertStringContainsString('use App\Models\Role;', $generatedCode);
    }

    private function getSampleUserModelData(): array
    {
        return [
            'name' => 'User',
            'namespace' => 'App\Models',
            'extends' => 'Model',
            'fillable' => ['name', 'email', 'password'],
            'hidden' => ['password', 'remember_token'],
            'casts' => [
                'email_verified_at' => 'datetime',
                'is_admin' => 'boolean',
            ],
            'relations' => [
                'posts' => ['type' => 'hasMany', 'model' => 'Post'],
                'profile' => ['type' => 'hasOne', 'model' => 'Profile'],
                'roles' => ['type' => 'belongsToMany', 'model' => 'Role'],
            ],
            'methods' => ['posts', 'profile', 'roles'],
        ];
    }

    private function getSamplePostModelData(): array
    {
        return [
            'name' => 'Post',
            'namespace' => 'App\Models',
            'extends' => 'Model',
            'fillable' => ['title', 'content', 'user_id'],
            'hidden' => [],
            'casts' => [
                'published_at' => 'datetime',
            ],
            'relations' => [
                'user' => ['type' => 'belongsTo', 'model' => 'User'],
                'comments' => ['type' => 'hasMany', 'model' => 'Comment'],
            ],
            'methods' => ['user', 'comments', 'scopePublished', 'scopeFeatured'],
        ];
    }
}
