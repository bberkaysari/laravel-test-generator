<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner;

use Symfony\Component\Finder\Finder;

class ProjectScanner
{
    private string $projectPath;
    private array $results = [];

    public function __construct(string $projectPath)
    {
        if (!is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project path does not exist: {$projectPath}");
        }

        $this->projectPath = realpath($projectPath);
    }

    /**
     * Scan the entire project.
     */
    public function scan(): array
    {
        $this->validateLaravelProject();
        
        $this->results = [
            'project_path' => $this->projectPath,
            'laravel_version' => $this->detectLaravelVersion(),
            'namespace_mapping' => $this->loadNamespaceMapping(),
            'models' => [],
            'controllers' => [],
            'services' => [],
        ];

        return $this->results;
    }

    /**
     * Check if this is a valid Laravel project.
     */
    private function validateLaravelProject(): void
    {
        $composerPath = $this->projectPath . '/composer.json';

        if (!file_exists($composerPath)) {
            throw new \RuntimeException('Not a valid Composer project: composer.json not found');
        }

        $composer = json_decode(file_get_contents($composerPath), true);

        if (!isset($composer['require']['laravel/framework'])) {
            throw new \RuntimeException('Not a Laravel project: laravel/framework not found in composer.json');
        }
    }

    /**
     * Detect Laravel version from composer.json.
     */
    private function detectLaravelVersion(): string
    {
        $composerPath = $this->projectPath . '/composer.json';
        $composer = json_decode(file_get_contents($composerPath), true);

        $versionConstraint = $composer['require']['laravel/framework'] ?? 'unknown';

        // Parse version (e.g., "^10.0" -> "10")
        if (preg_match('/(\d+)\./', $versionConstraint, $matches)) {
            return $matches[1] . '.x';
        }

        return 'unknown';
    }

    /**
     * Load PSR-4 namespace mapping from composer.json.
     */
    private function loadNamespaceMapping(): array
    {
        $composerPath = $this->projectPath . '/composer.json';
        $composer = json_decode(file_get_contents($composerPath), true);

        return $composer['autoload']['psr-4'] ?? [];
    }

    /**
     * Get scan results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get project path.
     */
    public function getProjectPath(): string
    {
        return $this->projectPath;
    }
}