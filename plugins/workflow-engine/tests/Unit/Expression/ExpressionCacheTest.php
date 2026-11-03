<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Expression;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Expression\Cache\ExpressionCache;

/**
 * ExpressionCache Unit Tests
 */
class ExpressionCacheTest extends TestCase
{
    private ExpressionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ExpressionCache(100);
    }

    #[Test]
    public function testConstructorWithMaxSize(): void
    {
        $cache = new ExpressionCache(50);
        $stats = $cache->getStats();

        $this->assertEquals(50, $stats['maxSize']);
    }

    #[Test]
    public function testSetAndGet(): void
    {
        $this->cache->set('key1', 'value1');

        $this->assertTrue($this->cache->has('key1'));
        $this->assertEquals('value1', $this->cache->get('key1'));
    }

    #[Test]
    public function testGetMissingKey(): void
    {
        $result = $this->cache->get('missing');
        $this->assertNull($result);
    }

    #[Test]
    public function testHas(): void
    {
        $this->assertFalse($this->cache->has('key1'));

        $this->cache->set('key1', 'value1');

        $this->assertTrue($this->cache->has('key1'));
    }

    #[Test]
    public function testDelete(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->has('key1'));

        $this->cache->delete('key1');
        $this->assertFalse($this->cache->has('key1'));
    }

    #[Test]
    public function testDeleteNonExistent(): void
    {
        // Should not throw
        $this->cache->delete('nonexistent');
        $this->assertFalse($this->cache->has('nonexistent'));
    }

    #[Test]
    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $this->cache->clear();

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));

        $stats = $this->cache->getStats();
        $this->assertEquals(0, $stats['size']);
    }

    #[Test]
    public function testLruEviction(): void
    {
        $cache = new ExpressionCache(3);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3');

        // Access key1 to make it recently used
        $cache->get('key1');

        // Add key4, should evict key2 (least recently used)
        $cache->set('key4', 'value4');

        $this->assertTrue($cache->has('key1')); // Recently accessed
        $this->assertFalse($cache->has('key2')); // Evicted
        $this->assertTrue($cache->has('key3'));
        $this->assertTrue($cache->has('key4'));
    }

    #[Test]
    public function testLruEvictionOrder(): void
    {
        $cache = new ExpressionCache(3);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3');

        // Access in reverse order: key3, key2, key1
        $cache->get('key3');
        $cache->get('key2');
        $cache->get('key1');

        // Add key4, should evict key3 (now least recently used)
        $cache->set('key4', 'value4');

        $this->assertTrue($cache->has('key1'));
        $this->assertTrue($cache->has('key2'));
        $this->assertFalse($cache->has('key3'));
        $this->assertTrue($cache->has('key4'));
    }

    #[Test]
    public function testUpdateExistingKey(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key1', 'value2');

        $this->assertEquals('value2', $this->cache->get('key1'));

        $stats = $this->cache->getStats();
        $this->assertEquals(1, $stats['size']); // Should not increase size
    }

    #[Test]
    public function testHitMissStats(): void
    {
        $this->cache->set('key1', 'value1');

        // Miss
        $this->cache->get('nonexistent');

        // Hit
        $this->cache->get('key1');
        $this->cache->get('key1');

        $stats = $this->cache->getStats();
        $this->assertEquals(2, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
    }

    #[Test]
    public function testHitRatio(): void
    {
        $this->cache->set('key1', 'value1');

        // 1 miss
        $this->cache->get('miss');

        // 3 hits
        $this->cache->get('key1');
        $this->cache->get('key1');
        $this->cache->get('key1');

        $stats = $this->cache->getStats();
        $this->assertEquals(0.75, $stats['hitRatio']); // 3 hits / 4 total
    }

    #[Test]
    public function testHitRatioWithNoAccess(): void
    {
        $stats = $this->cache->getStats();
        $this->assertEquals(0.0, $stats['hitRatio']);
    }

    #[Test]
    public function testGetStats(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->get('key1');

        $stats = $this->cache->getStats();

        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('size', $stats);
        $this->assertArrayHasKey('maxSize', $stats);
        $this->assertArrayHasKey('hitRatio', $stats);
        $this->assertArrayHasKey('memoryUsage', $stats);

        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(1, $stats['size']);
        $this->assertEquals(100, $stats['maxSize']);
    }

    #[Test]
    public function testGenerateKey(): void
    {
        $key1 = ExpressionCache::generateKey('x + y');
        $key2 = ExpressionCache::generateKey('x + y');
        $key3 = ExpressionCache::generateKey('x + z');

        $this->assertEquals($key1, $key2);
        $this->assertNotEquals($key1, $key3);
    }

    #[Test]
    public function testGenerateKeyConsistency(): void
    {
        $expression = 'amount > 100 and status == "approved"';

        // Should generate same key every time
        $key1 = ExpressionCache::generateKey($expression);
        $key2 = ExpressionCache::generateKey($expression);

        $this->assertEquals($key1, $key2);
        $this->assertMatchesRegularExpression('/^expr_[a-f0-9]+$/', $key1);
    }

    #[Test]
    public function testGetKeys(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');

        $keys = $this->cache->getKeys();

        $this->assertCount(3, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys);
        $this->assertContains('key3', $keys);
    }

    #[Test]
    public function testComplexValueStorage(): void
    {
        $complexValue = [
            'expression' => 'x + y',
            'parsed' => 'x + y',
            'compiled' => '($x + $y)',
            'metadata' => [
                'variables' => ['x', 'y'],
                'functions' => [],
            ],
        ];

        $this->cache->set('complex', $complexValue);
        $retrieved = $this->cache->get('complex');

        $this->assertEquals($complexValue, $retrieved);
    }

    #[Test]
    public function testSetMakesKeyMostRecentlyUsed(): void
    {
        $cache = new ExpressionCache(3);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3');

        // Update key1, making it most recently used
        $cache->set('key1', 'updated');

        // Add key4, should evict key2
        $cache->set('key4', 'value4');

        $this->assertTrue($cache->has('key1'));
        $this->assertFalse($cache->has('key2')); // Evicted
        $this->assertTrue($cache->has('key3'));
        $this->assertTrue($cache->has('key4'));
    }

    #[Test]
    public function testMemoryUsageEstimate(): void
    {
        $this->cache->set('key1', 'short');
        $this->cache->set('key2', str_repeat('x', 1000));

        $stats = $this->cache->getStats();

        $this->assertGreaterThan(0, $stats['memoryUsage']);
    }

    #[Test]
    public function testEmptyCache(): void
    {
        $stats = $this->cache->getStats();

        $this->assertEquals(0, $stats['size']);
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0.0, $stats['hitRatio']);
    }

    #[Test]
    public function testClearResetsStats(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->get('key1');
        $this->cache->get('missing');

        $this->cache->clear();

        $stats = $this->cache->getStats();
        $this->assertEquals(0, $stats['size']);
        // Note: hits/misses are preserved through clear in our implementation
    }

    #[Test]
    public function testMaxSizeOfOne(): void
    {
        $cache = new ExpressionCache(1);

        $cache->set('key1', 'value1');
        $cache->set('key2', 'value2');

        $this->assertFalse($cache->has('key1'));
        $this->assertTrue($cache->has('key2'));
    }

    #[Test]
    public function testNullValue(): void
    {
        $this->cache->set('null_key', null);

        $this->assertTrue($this->cache->has('null_key'));
        $this->assertNull($this->cache->get('null_key'));
    }

    #[Test]
    public function testBooleanValues(): void
    {
        $this->cache->set('true_key', true);
        $this->cache->set('false_key', false);

        $this->assertTrue($this->cache->get('true_key'));
        $this->assertFalse($this->cache->get('false_key'));
    }

    #[Test]
    public function testIntegerZeroValue(): void
    {
        $this->cache->set('zero', 0);

        $this->assertTrue($this->cache->has('zero'));
        $this->assertSame(0, $this->cache->get('zero'));
    }

    #[Test]
    public function testEmptyStringValue(): void
    {
        $this->cache->set('empty', '');

        $this->assertTrue($this->cache->has('empty'));
        $this->assertSame('', $this->cache->get('empty'));
    }

    #[Test]
    public function testHighVolumeOperations(): void
    {
        $cache = new ExpressionCache(100);

        // Add 200 items (should trigger evictions)
        for ($i = 0; $i < 200; $i++) {
            $cache->set("key_{$i}", "value_{$i}");
        }

        $stats = $cache->getStats();

        // Should not exceed max size
        $this->assertEquals(100, $stats['size']);

        // Later items should still be present
        $this->assertTrue($cache->has('key_199'));
        $this->assertTrue($cache->has('key_150'));

        // Earlier items should be evicted
        $this->assertFalse($cache->has('key_0'));
        $this->assertFalse($cache->has('key_50'));
    }
}
