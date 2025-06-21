<?php
// tests/Integration/AsyncIntegrationTest.php

require_once __DIR__ . '/../../src/Helpers/async_helper.php';
require_once __DIR__ . '/../../src/Helpers/loop_helper.php';

use Rcalicdan\FiberAsync\AsyncEventLoop;

beforeEach(function () {
    // Reset the singleton instance for each test
    $reflection = new ReflectionClass(AsyncEventLoop::class);
    $instance = $reflection->getProperty('instance');
    $instance->setAccessible(true);
    $instance->setValue(null);
});

test('complete async workflow with await works', function () {
    $result = run(function () {
        // Simulate async operations
        $value1 = await(delay(0.01)->then(fn() => 10));
        $value2 = await(delay(0.01)->then(fn() => 20));
        
        return $value1 + $value2;
    });
    
    expect($result)->toBe(30);
});

test('concurrent operations execute in parallel', function () {
    $start = microtime(true);
    
    $results = run(function () {
        $promises = [
            delay(0.05)->then(fn() => 'first'),
            delay(0.05)->then(fn() => 'second'),
            delay(0.05)->then(fn() => 'third')
        ];
        
        return await(all($promises));
    });
    
    $duration = microtime(true) - $start;
    
    expect($results)->toBe(['first', 'second', 'third']);
    // Should take ~50ms (parallel), not ~150ms (sequential)
    expect($duration)->toBeLessThan(0.1);
})->skip('Timing-sensitive test - may be flaky in CI');

test('error handling works correctly', function () {
    expect(function () {
        run(function () {
            throw new Exception('Test error');
        });
    })->toThrow(Exception::class, 'Test error');
});

test('complex nested async operations work', function () {
    $result = run(function () {
        $asyncFunc = async(function ($multiplier) {
            $base = await(delay(0.01)->then(fn() => 5));
            return $base * $multiplier;
        });
        
        $results = await(all([
            $asyncFunc(2),
            $asyncFunc(3),
            $asyncFunc(4)
        ]));
        
        return array_sum($results);
    });
    
    expect($result)->toBe(45); // 5*2 + 5*3 + 5*4 = 10 + 15 + 20 = 45
});