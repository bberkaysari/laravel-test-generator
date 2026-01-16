<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use Symfony\Component\Finder\Finder;

class MigrationScanner implements ScannerInterface
{
    private string $projectPath;
    private array $schema = [];

    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
    }

    public function scan(): array
    {
        $migrationPath = $this->findMigrationPath();

        if (!is_dir($migrationPath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($migrationPath)->name('*.php')->sortByName();

        foreach ($finder as $file) {
            $content = $file->getContents();
            $this->parseMigration($file->getFilename(), $content);
        }

        return $this->schema;
    }

    private function findMigrationPath(): string
    {
        return $this->projectPath . '/database/migrations';
    }

    private function parseMigration(string $filename, string $content): void
    {
        // Extract table creation
        if (preg_match('/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $tableName = $matches[1];
            $this->schema[$tableName] = $this->parseTableDefinition($content, $tableName);
        }

        // Extract table modifications (Schema::table)
        if (preg_match_all('/Schema::table\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $tableName) {
                if (!isset($this->schema[$tableName])) {
                    $this->schema[$tableName] = [
                        'columns' => [],
                        'indexes' => [],
                        'foreign_keys' => [],
                        'unique_constraints' => [],
                    ];
                }
                $this->mergeTableModifications($tableName, $content);
            }
        }
    }

    private function parseTableDefinition(string $content, string $tableName): array
    {
        return [
            'columns' => $this->extractColumns($content),
            'indexes' => $this->extractIndexes($content),
            'foreign_keys' => $this->extractForeignKeys($content),
            'unique_constraints' => $this->extractUniqueConstraints($content),
            'primary_key' => $this->extractPrimaryKey($content),
            'timestamps' => $this->hasTimestamps($content),
            'soft_deletes' => $this->hasSoftDeletes($content),
        ];
    }

    private function extractColumns(string $content): array
    {
        $columns = [];

        // Match column definitions like: $table->string('name'), $table->integer('age')->nullable()
        $pattern = '/\$table->(\w+)\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(\d+))?(?:\s*,\s*(\d+))?\s*\)([^;]*);/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = $match[1];
                $name = $match[2];
                $length = $match[3] ?? null;
                $precision = $match[4] ?? null;
                $modifiers = $match[5] ?? '';

                // Skip special methods that aren't columns
                if (in_array($type, ['foreign', 'index', 'unique', 'primary', 'dropColumn', 'renameColumn'])) {
                    continue;
                }

                $columns[$name] = [
                    'type' => $this->normalizeColumnType($type),
                    'original_type' => $type,
                    'nullable' => str_contains($modifiers, 'nullable()'),
                    'default' => $this->extractDefault($modifiers),
                    'unsigned' => str_contains($modifiers, 'unsigned()') || in_array($type, ['unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger']),
                    'unique' => str_contains($modifiers, 'unique()'),
                    'length' => $length ? (int) $length : null,
                    'precision' => $precision ? (int) $precision : null,
                ];
            }
        }

        // Handle id columns
        if (preg_match('/\$table->id\s*\(\s*\)/', $content)) {
            $columns['id'] = [
                'type' => 'bigInteger',
                'original_type' => 'id',
                'nullable' => false,
                'default' => null,
                'unsigned' => true,
                'unique' => true,
                'auto_increment' => true,
            ];
        }

        // Handle uuid columns
        if (preg_match('/\$table->uuid\s*\(\s*[\'"]?(\w*)[\'"]?\s*\)/', $content, $match)) {
            $name = $match[1] ?: 'uuid';
            $columns[$name] = [
                'type' => 'uuid',
                'original_type' => 'uuid',
                'nullable' => false,
                'default' => null,
                'unsigned' => false,
                'unique' => false,
            ];
        }

        // Handle timestamps
        if ($this->hasTimestamps($content)) {
            $columns['created_at'] = [
                'type' => 'timestamp',
                'original_type' => 'timestamps',
                'nullable' => true,
                'default' => null,
            ];
            $columns['updated_at'] = [
                'type' => 'timestamp',
                'original_type' => 'timestamps',
                'nullable' => true,
                'default' => null,
            ];
        }

        // Handle soft deletes
        if ($this->hasSoftDeletes($content)) {
            $columns['deleted_at'] = [
                'type' => 'timestamp',
                'original_type' => 'softDeletes',
                'nullable' => true,
                'default' => null,
            ];
        }

        // Handle rememberToken
        if (str_contains($content, '$table->rememberToken()')) {
            $columns['remember_token'] = [
                'type' => 'string',
                'original_type' => 'rememberToken',
                'nullable' => true,
                'default' => null,
                'length' => 100,
            ];
        }

        return $columns;
    }

    private function normalizeColumnType(string $type): string
    {
        return match ($type) {
            'bigIncrements', 'increments', 'smallIncrements', 'tinyIncrements', 'id' => 'bigInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger' => 'integer',
            'bigInteger', 'integer', 'smallInteger', 'tinyInteger', 'mediumInteger' => 'integer',
            'decimal', 'double', 'float' => 'decimal',
            'char', 'string', 'text', 'mediumText', 'longText', 'tinyText' => 'string',
            'boolean' => 'boolean',
            'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz' => 'datetime',
            'json', 'jsonb' => 'json',
            'binary', 'blob' => 'binary',
            'enum', 'set' => 'enum',
            'ipAddress', 'macAddress' => 'string',
            'uuid', 'ulid' => 'uuid',
            'year' => 'integer',
            default => $type,
        };
    }

    private function extractDefault(string $modifiers): mixed
    {
        if (preg_match('/->default\s*\(\s*([^)]+)\s*\)/', $modifiers, $match)) {
            $value = trim($match[1]);

            // Handle string defaults
            if (preg_match('/^[\'"](.*)[\'"]\s*$/', $value, $stringMatch)) {
                return $stringMatch[1];
            }

            // Handle boolean defaults
            if ($value === 'true') {
                return true;
            }
            if ($value === 'false') {
                return false;
            }

            // Handle null
            if ($value === 'null') {
                return null;
            }

            // Handle numeric defaults
            if (is_numeric($value)) {
                return str_contains($value, '.') ? (float) $value : (int) $value;
            }

            return $value;
        }

        return null;
    }

    private function extractIndexes(string $content): array
    {
        $indexes = [];

        // Single column index: $table->index('column')
        if (preg_match_all('/\$table->index\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $column) {
                $indexes[] = [
                    'columns' => [$column],
                    'type' => 'index',
                ];
            }
        }

        // Multi-column index: $table->index(['col1', 'col2'])
        if (preg_match_all('/\$table->index\s*\(\s*\[([^\]]+)\]/', $content, $matches)) {
            foreach ($matches[1] as $columnList) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $columnList, $cols);
                $indexes[] = [
                    'columns' => $cols[1],
                    'type' => 'index',
                ];
            }
        }

        return $indexes;
    }

    private function extractForeignKeys(string $content): array
    {
        $foreignKeys = [];

        // Modern syntax: $table->foreignId('user_id')->constrained()
        if (preg_match_all('/\$table->foreignId\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)([^;]*);/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $column = $match[1];
                $modifiers = $match[2];

                // Determine referenced table
                $referencedTable = null;
                if (preg_match('/->constrained\s*\(\s*[\'"]([^\'"]+)[\'"]/', $modifiers, $tableMatch)) {
                    $referencedTable = $tableMatch[1];
                } elseif (str_contains($modifiers, '->constrained()')) {
                    // Convention: user_id -> users table
                    $referencedTable = $this->guessTableFromForeignKey($column);
                }

                $foreignKeys[] = [
                    'column' => $column,
                    'referenced_table' => $referencedTable,
                    'referenced_column' => 'id',
                    'on_delete' => $this->extractOnDelete($modifiers),
                    'on_update' => $this->extractOnUpdate($modifiers),
                    'nullable' => str_contains($modifiers, '->nullable()'),
                ];
            }
        }

        // Legacy syntax: $table->foreign('user_id')->references('id')->on('users')
        if (preg_match_all('/\$table->foreign\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)->references\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)->on\s*\(\s*[\'"]([^\'"]+)[\'"]([^;]*);/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $foreignKeys[] = [
                    'column' => $match[1],
                    'referenced_table' => $match[3],
                    'referenced_column' => $match[2],
                    'on_delete' => $this->extractOnDelete($match[4] ?? ''),
                    'on_update' => $this->extractOnUpdate($match[4] ?? ''),
                ];
            }
        }

        return $foreignKeys;
    }

    private function guessTableFromForeignKey(string $column): ?string
    {
        // user_id -> users, post_id -> posts
        if (str_ends_with($column, '_id')) {
            $base = substr($column, 0, -3);
            return $this->pluralize($base);
        }
        return null;
    }

    private function pluralize(string $word): string
    {
        if (str_ends_with($word, 's')) {
            return $word . 'es';
        }
        if (str_ends_with($word, 'y')) {
            return substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    private function extractOnDelete(string $modifiers): ?string
    {
        if (preg_match('/->onDelete\s*\(\s*[\'"]([^\'"]+)[\'"]/', $modifiers, $match)) {
            return $match[1];
        }
        if (str_contains($modifiers, '->cascadeOnDelete()')) {
            return 'cascade';
        }
        if (str_contains($modifiers, '->nullOnDelete()')) {
            return 'set null';
        }
        if (str_contains($modifiers, '->restrictOnDelete()')) {
            return 'restrict';
        }
        return null;
    }

    private function extractOnUpdate(string $modifiers): ?string
    {
        if (preg_match('/->onUpdate\s*\(\s*[\'"]([^\'"]+)[\'"]/', $modifiers, $match)) {
            return $match[1];
        }
        if (str_contains($modifiers, '->cascadeOnUpdate()')) {
            return 'cascade';
        }
        return null;
    }

    private function extractUniqueConstraints(string $content): array
    {
        $constraints = [];

        // Single column: $table->unique('email')
        if (preg_match_all('/\$table->unique\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $column) {
                $constraints[] = [$column];
            }
        }

        // Multi-column: $table->unique(['email', 'tenant_id'])
        if (preg_match_all('/\$table->unique\s*\(\s*\[([^\]]+)\]/', $content, $matches)) {
            foreach ($matches[1] as $columnList) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $columnList, $cols);
                $constraints[] = $cols[1];
            }
        }

        // Inline unique: $table->string('email')->unique()
        if (preg_match_all('/\$table->\w+\s*\(\s*[\'"]([^\'"]+)[\'"][^;]*->unique\s*\(\s*\)/', $content, $matches)) {
            foreach ($matches[1] as $column) {
                $constraints[] = [$column];
            }
        }

        return array_unique($constraints, SORT_REGULAR);
    }

    private function extractPrimaryKey(string $content): array
    {
        // Default to 'id' if using $table->id()
        if (preg_match('/\$table->id\s*\(\s*\)/', $content)) {
            return ['id'];
        }

        // Custom primary key: $table->primary('custom_id') or $table->primary(['col1', 'col2'])
        if (preg_match('/\$table->primary\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $match)) {
            return [$match[1]];
        }

        if (preg_match('/\$table->primary\s*\(\s*\[([^\]]+)\]/', $content, $match)) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $cols);
            return $cols[1];
        }

        return [];
    }

    private function hasTimestamps(string $content): bool
    {
        return (bool) preg_match('/\$table->timestamps\s*\(\s*\)/', $content);
    }

    private function hasSoftDeletes(string $content): bool
    {
        return (bool) preg_match('/\$table->softDeletes\s*\(\s*\)/', $content);
    }

    private function mergeTableModifications(string $tableName, string $content): void
    {
        // Extract additional columns from Schema::table
        $additionalColumns = $this->extractColumns($content);
        $this->schema[$tableName]['columns'] = array_merge(
            $this->schema[$tableName]['columns'] ?? [],
            $additionalColumns
        );

        // Merge indexes
        $additionalIndexes = $this->extractIndexes($content);
        $this->schema[$tableName]['indexes'] = array_merge(
            $this->schema[$tableName]['indexes'] ?? [],
            $additionalIndexes
        );

        // Merge foreign keys
        $additionalForeignKeys = $this->extractForeignKeys($content);
        $this->schema[$tableName]['foreign_keys'] = array_merge(
            $this->schema[$tableName]['foreign_keys'] ?? [],
            $additionalForeignKeys
        );
    }

    /**
     * Get schema for a specific table.
     */
    public function getTableSchema(string $tableName): ?array
    {
        return $this->schema[$tableName] ?? null;
    }

    /**
     * Get all table names.
     */
    public function getTableNames(): array
    {
        return array_keys($this->schema);
    }
}
