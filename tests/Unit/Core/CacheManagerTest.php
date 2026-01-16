<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Core\Cache\CacheManager;

class CacheManagerTest extends TestCase
{
    private CacheManager $cache;
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/laravel-test-gen-test-cache-' . uniqid();
        $this->cache = new CacheManager($this->cacheDir);
    }

    protected function tearDown(): void
    {
        // Clean up cache directory
        if (is_dir($this->cacheDir)) {
            array_map('unlink', glob($this->cacheDir . '/*'));
            rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    public function test_it_stores_and_retrieves_cache()
    {
        $key = 'test_key';
        $data = ['foo' => 'bar', 'numbers' => [1, 2, 3]];

        $this->cache->set($key, $data, __FILE__);
        $retrieved = $this->cache->get($key, __FILE__);

        $this->assertEquals($data, $retrieved);
    }

    public function test_it_returns_null_for_missing_cache()
    {
        $this->assertNull($this->cache->get('non_existent', __FILE__));
    }

    public function test_it_invalidates_cache_when_source_modified()
    {
        $tempFile = $this->cacheDir . '/temp_source.txt';
        file_put_contents($tempFile, 'original');

        $key = 'test_key';
        $data = ['version' => 1];
        $this->cache->set($key, $data, $tempFile);

        // Verify cache exists
        $this->assertEquals($data, $this->cache->get($key, $tempFile));

        // Modify source file
        sleep(1); // Ensure different mtime
        file_put_contents($tempFile, 'modified');

        // Cache should be invalidated
        $this->assertNull($this->cache->get($key, $tempFile));

        unlink($tempFile);
    }

    public function test_it_clears_all_cache()
    {
        $this->cache->set('key1', ['data' => 1], __FILE__);
        $this->cache->set('key2', ['data' => 2], __FILE__);

        $this->cache->clear();

        $this->assertNull($this->cache->get('key1', __FILE__));
        $this->assertNull($this->cache->get('key2', __FILE__));
    }

    public function test_it_provides_cache_statistics()
    {
        $this->cache->set('key1', ['data' => 1], __FILE__);
        $this->cache->set('key2', ['data' => 2], __FILE__);

        $stats = $this->cache->getStats();

        $this->assertArrayHasKey('files', $stats);
        $this->assertArrayHasKey('total_size', $stats);
        $this->assertEquals(2, $stats['files']);
    }
}
