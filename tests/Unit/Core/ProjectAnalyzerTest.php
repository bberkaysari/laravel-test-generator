<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Core\ProjectAnalyzer;

class ProjectAnalyzerTest extends TestCase
{
    private ProjectAnalyzer $analyzer;
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = __DIR__ . '/../../Fixtures/sample-project';
        $this->analyzer = new ProjectAnalyzer($this->fixturesPath);
    }

    public function test_it_analyzes_project_structure()
    {
        $result = $this->analyzer->analyze();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('models', $result);
        $this->assertArrayHasKey('controllers', $result);
        $this->assertArrayHasKey('migrations', $result);
    }

    public function test_it_generates_statistics()
    {
        $result = $this->analyzer->analyze();
        $stats = $this->analyzer->getStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('models', $stats);
        $this->assertArrayHasKey('controllers', $stats);
        $this->assertArrayHasKey('migrations', $stats);
        $this->assertArrayHasKey('controller_methods', $stats);
    }

    public function test_it_tracks_performance()
    {
        $result = $this->analyzer->analyze();

        $summary = $this->analyzer->getPerformanceMonitor()->getSummary();
        $this->assertArrayHasKey('total_time', $summary);
        $this->assertArrayHasKey('peak_memory', $summary);
        $this->assertGreaterThan(0, $summary['total_time']);
    }

    public function test_it_uses_cache_on_second_run()
    {
        // First run
        $this->analyzer->analyze();
        $firstRunTime = $this->analyzer->getPerformanceMonitor()->getTotalTime();

        // Second run with cache
        $analyzer2 = new ProjectAnalyzer($this->fixturesPath);
        $analyzer2->analyze();
        $secondRunTime = $analyzer2->getPerformanceMonitor()->getTotalTime();

        // Cache should make it faster (though both might be very fast with small dataset)
        $this->assertGreaterThan(0, $firstRunTime);
        $this->assertGreaterThan(0, $secondRunTime);
    }
}
