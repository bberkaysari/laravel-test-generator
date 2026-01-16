<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Core\Cache;

/**
 * Cache manager for parsed results (speeds up large projects)
 */
class CacheManager
{
    private string $cacheDir;
    private bool $enabled;
    
    public function __construct(string $cacheDir, bool $enabled = true)
    {
        $this->cacheDir = $cacheDir;
        $this->enabled = $enabled;
        
        if ($enabled && !is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cached data
     */
    public function get(string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        
        $file = $this->getCacheFile($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $data = unserialize(file_get_contents($file));
        
        // Check if cache is still valid
        if (!$this->isValid($data)) {
            $this->delete($key);
            return null;
        }
        
        return $data['content'];
    }
    
    /**
     * Store data in cache
     */
    public function set(string $key, array $data, ?string $sourceFile = null): void
    {
        if (!$this->enabled) {
            return;
        }
        
        $cacheData = [
            'content' => $data,
            'created_at' => time(),
            'source_file' => $sourceFile,
            'source_mtime' => $sourceFile ? filemtime($sourceFile) : null,
        ];
        
        file_put_contents($this->getCacheFile($key), serialize($cacheData));
    }
    
    /**
     * Delete cached data
     */
    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);
        
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    /**
     * Clear all cache
     */
    public function clear(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        
        foreach ($files as $file) {
            unlink($file);
        }
    }
    
    /**
     * Check if cached data is still valid
     */
    private function isValid(array $data): bool
    {
        // Check if source file still exists and hasn't changed
        if (isset($data['source_file']) && file_exists($data['source_file'])) {
            $currentMtime = filemtime($data['source_file']);
            
            if ($currentMtime !== $data['source_mtime']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get cache file path
     */
    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
    
    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        if (!is_dir($this->cacheDir)) {
            return ['enabled' => false];
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'enabled' => $this->enabled,
            'files' => count($files),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
        ];
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
