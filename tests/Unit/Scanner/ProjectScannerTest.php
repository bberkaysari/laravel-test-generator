<?php

namespace Bberkaysari\LaravelTestGenerator\Tests\Unit\Scanner;

use Bberkaysari\LaravelTestGenerator\Scanner\ProjectScanner;
use PHPUnit\Framework\TestCase;

class ProjectScannerTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_constructor_throws_exception_for_invalid_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project path does not exist');

        new ProjectScanner('/path/that/does/not/exist');
    }

    public function test_constructor_accepts_valid_path(): void
    {
        $scanner = new ProjectScanner(__DIR__);

        $this->assertInstanceOf(ProjectScanner::class, $scanner);
    }

    public function test_get_project_path_returns_correct_path(): void
    {
        $scanner = new ProjectScanner(__DIR__);

        $this->assertEquals(realpath(__DIR__), $scanner->getProjectPath());
    }

    public function test_scan_validates_laravel_project(): void
    {
        $scanner = new ProjectScanner($this->fixturesPath);
        $results = $scanner->scan();

        $this->assertArrayHasKey('project_path', $results);
        $this->assertArrayHasKey('laravel_version', $results);
        $this->assertArrayHasKey('namespace_mapping', $results);
    }

    public function test_scan_detects_laravel_version(): void
    {
        $scanner = new ProjectScanner($this->fixturesPath);
        $results = $scanner->scan();

        $this->assertEquals('11.x', $results['laravel_version']);
    }

    public function test_scan_loads_namespace_mapping(): void
    {
        $scanner = new ProjectScanner($this->fixturesPath);
        $results = $scanner->scan();

        $this->assertArrayHasKey('App\\', $results['namespace_mapping']);
        $this->assertEquals('app/', $results['namespace_mapping']['App\\']);
    }

    public function test_scan_throws_exception_for_non_composer_project(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a valid Composer project');

        $scanner = new ProjectScanner(__DIR__);
        $scanner->scan();
    }
}
