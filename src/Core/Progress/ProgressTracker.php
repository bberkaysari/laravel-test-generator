<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Core\Progress;

/**
 * Progress tracking for large operations
 */
class ProgressTracker
{
    private int $total;
    private int $current = 0;
    private string $label;
    private ?callable $callback = null;
    private bool $verbose = true;
    
    public function __construct(int $total, string $label = 'Processing', bool $verbose = true)
    {
        $this->total = $total;
        $this->label = $label;
        $this->verbose = $verbose;
    }
    
    /**
     * Set progress callback
     */
    public function setCallback(callable $callback): void
    {
        $this->callback = $callback;
    }
    
    /**
     * Advance progress
     */
    public function advance(int $step = 1): void
    {
        $this->current += $step;
        
        if ($this->callback) {
            call_user_func($this->callback, $this->current, $this->total);
        }
        
        if ($this->verbose) {
            $this->display();
        }
    }
    
    /**
     * Display progress bar
     */
    private function display(): void
    {
        $percentage = $this->total > 0 ? ($this->current / $this->total) * 100 : 0;
        $barLength = 50;
        $filled = (int) ($percentage / 100 * $barLength);
        $bar = str_repeat('█', $filled) . str_repeat('░', $barLength - $filled);
        
        $output = sprintf(
            "\r%s [%s] %d/%d (%d%%)",
            $this->label,
            $bar,
            $this->current,
            $this->total,
            (int) $percentage
        );
        
        echo $output;
        
        if ($this->current >= $this->total) {
            echo "\n";
        }
    }
    
    /**
     * Get current progress
     */
    public function getCurrent(): int
    {
        return $this->current;
    }
    
    /**
     * Get total
     */
    public function getTotal(): int
    {
        return $this->total;
    }
    
    /**
     * Get percentage
     */
    public function getPercentage(): float
    {
        return $this->total > 0 ? ($this->current / $this->total) * 100 : 0;
    }
    
    /**
     * Reset progress
     */
    public function reset(): void
    {
        $this->current = 0;
    }
}
