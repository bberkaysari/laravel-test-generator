<?php

namespace Bberkaysari\LaravelTestGenerator\Tests\Unit\Scanner;

use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\ModelScanner;
use PHPUnit\Framework\TestCase;

class ModelScannerTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_scanner_returns_empty_array_when_no_models_exist(): void
    {
        $scanner = new ModelScanner(__DIR__);
        $results = $scanner->scan();

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_scanner_finds_models_in_project(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCount(2, $results);
    }

    public function test_scanner_extracts_model_name_and_namespace(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $userModel = $this->findModelByName($results, 'User');

        $this->assertNotNull($userModel);
        $this->assertEquals('User', $userModel['name']);
        $this->assertEquals('App\Models', $userModel['namespace']);
        $this->assertEquals('Model', $userModel['extends']);
    }

    public function test_scanner_extracts_fillable_properties(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $userModel = $this->findModelByName($results, 'User');

        $this->assertContains('name', $userModel['fillable']);
        $this->assertContains('email', $userModel['fillable']);
        $this->assertContains('password', $userModel['fillable']);
    }

    public function test_scanner_extracts_hidden_properties(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $userModel = $this->findModelByName($results, 'User');

        $this->assertContains('password', $userModel['hidden']);
        $this->assertContains('remember_token', $userModel['hidden']);
    }

    public function test_scanner_extracts_casts(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $userModel = $this->findModelByName($results, 'User');

        $this->assertArrayHasKey('email_verified_at', $userModel['casts']);
        $this->assertEquals('datetime', $userModel['casts']['email_verified_at']);
        $this->assertArrayHasKey('is_admin', $userModel['casts']);
        $this->assertEquals('boolean', $userModel['casts']['is_admin']);
    }

    public function test_scanner_extracts_relations(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $userModel = $this->findModelByName($results, 'User');

        $this->assertArrayHasKey('posts', $userModel['relations']);
        $this->assertEquals('hasMany', $userModel['relations']['posts']['type']);
        $this->assertEquals('Post', $userModel['relations']['posts']['model']);

        $this->assertArrayHasKey('profile', $userModel['relations']);
        $this->assertEquals('hasOne', $userModel['relations']['profile']['type']);

        $this->assertArrayHasKey('roles', $userModel['relations']);
        $this->assertEquals('belongsToMany', $userModel['relations']['roles']['type']);
    }

    public function test_scanner_extracts_methods(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $userModel = $this->findModelByName($results, 'User');

        $this->assertContains('posts', $userModel['methods']);
        $this->assertContains('profile', $userModel['methods']);
        $this->assertContains('roles', $userModel['methods']);
    }

    public function test_scanner_handles_post_model_with_scopes(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $postModel = $this->findModelByName($results, 'Post');

        $this->assertNotNull($postModel);
        $this->assertContains('scopePublished', $postModel['methods']);
        $this->assertContains('scopeFeatured', $postModel['methods']);
    }

    public function test_scanner_extracts_belongsto_relation(): void
    {
        $scanner = new ModelScanner($this->fixturesPath);
        $results = $scanner->scan();

        $postModel = $this->findModelByName($results, 'Post');

        $this->assertArrayHasKey('user', $postModel['relations']);
        $this->assertEquals('belongsTo', $postModel['relations']['user']['type']);
        $this->assertEquals('User', $postModel['relations']['user']['model']);
    }

    private function findModelByName(array $models, string $name): ?array
    {
        foreach ($models as $model) {
            if ($model['name'] === $name) {
                return $model;
            }
        }
        return null;
    }
}
