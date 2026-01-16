<?php

namespace Bberkaysari\LaravelTestGenerator\Tests\Unit\Scanner;

use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\MigrationScanner;
use PHPUnit\Framework\TestCase;

class MigrationScannerTest extends TestCase
{
    private string $fixturesPath;
    private MigrationScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = __DIR__ . '/../../Fixtures/sample-project';
        $this->scanner = new MigrationScanner($this->fixturesPath);
    }

    public function test_scanner_returns_empty_array_when_no_migrations_exist(): void
    {
        $scanner = new MigrationScanner(__DIR__);
        $results = $scanner->scan();

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_scanner_finds_tables_in_migrations(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('users', $results);
        $this->assertArrayHasKey('posts', $results);
    }

    public function test_scanner_extracts_id_column(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('id', $results['users']['columns']);
        $this->assertEquals('bigInteger', $results['users']['columns']['id']['type']);
        $this->assertTrue($results['users']['columns']['id']['unsigned']);
    }

    public function test_scanner_extracts_string_columns(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('name', $results['users']['columns']);
        $this->assertEquals('string', $results['users']['columns']['name']['type']);
        $this->assertFalse($results['users']['columns']['name']['nullable']);

        $this->assertArrayHasKey('email', $results['users']['columns']);
        $this->assertTrue($results['users']['columns']['email']['unique']);
    }

    public function test_scanner_extracts_nullable_columns(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('email_verified_at', $results['users']['columns']);
        $this->assertTrue($results['users']['columns']['email_verified_at']['nullable']);
    }

    public function test_scanner_extracts_boolean_with_default(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('is_admin', $results['users']['columns']);
        $this->assertEquals('boolean', $results['users']['columns']['is_admin']['type']);
        $this->assertFalse($results['users']['columns']['is_admin']['default']);
    }

    public function test_scanner_detects_timestamps(): void
    {
        $results = $this->scanner->scan();

        $this->assertTrue($results['users']['timestamps']);
        $this->assertArrayHasKey('created_at', $results['users']['columns']);
        $this->assertArrayHasKey('updated_at', $results['users']['columns']);
    }

    public function test_scanner_detects_soft_deletes(): void
    {
        $results = $this->scanner->scan();

        $this->assertTrue($results['users']['soft_deletes']);
        $this->assertArrayHasKey('deleted_at', $results['users']['columns']);
    }

    public function test_scanner_extracts_foreign_keys(): void
    {
        $results = $this->scanner->scan();

        $foreignKeys = $results['posts']['foreign_keys'];
        $this->assertNotEmpty($foreignKeys);

        $userFk = $foreignKeys[0];
        $this->assertEquals('user_id', $userFk['column']);
        $this->assertEquals('users', $userFk['referenced_table']);
        $this->assertEquals('cascade', $userFk['on_delete']);
    }

    public function test_scanner_extracts_indexes(): void
    {
        $results = $this->scanner->scan();

        $indexes = $results['posts']['indexes'];
        $this->assertNotEmpty($indexes);

        // Check for single column index
        $publishedAtIndex = array_filter($indexes, fn($i) => in_array('published_at', $i['columns']));
        $this->assertNotEmpty($publishedAtIndex);

        // Check for composite index
        $compositeIndex = array_filter($indexes, fn($i) => count($i['columns']) > 1);
        $this->assertNotEmpty($compositeIndex);
    }

    public function test_scanner_extracts_unique_constraints(): void
    {
        $results = $this->scanner->scan();

        $unique = $results['posts']['unique_constraints'];
        $this->assertNotEmpty($unique);
        $this->assertContains(['slug'], $unique);
    }

    public function test_scanner_extracts_decimal_with_precision(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('rating', $results['posts']['columns']);
        $this->assertEquals('decimal', $results['posts']['columns']['rating']['type']);
        $this->assertEquals(3, $results['posts']['columns']['rating']['length']);
        $this->assertEquals(2, $results['posts']['columns']['rating']['precision']);
    }

    public function test_scanner_extracts_unsigned_integer(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('view_count', $results['posts']['columns']);
        $this->assertTrue($results['posts']['columns']['view_count']['unsigned']);
        $this->assertEquals(0, $results['posts']['columns']['view_count']['default']);
    }

    public function test_scanner_extracts_string_with_length(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('title', $results['posts']['columns']);
        $this->assertEquals(255, $results['posts']['columns']['title']['length']);
    }

    public function test_get_table_schema_returns_specific_table(): void
    {
        $this->scanner->scan();

        $usersSchema = $this->scanner->getTableSchema('users');
        $this->assertNotNull($usersSchema);
        $this->assertArrayHasKey('columns', $usersSchema);

        $nonExistent = $this->scanner->getTableSchema('non_existent');
        $this->assertNull($nonExistent);
    }

    public function test_get_table_names_returns_all_tables(): void
    {
        $this->scanner->scan();

        $tables = $this->scanner->getTableNames();
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
    }

    public function test_scanner_extracts_remember_token(): void
    {
        $results = $this->scanner->scan();

        $this->assertArrayHasKey('remember_token', $results['users']['columns']);
        $this->assertEquals('string', $results['users']['columns']['remember_token']['type']);
        $this->assertEquals(100, $results['users']['columns']['remember_token']['length']);
    }
}
