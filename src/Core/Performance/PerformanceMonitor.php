<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Core\Performance;

/**
 * Performance monitoring for large projects
 */
class PerformanceMonitor
{
    private float $startTime;
    private int $memoryStart;
    private array $metrics = [];
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->memoryStart = memory_get_usage(true);
    }
    
    /**
     * Start timing a section
     */
    public function start(string $section): void
    {
        $this->metrics[$section] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }
    
    /**
     * Stop timing a section
     */
    public function stop(string $section): void
    {
        if (!isset($this->metrics[$section])) {
            return;
        }
        
        $this->metrics[$section]['end'] = microtime(true);
        $this->metrics[$section]['memory_end'] = memory_get_usage(true);
        $this->metrics[$section]['duration'] = 
            $this->metrics[$section]['end'] - $this->metrics[$section]['start'];
        $this->metrics[$section]['memory_used'] = 
            $this->metrics[$section]['memory_end'] - $this->metrics[$section]['memory_start'];
    }
    
    /**
     * Get total execution time
     */
    public function getTotalTime(): float
    {
        return microtime(true) - $this->startTime;
    }
    
    /**
     * Get total memory used
     */
    public function getTotalMemory(): int
    {
        return memory_get_usage(true) - $this->memoryStart;
    }
    
    /**
     * Get peak memory usage
     */
    public function getPeakMemory(): int
    {
        return memory_get_peak_usage(true);
    }
    
    /**
     * Get all metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    
    /**
     * Format bytes to human readable
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Format time to human readable
     */
    public function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2) . ' ms';
        }
        
        if ($seconds < 60) {
            return round($seconds, 2) . ' s';
        }
        
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        
        return $minutes . ' min ' . round($seconds, 2) . ' s';
    }
    
    /**
     * Get summary report
     */
    public function getSummary(): array
    {
        return [
            'total_time' => $this->getTotalTime(),
            'total_time_formatted' => $this->formatTime($this->getTotalTime()),
            'total_memory' => $this->getTotalMemory(),
            'total_memory_formatted' => $this->formatBytes($this->getTotalMemory()),
            'peak_memory' => $this->getPeakMemory(),
            'peak_memory_formatted' => $this->formatBytes($this->getPeakMemory()),
            'sections' => $this->metrics,
        ];
    }
}
